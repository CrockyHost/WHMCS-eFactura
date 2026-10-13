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

namespace WHMCS\Module\Addon\Efactura\Whmcs;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Exchange\VatInRon;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * The BNR exchange rate and the VAT in RON of a fiscal invoice in another
 * currency (art. 319 alin. (23) Fiscal Code), added to the notes of its PDF
 * and of its page in the client area: the mention of its XML, in the
 * language of the client. Proformas and invoices in RON get nothing.
 *
 * The rate is the one saved when the XML was built, otherwise the one the
 * XML will use, from the rates the cron downloads every day; the PDF never
 * waits for BNR. When the rate is not downloaded yet, the PDF goes out
 * without the mention, and the invoice is logged and listed on the dashboard.
 */
final class ExchangeMention
{
    /** RuntimeState: the invoices whose PDF missed the mention, by invoice id. */
    private const MISSING = 'exchange_mention_missing';
    private const MISSING_KEPT = 20;
    /** How long the dashboard lists a PDF without the mention. */
    public const MISSING_DAYS = 7;

    /**
     * The mention for the invoice in $language ("romanian" or "english"), or
     * null. $pdf: a missing rate is recorded (the page of the invoice is not
     * the document the client keeps).
     */
    public static function forInvoice(int $invoiceId, string $language, bool $pdf): ?string
    {
        $document = FiscalInvoice::document($invoiceId);
        if ($document === null) {
            return null;
        }
        $currency = FiscalInvoice::currency((int) $document->client_id);
        if ($currency === '' || $currency === 'RON') {
            return null;
        }
        $rate = VatInRon::rateFor($document, null, $currency, Addon::bnrRates(), false);
        if ($rate === null) {
            if ($pdf) {
                self::recordMissing($invoiceId, $document, $currency);
            }

            return null;
        }
        $tax = Money::cents((string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('tax'));

        return VatInRon::of($currency, $tax, $rate)->text($language);
    }

    /**
     * Whether WHMCS bills in a currency other than RON (the rates are needed).
     */
    public static function needed(): bool
    {
        return Capsule::table('tblcurrencies')->where('code', '!=', 'RON')->exists();
    }

    /**
     * The language of the page or PDF being rendered: WHMCS switches to the
     * language of the client for the PDF.
     */
    public static function language(): string
    {
        try {
            $name = strtolower((string) \Lang::getName());
        } catch (Throwable) {
            $name = '';
        }

        return $name === 'romanian' ? 'romanian' : 'english';
    }

    /**
     * The notes of the PDF with the mention on a line of its own. The final
     * line break keeps it left-aligned: the themes print the notes justified
     * (TCPDF MultiCell), which can stretch a last line across the page.
     */
    public static function pdfNotes(string $notes, string $mention): string
    {
        return (trim($notes) === '' ? '' : rtrim($notes) . "\n") . $mention . "\n";
    }

    /**
     * The notes of the invoice page (HTML) with the mention on a line of its own.
     */
    public static function pageNotes(string $notes, string $mention): string
    {
        $mention = htmlspecialchars($mention, ENT_QUOTES, 'UTF-8');

        return trim($notes) === '' ? $mention : rtrim($notes) . "<br />\n" . $mention;
    }

    /**
     * The invoices whose PDF missed the mention in the last days, newest
     * first.
     *
     * @return list<array{invoice: int, number: string, currency: string, date: string, at: string}>
     */
    public static function missing(): array
    {
        $since = Clock::now()->modify('-' . self::MISSING_DAYS . ' days')->format('Y-m-d H:i:s');
        $entries = array_values(array_filter(
            (array) (new RuntimeState())->get(self::MISSING, []),
            static fn (mixed $entry): bool => is_array($entry) && (string) ($entry['at'] ?? '') >= $since,
        ));
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $entries;
    }

    private static function recordMissing(int $invoiceId, object $document, string $currency): void
    {
        $state = new RuntimeState();
        $entries = (array) $state->get(self::MISSING, []);
        $key = (string) $invoiceId;
        $since = Clock::now()->modify('-' . self::MISSING_DAYS . ' days')->format('Y-m-d H:i:s');
        if (is_array($entries[$key] ?? null) && (string) ($entries[$key]['at'] ?? '') >= $since) {
            return;
        }
        $date = Clock::parse((string) $document->issue_date)->format('Y-m-d');
        $entries[$key] = ['invoice' => $invoiceId, 'number' => (string) $document->number, 'currency' => $currency, 'date' => $date, 'at' => Clock::now()->format('Y-m-d H:i:s')];
        uasort($entries, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
        $state->set(self::MISSING, array_slice($entries, 0, self::MISSING_KEPT, true));
        logActivity(sprintf(
            '%s: the PDF of invoice %s has no BNR exchange rate mention: the %s rate for %s is not downloaded yet.',
            Addon::NAME,
            $document->number,
            $currency,
            Clock::parse($date)->format('d.m.Y'),
        ));
    }
}
