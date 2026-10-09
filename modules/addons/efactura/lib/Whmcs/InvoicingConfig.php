<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

namespace WHMCS\Module\Addon\Efactura\Whmcs;

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

/**
 * Reads the WHMCS invoice numbering configuration.
 *
 * The addon relies on the native proforma mode: at payment WHMCS replaces the
 * invoice number with the next "Sequential Paid Invoice Number" (the fiscal
 * series, SequentialInvoiceNumberFormat) and sets the payment date. Unpaid
 * invoices are proformas: they have no number, or a number from the separate
 * "Custom Invoice Numbering" series (TaxCustomInvoiceNumberFormat) when that
 * is enabled. The two series have separate counters.
 */
final class InvoicingConfig
{
    private const TAGS = ['{NUMBER}', '{YEAR}', '{MONTH}', '{DAY}'];

    public function sequentialPaidNumbering(): bool
    {
        return self::isOn(Setting::getValue('SequentialInvoiceNumbering'));
    }

    public function proformaInvoicing(): bool
    {
        return self::isOn(Setting::getValue('EnableProformaInvoicing'));
    }

    public function invoiceDateOnPayment(): bool
    {
        return self::isOn(Setting::getValue('TaxSetInvoiceDateOnPayment'));
    }

    /**
     * Whether proformas get a number from the custom series when created.
     */
    public function proformaNumbering(): bool
    {
        return self::isOn(Setting::getValue('TaxCustomInvoiceNumbering'));
    }

    public function fiscalFormat(): string
    {
        return trim((string) Setting::getValue('SequentialInvoiceNumberFormat'));
    }

    /**
     * The counter of the fiscal series ("Next Paid Invoice Number").
     */
    public function fiscalCounter(): string
    {
        return trim((string) Setting::getValue('SequentialInvoiceNumberValue'));
    }

    public function proformaFormat(): string
    {
        return trim((string) Setting::getValue('TaxCustomInvoiceNumberFormat'));
    }

    public function proformaCounter(): string
    {
        return trim((string) Setting::getValue('TaxNextCustomInvoiceNumber'));
    }

    /**
     * The highest number of the fiscal series already present on an invoice
     * or a billing note, or null when the series is unused. Only the fiscal
     * series counts; proforma numbers are ignored.
     *
     * @return array{number: string, counter: string, value: int}|null
     *         the full number, its counter part as written and its value
     */
    public function highestIssuedNumber(): ?array
    {
        $format = $this->fiscalFormat();
        $pattern = self::numberPattern($format);
        if ($pattern === null) {
            return null;
        }
        $like = addcslashes(self::literalPrefix($format), '%_\\') . '%';

        $numbers = Capsule::table('tblinvoices')->where('invoicenum', 'like', $like)->pluck('invoicenum')
            ->merge(Capsule::table('tblbillingnotes')->where('custom_number', 'like', $like)->pluck('custom_number'));

        $highest = null;
        foreach ($numbers as $number) {
            if (preg_match($pattern, (string) $number, $match) === 1 && ($highest === null || (int) $match['n'] > $highest['value'])) {
                $highest = ['number' => (string) $number, 'counter' => $match['n'], 'value' => (int) $match['n']];
            }
        }

        return $highest;
    }

    /**
     * Fills a series format with a counter value, the way WHMCS does: the
     * counter is inserted as stored (with its zero padding) and the date tags
     * use the given date.
     */
    public static function format(string $format, string $counter, ?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable();

        return strtr($format, [
            '{NUMBER}' => $counter,
            '{YEAR}' => $date->format('Y'),
            '{MONTH}' => $date->format('m'),
            '{DAY}' => $date->format('d'),
        ]);
    }

    /**
     * Regex matching numbers of the given format, with the counter in the
     * named group "n". Null when the format has no {NUMBER} tag.
     */
    public static function numberPattern(string $format): ?string
    {
        return str_contains($format, '{NUMBER}') ? self::pattern($format) : null;
    }

    /**
     * Whether two series formats can produce the same number, or start with
     * the same literal text (which makes them impossible to tell apart). Used
     * to make sure proforma numbers never look like fiscal numbers.
     */
    public static function formatsOverlap(string $fiscal, string $proforma): bool
    {
        if (self::literalPrefix($fiscal) === self::literalPrefix($proforma)) {
            return true;
        }

        $fiscalPattern = self::pattern($fiscal);
        $proformaPattern = self::pattern($proforma);
        $dates = [new \DateTimeImmutable(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2099-12-31')];
        foreach (['1', '7', '42', '0094', '0100', '2026', '12345', '0000001'] as $counter) {
            foreach ($dates as $date) {
                if (preg_match($fiscalPattern, self::format($proforma, $counter, $date)) === 1
                    || preg_match($proformaPattern, self::format($fiscal, $counter, $date)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The literal text before the first tag of a format.
     */
    public static function literalPrefix(string $format): string
    {
        $position = strlen($format);
        foreach (self::TAGS as $tag) {
            $found = strpos($format, $tag);
            if ($found !== false && $found < $position) {
                $position = $found;
            }
        }

        return substr($format, 0, $position);
    }

    private static function pattern(string $format): string
    {
        $regex = strtr(preg_quote($format, '/'), [
            '\{NUMBER\}' => '(?<n>\d+)',
            '\{YEAR\}' => '\d{4}',
            '\{MONTH\}' => '\d{2}',
            '\{DAY\}' => '\d{2}',
        ]);

        return '/^' . $regex . '$/';
    }

    private static function isOn(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true'], true);
    }
}
