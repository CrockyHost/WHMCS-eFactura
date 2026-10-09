<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

namespace WHMCS\Module\Addon\Efactura\ClientData\Anaf;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Http\Transport;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Support\ModuleLog;

/**
 * Company data from the public ANAF service PlatitorTvaRest v9 (no
 * authentication). Answers are cached in mod_efactura_company_cache, and
 * the whole installation sends at most one request per second, as the
 * service rules require ("maxim 1 request pe secunda").
 */
final class CompanyLookup
{
    public const URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';
    public const CACHE_TABLE = 'mod_efactura_company_cache';
    public const LOG_TABLE = 'mod_efactura_lookup_log';

    /** Cache lifetime: a day for a company, an hour for an unknown CUI. */
    private const FOUND_TTL = 86400;
    private const NOT_FOUND_TTL = 3600;

    /** Minimum time between two requests to ANAF, in microseconds. */
    private const SPACING_US = 1100000;

    /** How long a request waits for its turn before giving up, in seconds. */
    private const TURN_WAIT = 8;

    private const LOCK = 'anaf_company_lookup';

    public function __construct(private readonly Transport $transport)
    {
    }

    public function find(string $cui): LookupResult
    {
        $cui = Cui::normalize($cui);
        $cached = $this->cached($cui);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->request($cui);
        if ($response === null) {
            return new LookupResult(LookupResult::UNAVAILABLE);
        }

        $answer = $response->json();
        if (in_array($response->status, [200, 404], true) && $answer !== null) {
            $company = CompanyParser::company($answer, $cui);
            if ($company !== null) {
                $this->store($cui, LookupResult::FOUND, $company, self::FOUND_TTL);

                return new LookupResult(LookupResult::FOUND, $company);
            }
            if (CompanyParser::notFound($answer, $cui)) {
                $this->store($cui, LookupResult::NOT_FOUND, null, self::NOT_FOUND_TTL);

                return new LookupResult(LookupResult::NOT_FOUND);
            }
        }

        return new LookupResult(LookupResult::UNAVAILABLE);
    }

    private function cached(string $cui): ?LookupResult
    {
        $row = Capsule::table(self::CACHE_TABLE)
            ->where('cui', $cui)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
        if ($row === null) {
            return null;
        }
        if ($row->status === LookupResult::NOT_FOUND) {
            return new LookupResult(LookupResult::NOT_FOUND, null, true);
        }
        $data = json_decode((string) $row->data, true);

        return is_array($data) ? new LookupResult(LookupResult::FOUND, CompanyRecord::fromArray($data), true) : null;
    }

    private function store(string $cui, string $status, ?CompanyRecord $company, int $ttl): void
    {
        $now = time();
        Capsule::table(self::CACHE_TABLE)->updateOrInsert(['cui' => $cui], [
            'status' => $status,
            'data' => $company === null ? null : json_encode($company->toArray(), JSON_UNESCAPED_UNICODE),
            'fetched_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + $ttl),
        ]);
    }

    /**
     * Waits for this installation's turn (one request per second), then asks
     * ANAF. Null when the turn does not come in time.
     */
    private function request(string $cui): ?Response
    {
        if (!Lock::acquire(self::LOCK, self::TURN_WAIT)) {
            return null;
        }
        try {
            $last = Capsule::table(self::LOG_TABLE)->where('bucket', 'anaf')->max('created_at');
            if ($last !== null) {
                $lastAt = (float) strtotime(substr((string) $last, 0, 19)) + self::fraction((string) $last);
                $elapsed = (int) ((microtime(true) - $lastAt) * 1000000);
                if ($elapsed < self::SPACING_US) {
                    usleep(self::SPACING_US - max(0, $elapsed));
                }
            }
            Capsule::table(self::LOG_TABLE)->insert(['bucket' => 'anaf', 'created_at' => self::microDate()]);
        } finally {
            Lock::release(self::LOCK);
        }

        $request = new Request(
            'POST',
            self::URL,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode([['cui' => (int) $cui, 'data' => Clock::today()->format('Y-m-d')]]),
            8,
            4
        );
        try {
            $response = $this->transport->send($request);
        } catch (Throwable) {
            return null;
        }
        ModuleLog::call('company_lookup', $request, $response, []);

        return $response->failed() || $response->status >= 500 ? null : $response;
    }

    /**
     * The current time with milliseconds, for DATETIME(3).
     */
    public static function microDate(): string
    {
        $now = microtime(true);

        return date('Y-m-d H:i:s', (int) $now) . sprintf('.%03d', (int) (($now - floor($now)) * 1000));
    }

    private static function fraction(string $datetime): float
    {
        return preg_match('/\.(\d+)$/', $datetime, $match) === 1 ? (float) ('0.' . $match[1]) : 0.0;
    }
}
