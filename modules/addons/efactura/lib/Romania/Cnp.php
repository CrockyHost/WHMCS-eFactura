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

namespace WHMCS\Module\Addon\Efactura\Romania;

/**
 * Romanian personal numeric code (CNP): 13 digits, the last one a check
 * digit computed with the key 279146358279. "0000000000000" is what the law
 * allows for individuals who do not give their CNP.
 */
final class Cnp
{
    public const UNKNOWN = '0000000000000';

    private const KEY = '279146358279';

    public static function normalize(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }

    public static function isValid(string $value): bool
    {
        $cnp = self::normalize($value);
        if (preg_match('/^[1-9]\d{12}$/', $cnp) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnp[$i] * (int) self::KEY[$i];
        }
        $check = $sum % 11;
        if ($check === 10) {
            $check = 1;
        }

        return $check === (int) $cnp[12];
    }
}
