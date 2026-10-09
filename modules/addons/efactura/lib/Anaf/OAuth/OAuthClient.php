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
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Transport;
use WHMCS\Module\Addon\Efactura\Support\ModuleLog;

/**
 * OAuth 2.0 authorization code flow of the ANAF identity provider
 * (logincert.anaf.ro). The same identity provider and tokens serve the test
 * and production e-Factura APIs. Only the authorization step, in a browser,
 * needs the qualified certificate; the token calls use HTTP Basic
 * authentication with the client ID and secret.
 */
final class OAuthClient
{
    public const AUTHORIZE_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize';
    public const TOKEN_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';

    public function __construct(private readonly Transport $transport)
    {
    }

    public static function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'token_content_type' => 'jwt',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the authorization code; ANAF accepts it for 60 seconds only.
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, DateTimeImmutable $now): TokenSet
    {
        return $this->tokenRequest('oauth_exchange_code', $clientId, $clientSecret, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'token_content_type' => 'jwt',
        ], [$code], $now);
    }

    /**
     * Returns a new pair of tokens. The refresh token rotates: the old one
     * stops working, so the new pair must be saved at once.
     */
    public function refresh(string $clientId, string $clientSecret, string $refreshToken, DateTimeImmutable $now): TokenSet
    {
        return $this->tokenRequest('oauth_refresh', $clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'token_content_type' => 'jwt',
        ], [$refreshToken], $now);
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $secrets values to mask in the module log
     */
    private function tokenRequest(string $action, string $clientId, string $clientSecret, array $fields, array $secrets, DateTimeImmutable $now): TokenSet
    {
        $basic = base64_encode($clientId . ':' . $clientSecret);
        $request = new Request('POST', self::TOKEN_URL, [
            'Authorization' => 'Basic ' . $basic,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query($fields, '', '&', PHP_QUERY_RFC1738), 30, 15);

        $response = $this->transport->send($request);
        $json = $response->json() ?? [];
        $tokens = $response->status === 200 ? TokenSet::fromResponse($json, $now) : null;

        ModuleLog::call($action, $request, $response, array_merge(
            [$clientSecret, $basic],
            $secrets,
            [(string) ($json['access_token'] ?? ''), (string) ($json['refresh_token'] ?? '')]
        ));

        if ($tokens !== null) {
            return $tokens;
        }
        if ($response->failed()) {
            throw new OAuthException('Could not reach ANAF: ' . $response->error, OAuthException::TRANSPORT);
        }

        $error = (string) ($json['error'] ?? '');
        $description = trim((string) ($json['error_description'] ?? $json['message'] ?? ''));
        $text = $error . ' ' . $description . ' ' . ($json === [] ? $response->body : '');
        // invalid_grant and "Refresh Token status is expired" cannot be fixed by retrying.
        $reauthorize = $error === 'invalid_grant' || stripos($text, 'expired') !== false;

        $message = $error !== '' || $description !== ''
            ? trim($error . ($description !== '' ? ': ' . $description : ''))
            : sprintf('HTTP %d: %s', $response->status, mb_substr(trim(strip_tags($response->body)), 0, 200));

        throw new OAuthException($message, OAuthException::REJECTED, $reauthorize);
    }
}
