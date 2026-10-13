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

namespace WHMCS\Module\Addon\Efactura\Exchange;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Transport;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * National Bank of Romania reference rates (curs.bnr.ro), cached in
 * mod_efactura_exchange_rates.
 *
 * The VAT of a foreign-currency invoice is converted at "the last exchange
 * rate communicated by the NBR" (art. 290 Fiscal Code): the rate published
 * on a banking day applies to the operations of the next day, so a document
 * dated D uses the latest rate published before D.
 *
 * The latest cached rate is that rate only when no later one is missing, so
 * the addon records which days its downloads cover completely. The cron
 * downloads the rates every day once BNR has published them; the PDF of an
 * invoice then reads them from the cache, without waiting for BNR.
 */
final class BnrRates
{
    public const SOURCE = 'BNR';
    public const TABLE = 'mod_efactura_exchange_rates';

    private const BASE_URL = 'https://curs.bnr.ro/';
    private const RECENT_FILE = 'nbrfxrates10days.xml';
    private const RECENT_DAYS = 9;

    /** BNR publishes the rates of a banking day around 13:00. */
    private const PUBLISHED_AFTER = '13:15';
    private const RETRY_MINUTES = 30;

    /** RuntimeState: the date ranges whose rates are all cached, [[from, through], ...]. */
    private const COVERAGE = 'bnr_coverage';
    private const COVERAGE_RANGES = 12;
    /** RuntimeState: the last daily download, {at, error}. */
    private const DOWNLOAD = 'bnr_download';

    private readonly RuntimeState $state;

    public function __construct(private readonly Transport $transport)
    {
        $this->state = new RuntimeState();
    }

    /**
     * The rate to use for VAT on a document dated $date, downloaded from BNR
     * when the cache does not have it.
     */
    public function forDocumentDate(string $currency, DateTimeImmutable $date): ExchangeRate
    {
        $currency = strtoupper($currency);
        $rate = $this->downloaded($currency, $date);
        if ($rate === null) {
            $this->fetchAround(self::day($date));
            $rate = $this->downloaded($currency, $date);
        }
        if ($rate === null) {
            throw new RuntimeException("No BNR exchange rate for {$currency} before " . self::day($date)->format('Y-m-d') . '.');
        }

        return $rate;
    }

    /**
     * The rate for a document dated $date from the rates already downloaded,
     * without contacting BNR. Null when it is missing, or when a later rate
     * published before $date could be missing.
     */
    public function downloaded(string $currency, DateTimeImmutable $date): ?ExchangeRate
    {
        $day = self::day($date);
        $rate = $this->cached(strtoupper($currency), $day);
        if ($rate === null || !$this->covered($rate->date->format('Y-m-d'), $day->modify('-1 day')->format('Y-m-d'))) {
            return null;
        }

        return $rate;
    }

