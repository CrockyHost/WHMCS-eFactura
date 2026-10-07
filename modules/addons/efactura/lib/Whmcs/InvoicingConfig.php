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
 * Reads the WHMCS invoice numbering configuration. The addon relies on the
 * native proforma mode: unpaid invoices have no number and WHMCS assigns the
 * next "Sequential Paid Invoice Number" (the fiscal series) at payment.
 */
final class InvoicingConfig
{
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

    public function customInvoiceNumbering(): bool
    {
        return self::isOn(Setting::getValue('TaxCustomInvoiceNumbering'));
    }

    public function numberFormat(): string
    {
        return trim((string) Setting::getValue('SequentialInvoiceNumberFormat'));
    }

    /**
     * The raw counter WHMCS uses for the next paid invoice ("Next Paid
     * Invoice Number").
     */
    public function nextNumberValue(): string
    {
        return trim((string) Setting::getValue('SequentialInvoiceNumberValue'));
    }

    /**
     * The highest number of the fiscal series already present on an invoice
     * or a billing note, or null when the series is unused.
     *
     * @return array{number: string, counter: string, value: int}|null
     *         the full number, its counter part as written and its value
     */
    public function highestIssuedNumber(): ?array
    {
        $pattern = self::numberPattern($this->numberFormat());
        if ($pattern === null) {
            return null;
        }
        $prefix = addcslashes(self::literalPrefix($this->numberFormat()), '%_\\');

        $numbers = Capsule::table('tblinvoices')
            ->where('invoicenum', 'like', $prefix . '%')
            ->pluck('invoicenum')
            ->merge(Capsule::table('tblbillingnotes')->where('custom_number', 'like', $prefix . '%')->pluck('custom_number'));

        $highest = null;
        foreach ($numbers as $number) {
            if (preg_match($pattern, (string) $number, $match) === 1 && ($highest === null || (int) $match['n'] > $highest['value'])) {
                $highest = ['number' => (string) $number, 'counter' => $match['n'], 'value' => (int) $match['n']];
            }
        }

        return $highest;
    }

    /**
     * Formats a counter value with the series format, for display. The
     * {YEAR}, {MONTH} and {DAY} tags use today's date.
     */
    public function format(string $counter): string
    {
        return strtr($this->numberFormat(), [
            '{NUMBER}' => $counter,
            '{YEAR}' => date('Y'),
            '{MONTH}' => date('m'),
            '{DAY}' => date('d'),
        ]);
    }

    /**
     * Regex matching numbers of the given format, with the counter in the
     * named group "n". Null when the format has no {NUMBER} tag.
     */
    public static function numberPattern(string $format): ?string
    {
        if (!str_contains($format, '{NUMBER}')) {
            return null;
        }
        $regex = preg_quote($format, '/');
        $regex = strtr($regex, [
            '\{NUMBER\}' => '(?<n>\d+)',
            '\{YEAR\}' => '\d{4}',
            '\{MONTH\}' => '\d{2}',
            '\{DAY\}' => '\d{2}',
        ]);

        return '/^' . $regex . '$/';
    }

    private static function literalPrefix(string $format): string
    {
        $position = strpos($format, '{');

        return $position === false ? $format : substr($format, 0, $position);
    }

    private static function isOn(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true'], true);
    }
}
