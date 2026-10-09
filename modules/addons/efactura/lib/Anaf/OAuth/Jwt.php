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

namespace WHMCS\Module\Addon\Efactura\Anaf\OAuth;

/**
 * Reads the claims of an ANAF JWT (exp, serial, role). The signature is not
 * verified: ANAF does not publish the key, and the token is only ever sent
 * back to ANAF, which verifies it.
 */
final class Jwt
{
    /**
     * @return array<string, mixed>|null the payload, or null when the value is not a JWT
     */
    public static function claims(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        if ($json === false) {
            return null;
        }
        $claims = json_decode($json, true);

        return is_array($claims) ? $claims : null;
    }

    public static function expiresAt(string $token): ?\DateTimeImmutable
    {
        $exp = self::claims($token)['exp'] ?? null;

        return is_int($exp) || (is_string($exp) && ctype_digit($exp))
            ? (new \DateTimeImmutable('@' . $exp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            : null;
    }
}
