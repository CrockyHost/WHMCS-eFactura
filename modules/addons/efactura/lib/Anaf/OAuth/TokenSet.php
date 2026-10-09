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

use DateTimeImmutable;

/**
 * The access and refresh tokens returned by the ANAF token endpoint.
 * ANAF documents 90 days for the access token and 365 days for the refresh
 * token; the JWT "exp" claims take precedence when present.
 */
final class TokenSet
{
    public const ACCESS_DAYS = 90;
    public const REFRESH_DAYS = 365;

    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly DateTimeImmutable $accessExpiresAt,
        public readonly DateTimeImmutable $refreshExpiresAt,
        public readonly string $certificateSerial,
        public readonly string $roles,
    ) {
    }

    /**
     * @param array<string, mixed> $json decoded token endpoint response
     */
    public static function fromResponse(array $json, DateTimeImmutable $now): ?self
    {
        // Tokens are long JWTs; stray whitespace breaks them.
        $access = preg_replace('/\s+/', '', (string) ($json['access_token'] ?? '')) ?? '';
        $refresh = preg_replace('/\s+/', '', (string) ($json['refresh_token'] ?? '')) ?? '';
        if ($access === '' || $refresh === '' || strcasecmp((string) ($json['token_type'] ?? 'Bearer'), 'Bearer') !== 0) {
            return null;
        }

        $expiresIn = (int) ($json['expires_in'] ?? 0);
        $accessExpires = Jwt::expiresAt($access)
            ?? $now->modify('+' . ($expiresIn > 0 ? $expiresIn . ' seconds' : self::ACCESS_DAYS . ' days'));
        $refreshExpires = Jwt::expiresAt($refresh) ?? $now->modify('+' . self::REFRESH_DAYS . ' days');

        $claims = Jwt::claims($access) ?? [];

        return new self(
            $access,
            $refresh,
            $accessExpires,
            $refreshExpires,
            (string) ($claims['serial'] ?? ''),
            self::roles($claims),
        );
    }

    /**
     * The services granted to the token. ANAF sends them in the "roles"
     * claim separated by "@" (e.g. "HELLO@EFACTURA@ETRANSPORT@SRV_EFACTURA",
     * seen in 2026); "role" is accepted as well.
     *
     * @param array<string, mixed> $claims
     */
    public static function roles(array $claims): string
    {
        $roles = $claims['roles'] ?? $claims['role'] ?? [];
        if (!is_array($roles)) {
            $roles = preg_split('/[@,\s]+/', (string) $roles, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return implode(', ', array_map('strval', $roles));
    }
}
