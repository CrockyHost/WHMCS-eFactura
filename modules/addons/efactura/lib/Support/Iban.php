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

namespace WHMCS\Module\Addon\Efactura\Support;

/**
 * IBAN normalization and ISO 13616 check (mod 97). Romanian IBANs have 24
 * characters.
 */
final class Iban
{
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    public static function isValid(string $value): bool
    {
        $iban = self::normalize($value);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            return false;
        }
        if (str_starts_with($iban, 'RO') && strlen($iban) !== 24) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