    /**
     * Downloads the recent rates when the cache lacks the ones published so
     * far: once a day after BNR publishes them, at once when the cache is
     * older (first run, a cron that stopped), retried every half hour.
     *
     * @throws RuntimeException on the first failure of a series (the next
     *                          ones are only recorded, for the dashboard)
     */
    public function refreshIfDue(): void
    {
        $now = Clock::now();
        $through = $this->coveredThrough();
        $today = $now->format('Y-m-d');
        if ($through === $today) {
            return;
        }
        if ($through === $now->modify('-1 day')->format('Y-m-d') && $now->format('H:i') < self::PUBLISHED_AFTER) {
            return;
        }
        $last = $this->state->get(self::DOWNLOAD);
        if (is_array($last) && isset($last['at']) && Clock::parse((string) $last['at']) > $now->modify('-' . self::RETRY_MINUTES . ' minutes')) {
            return;
        }

        try {
            $this->import(self::RECENT_FILE);
            $this->state->set(self::DOWNLOAD, ['at' => $now->format('Y-m-d H:i:s'), 'error' => null]);
        } catch (Throwable $e) {
            $this->state->set(self::DOWNLOAD, ['at' => $now->format('Y-m-d H:i:s'), 'error' => $e->getMessage()]);
            if (!is_array($last) || ($last['error'] ?? null) === null) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * For the dashboard: the last day whose rates are all cached, and the
     * last daily download.
     *
     * @return array{through: ?string, at: ?string, error: ?string}
     */
    public function status(): array
    {
        $last = $this->state->get(self::DOWNLOAD);

        return [
            'through' => $this->coveredThrough(),
            'at' => is_array($last) ? ($last['at'] ?? null) : null,
            'error' => is_array($last) ? ($last['error'] ?? null) : null,
        ];
    }

    private function cached(string $currency, DateTimeImmutable $day): ?ExchangeRate
    {
        // A gap of more than 10 days means missing data, not a holiday.
        $row = Capsule::table(self::TABLE)
            ->where('source', self::SOURCE)
            ->where('currency', $currency)
            ->where('rate_date', '<', $day->format('Y-m-d'))
            ->where('rate_date', '>=', $day->modify('-10 days')->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();
        if ($row === null) {
            return null;
        }

        return new ExchangeRate($currency, ExchangeRate::perUnit((string) $row->value, (int) $row->multiplier), Clock::parse((string) $row->rate_date), self::SOURCE);
    }

    private function fetchAround(DateTimeImmutable $day): void
    {
        $recent = Clock::today()->modify('-' . self::RECENT_DAYS . ' days');
        if ($day > $recent) {
            $this->import(self::RECENT_FILE);

            return;
        }
        $this->import('files/xml/years/nbrfxrates' . $day->format('Y') . '.xml');
        // The first days of January need the end of the previous year.
        if ((int) $day->format('n') === 1 && (int) $day->format('j') <= 15) {
            $this->import('files/xml/years/nbrfxrates' . ((int) $day->format('Y') - 1) . '.xml');
        }
    }

    /**
     * Downloads a BNR file, stores all its rates and records the days it
     * covers completely.
     *
     * @return int the number of rates stored
     */
    public function import(string $path): int
    {
        $response = $this->transport->send(new Request('GET', self::BASE_URL . $path, ['Accept' => 'text/xml'], '', 60, 15));
        if ($response->failed() || $response->status !== 200) {
            throw new RuntimeException('BNR exchange rates unavailable (' . ($response->failed() ? $response->error : 'HTTP ' . $response->status) . ').');
        }
        $dates = $this->store($response->body);
        if ($dates === []) {
            throw new RuntimeException('BNR returned no exchange rates.');
        }

        // A yearly file covers its year from 1 January; the recent file, its
        // first day. Both cover every day up to yesterday (BNR published
        // yesterday's rates before today), and today once today's rates are
        // in or when BNR publishes none today.
        $today = Clock::today();
        $year = preg_match('~nbrfxrates(\d{4})\.xml$~', $path, $match) === 1 ? (int) $match[1] : null;
        $from = $year !== null ? sprintf('%04d-01-01', $year) : $dates[0];
        if ($year !== null && $year < (int) $today->format('Y')) {
            $through = sprintf('%04d-12-31', $year);
        } elseif (end($dates) === $today->format('Y-m-d') || !WorkingDays::isWorkingDay($today)) {
            $through = $today->format('Y-m-d');
        } else {
            $through = $today->modify('-1 day')->format('Y-m-d');
        }
        $this->cover($from, $through);

        return count($dates);
    }

    /**
     * Stores the rates of a BNR file; rates already cached are kept.
     *
     * @return list<string> the dates of the file, sorted
     */
    public function store(string $xml): array
    {
        $document = new DOMDocument();
        if (@$document->loadXML($xml, LIBXML_NONET) === false) {
            throw new RuntimeException('BNR returned an invalid XML file.');
        }
        $xpath = new DOMXPath($document);
        $now = date('Y-m-d H:i:s');
        $rows = [];
        $dates = [];
        foreach ($xpath->query("//*[local-name()='Cube'][@date]") as $cube) {
            $date = $cube->getAttribute('date');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            foreach ($xpath->query("*[local-name()='Rate'][@currency]", $cube) as $rate) {
                $value = trim($rate->textContent);
                if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
                    continue;
                }
                $dates[$date] = true;
                $rows[] = [
                    'source' => self::SOURCE,
                    'currency' => strtoupper($rate->getAttribute('currency')),
                    'rate_date' => $date,
                    'value' => $value,
                    'multiplier' => max(1, (int) ($rate->getAttribute('multiplier') ?: 1)),
                    'fetched_at' => $now,
                ];
            }
        }
        // Published rates never change: rows already cached are kept.
        foreach (array_chunk($rows, 500) as $chunk) {
            Capsule::table(self::TABLE)->insertOrIgnore($chunk);
        }
        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /**
     * Whether every rate BNR published from $from to $through is cached.
     */
    private function covered(string $from, string $through): bool
    {
        foreach ($this->ranges() as [$start, $end]) {
            if ($start <= $from && $end >= $through) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last day of the most recent covered range.
     */
    private function coveredThrough(): ?string
    {
        $ranges = $this->ranges();

        return $ranges === [] ? null : max(array_column($ranges, 1));
    }

    /**
     * Adds a covered range, merged with the ranges it touches.
     */
    private function cover(string $from, string $through): void
    {
        $ranges = [];
        foreach ($this->ranges() as [$start, $end]) {
            $touches = $start <= self::nextDay($through) && $end >= self::previousDay($from);
            if ($touches) {
                $from = min($from, $start);
                $through = max($through, $end);
            } else {
                $ranges[] = [$start, $end];
            }
        }
        $ranges[] = [$from, $through];
        usort($ranges, static fn (array $a, array $b): int => strcmp($b[1], $a[1]));
        $this->state->set(self::COVERAGE, array_slice($ranges, 0, self::COVERAGE_RANGES));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function ranges(): array
    {
        $ranges = $this->state->get(self::COVERAGE, []);

        return is_array($ranges) ? array_values(array_filter($ranges, static fn (mixed $range): bool => is_array($range) && count($range) === 2)) : [];
    }

    private static function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return Clock::parse($date->format('Y-m-d'));
    }

    private static function nextDay(string $date): string
    {
        return Clock::parse($date)->modify('+1 day')->format('Y-m-d');
    }

    private static function previousDay(string $date): string
    {
        return Clock::parse($date)->modify('-1 day')->format('Y-m-d');
    }
}
