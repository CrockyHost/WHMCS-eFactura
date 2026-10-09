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

/**
 * Encryption of the addon's own secrets with the WHMCS encrypt()/decrypt()
 * functions (key: cc_encryption_hash in configuration.php). Only values
 * written by the addon are ever decrypted.
 */
final class Crypto
{
    public static function encrypt(string $value): string
    {
        return $value === '' ? '' : (string) encrypt($value);
    }

    /**
     * Returns '' when the value cannot be decrypted, for example after the
     * database was moved to an installation with another encryption key.
     */
    public static function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return trim((string) decrypt($value));
    }
}
