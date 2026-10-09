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

namespace WHMCS\Module\Addon\Efactura\Support;

use InvalidArgumentException;

/**
 * Money as integer minor units (bani, cents), so that sums are exact.
 * Rounding is half away from zero, as WHMCS and EN 16931 do.
 */
final class Money
{
    /**
     * "121.00", "-5.5", 3 -> minor units. More than 2 decimals are rounded.
     */
    public static function cents(string|int|float $value): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $text, $match) !== 1 || ($match[2] === '' && ($match[3] ?? '') === '')) {
            throw new InvalidArgumentException("Not an amount: {$text}");
        }
        $decimals = str_pad($match[3] ?? '', 3, '0');
        $cents = (int) ($match[2] === '' ? '0' : $match[2]) * 100 + (int) substr($decimals, 0, 2);
        if ((int) $decimals[2] >= 5) {
            $cents++;
        }

        return $match[1] === '-' ? -$cents : $cents;
    }

    /**
     * Minor units -> "121.00" (dot, no thousands separator).
     */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * $cents x $factor, rounded to minor units. $factor is a decimal string
     * with up to 6 decimals (a VAT rate divided by 100, or an exchange rate).
     */
    public static function multiply(int $cents, string $factor): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', trim($factor), $match) !== 1) {
            throw new InvalidArgumentException("Not a factor: {$factor}");
        }
        $scaled = (int) ($match[1] . str_pad($match[2] ?? '', 6, '0'));
        $product = abs($cents) * $scaled;
        $result = intdiv($product, 1000000) + (($product % 1000000) >= 500000 ? 1 : 0);

        return $cents < 0 ? -$result : $result;
    }

    /**
     * VAT of a base at a percentage ("21", "21.000").
     */
    public static function percent(int $cents, string $rate): int
    {
        $rate = self::trimDecimal($rate);
        [$whole, $fraction] = array_pad(explode('.', $rate), 2, '');
        $fraction = str_pad($fraction, 4, '0');
        // rate / 100 with up to 6 decimals.
        $factor = intdiv((int) $whole, 100) . '.' . str_pad((string) ((int) $whole % 100), 2, '0', STR_PAD_LEFT) . substr($fraction, 0, 4);

        return self::multiply($cents, $factor);
    }

    /**
     * "21.000" -> "21", "9.50" -> "9.5".
     */
    public static function trimDecimal(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
