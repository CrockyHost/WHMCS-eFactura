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
 * Romanian fiscal identification code (CUI / CIF): 2 to 10 digits, the last
 * one being a check digit computed with the key 753217532.
 */
final class Cui
{
    private const KEY = '753217532';

    /**
     * Strips spaces and an optional "RO" prefix. Returns the input unchanged
     * (trimmed, upper case) when it does not look like a CUI.
     */
    public static function normalize(string $value): string
    {
        $value = strtoupper(preg_replace('/[\s.\-]+/', '', $value) ?? '');
        if (str_starts_with($value, 'RO')) {
            $value = substr($value, 2);
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        $cui = self::normalize($value);
        if (preg_match('/^[1-9]\d{1,9}$/', $cui) !== 1) {
            return false;
        }

        $body = str_pad(substr($cui, 0, -1), 9, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (int) self::KEY[$i];
        }
        $check = ($sum * 10) % 11;
        if ($check === 10) {
            $check = 0;
        }

        return $check === (int) substr($cui, -1);
    }
}
