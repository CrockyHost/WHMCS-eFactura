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
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Transport;

/**
 * National Bank of Romania reference rates (curs.bnr.ro), cached in
 * mod_efactura_exchange_rates.
 *
 * The VAT of a foreign-currency invoice is converted at "the last exchange
 * rate communicated by the NBR" (art. 290 Fiscal Code): the rate published
 * on a banking day applies to the operations of the next day, so a document
 * dated D uses the latest rate published before D.
 */
final class BnrRates
{
    public const SOURCE = 'BNR';
    public const TABLE = 'mod_efactura_exchange_rates';

    private const BASE_URL = 'https://curs.bnr.ro/';
    private const RECENT_DAYS = 9;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The rate to use for VAT on a document dated $date.
     */
    public function forDocumentDate(string $currency, DateTimeImmutable $date): ExchangeRate
    {
        $currency = strtoupper($currency);
        $day = $date->setTime(0, 0);
        $rate = $this->cached($currency, $day);
        if ($rate === null) {
            $this->fetchAround($day);
            $rate = $this->cached($currency, $day);
        }
        if ($rate === null) {
            throw new RuntimeException("No BNR exchange rate for {$currency} before " . $day->format('Y-m-d') . '.');
        }

        return $rate;
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
            $this->import('nbrfxrates10days.xml');

            return;
        }
        $this->import('files/xml/years/nbrfxrates' . $day->format('Y') . '.xml');
        // The first days of January need the end of the previous year.
        if ((int) $day->format('n') === 1 && (int) $day->format('j') <= 15) {
            $this->import('files/xml/years/nbrfxrates' . ((int) $day->format('Y') - 1) . '.xml');
        }
    }

    /**
     * Downloads a BNR file and stores all its rates.
     *
     * @return int the number of rates stored
     */
    public function import(string $path): int
    {
        $response = $this->transport->send(new Request('GET', self::BASE_URL . $path, ['Accept' => 'text/xml'], '', 60, 15));
        if ($response->failed() || $response->status !== 200) {
            throw new RuntimeException('BNR exchange rates unavailable (' . ($response->failed() ? $response->error : 'HTTP ' . $response->status) . ').');
        }

        return $this->store($response->body);
    }

    /**
     * @return int the number of rates stored
     */
    public function store(string $xml): int
    {
        $document = new DOMDocument();
        if (@$document->loadXML($xml, LIBXML_NONET) === false) {
            throw new RuntimeException('BNR returned an invalid XML file.');
        }
        $xpath = new DOMXPath($document);
        $now = date('Y-m-d H:i:s');
        $rows = [];
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

        return count($rows);
    }
}
