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

namespace WHMCS\Module\Addon\Efactura\ClientData;

/**
 * Trade register number (ONRC). The register letter is J (companies), F
 * (PFA, II, IF) or C (cooperatives). Two formats are valid:
 * - since 26.07.2024: letter + year (4) + order number (6) + county (2) +
 *   check digit, e.g. J2024020698007; companies registered after that date
 *   have county 00, older ones keep the county of their last seat;
 * - before: letter + county + "/" + order number + "/" + year, e.g.
 *   J40/9159/1999, sometimes with the full date instead of the year.
 * The check digit is (letter code + sum of the 12 digits) mod 10, where the
 * letter code is its ASCII value mod 10 (J=4, F=0, C=7).
 */
final class RegCom
{
    public const FORMAT_NEW = 'new';
    public const FORMAT_OLD = 'old';

    /** First year of the trade register in its current form. */
    private const FIRST_YEAR = 1990;

    /** Year the new format started; older numbers stay valid. */
    private const NEW_FORMAT_YEAR = 2024;

    /**
     * Upper case, without spaces; the county of the old format on two digits.
     */
    public static function normalize(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if (preg_match('#^([JFC])(\d{1,2})/(\d+)/(.+)$#', $value, $match) === 1) {
            $value = $match[1] . str_pad($match[2], 2, '0', STR_PAD_LEFT) . '/' . $match[3] . '/' . $match[4];
        }

        return $value;
    }

    /**
     * FORMAT_NEW, FORMAT_OLD or null when the number is not valid.
     */
    public static function format(string $value, ?int $currentYear = null): ?string
    {
        $value = self::normalize($value);
        $currentYear ??= (int) date('Y');

        if (preg_match('/^([JFC])(\d{4})(\d{6})(\d{2})(\d)$/', $value, $match) === 1) {
            $year = (int) $match[2];
            $county = (int) $match[4];
            if ($year < self::FIRST_YEAR || $year > $currentYear) {
                return null;
            }
            $countyValid = match (true) {
                $year < self::NEW_FORMAT_YEAR => self::isOldCounty($county),
                $year === self::NEW_FORMAT_YEAR => $county === 0 || self::isOldCounty($county),
                default => $county === 0,
            };
            if (!$countyValid) {
                return null;
            }
            $sum = ord($match[1]) % 10;
            foreach (str_split(substr($value, 1, 12)) as $digit) {
                $sum += (int) $digit;
            }

            return $sum % 10 === (int) $match[5] ? self::FORMAT_NEW : null;
        }

        if (preg_match('#^([JFC])(\d{2})/(\d{1,6})/(?:\d{1,2}\.\d{1,2}\.)?(\d{4})$#', $value, $match) === 1) {
            $year = (int) $match[4];
            $valid = self::isOldCounty((int) $match[2])
                && (int) $match[3] > 0
                && $year >= self::FIRST_YEAR
                && $year <= min($currentYear, self::NEW_FORMAT_YEAR);

            return $valid ? self::FORMAT_OLD : null;
        }

        return null;
    }

    public static function isValid(string $value, ?int $currentYear = null): bool
    {
        return self::format($value, $currentYear) !== null;
    }

    /**
     * County codes of the old format: 1 to 40 (40 is Bucharest), 51 and 52.
     */
    private static function isOldCounty(int $county): bool
    {
        return ($county >= 1 && $county <= 40) || $county === 51 || $county === 52;
    }
}
