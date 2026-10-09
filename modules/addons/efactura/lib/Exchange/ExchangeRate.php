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

/**
 * RON for one unit of a currency, as published on a date.
 */
final class ExchangeRate
{
    public function __construct(
        public readonly string $currency,
        public readonly string $rate,
        public readonly DateTimeImmutable $date,
        public readonly string $source,
    ) {
    }

    /**
     * "5.0812" from a published value and multiplier ("1.2345" for 100 HUF
     * gives "0.012345").
     */
    public static function perUnit(string $value, int $multiplier): string
    {
        if ($multiplier <= 1) {
            return $value;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $digits = ltrim($whole . $fraction, '0');
        $decimals = strlen($fraction) + (int) round(log10($multiplier));
        $digits = str_pad($digits === '' ? '0' : $digits, $decimals + 1, '0', STR_PAD_LEFT);

        return rtrim(rtrim(substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals), '0'), '.');
    }
}
