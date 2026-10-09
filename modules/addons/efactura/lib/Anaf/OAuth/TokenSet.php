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
        $roles = $claims['role'] ?? '';

        return new self(
            $access,
            $refresh,
            $accessExpires,
            $refreshExpires,
            (string) ($claims['serial'] ?? ''),
            is_array($roles) ? implode(', ', array_map('strval', $roles)) : (string) $roles,
        );
    }
}
