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

namespace WHMCS\Module\Addon\Efactura\Numbering;

use DateTimeInterface;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * The counter of the fiscal series: the WHMCS "Next Paid Invoice Number"
 * (SequentialInvoiceNumberValue). WHMCS reads it from the database each
 * time it numbers an invoice (verified in 9.0.5), so it is always read and
 * written directly, never through a cache.
 */
final class SeriesCounter
{
    private const SETTING = 'SequentialInvoiceNumberValue';

    public function __construct(private readonly InvoicingConfig $config)
    {
    }

    public function value(): string
    {
        return trim((string) Capsule::table('tblconfiguration')->where('setting', self::SETTING)->value('value'));
    }

    public function set(string $value): void
    {
        Capsule::table('tblconfiguration')->where('setting', self::SETTING)->update([
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The next counter value, with the same zero padding ("0099" -> "0100").
     */
    public static function increment(string $counter): string
    {
        return str_pad((string) ((int) $counter + 1), strlen($counter), '0', STR_PAD_LEFT);
    }

    public function format(string $counter, DateTimeInterface $date): string
    {
        return InvoicingConfig::format($this->config->fiscalFormat(), $counter, $date);
    }

    /**
     * The counter part of a number of the fiscal series ("CRK-0094" ->
     * "0094"), or null when the number is not from the fiscal series.
     */
    public function counterOf(string $number): ?string
    {
        $pattern = InvoicingConfig::numberPattern($this->config->fiscalFormat());

        return $pattern !== null && preg_match($pattern, $number, $match) === 1 ? $match['n'] : null;
    }
}
