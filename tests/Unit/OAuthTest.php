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

use WHMCS\Module\Addon\Efactura\Anaf\OAuth\ConnectionMonitor;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\TokenSet;
use WHMCS\Module\Addon\Efactura\Http\Response;

$now = new DateTimeImmutable('2026-10-09 12:00:00');

return [
    'JWT claims are read without verifying the signature' => static function (): void {
        $token = FakeTransport::jwt(['exp' => 1893456000, 'serial' => 'ABC123']);
        Assert::same('ABC123', Jwt::claims($token)['serial']);
        Assert::same(1893456000, Jwt::expiresAt($token)->getTimestamp());
        Assert::same(null, Jwt::claims('opaque-token'));
        Assert::same(null, Jwt::claims('a.%%%.c'));
        Assert::same(null, Jwt::expiresAt(FakeTransport::jwt(['serial' => 'x'])));
    },
    'token set uses the JWT expiry, roles and certificate serial' => static function () use ($now): void {
        $json = FakeTransport::tokenResponse($now->modify('+90 days'), $now->modify('+365 days'));
        $tokens = TokenSet::fromResponse($json, $now);
        Assert::same($now->modify('+90 days')->getTimestamp(), $tokens->accessExpiresAt->getTimestamp());
        Assert::same($now->modify('+365 days')->getTimestamp(), $tokens->refreshExpiresAt->getTimestamp());
        Assert::same('CERT-1', $tokens->certificateSerial);
        Assert::same('EFACTURA, HELLO', $tokens->roles);
    },
    'token set falls back to expires_in and strips whitespace' => static function () use ($now): void {
        $tokens = TokenSet::fromResponse(['access_token' => " opaque\r\n", 'refresh_token' => "ref\n", 'expires_in' => 3600], $now);
        Assert::same('opaque', $tokens->accessToken);
        Assert::same('ref', $tokens->refreshToken);
        Assert::same($now->modify('+3600 seconds')->getTimestamp(), $tokens->accessExpiresAt->getTimestamp());
        Assert::same($now->modify('+365 days')->getTimestamp(), $tokens->refreshExpiresAt->getTimestamp());
    },
    'token set rejects incomplete responses' => static function () use ($now): void {
        Assert::same(null, TokenSet::fromResponse(['access_token' => 'a'], $now));
        Assert::same(null, TokenSet::fromResponse(['access_token' => 'a', 'refresh_token' => 'r', 'token_type' => 'MAC'], $now));
    },
    'authorize URL carries the exact redirect URI, JWT type and state' => static function (): void {
        $url = OAuthClient::authorizeUrl('client id', 'https://x.test/modules/addons/efactura/oauth_callback.php', 'st');
        Assert::true(str_starts_with($url, OAuthClient::AUTHORIZE_URL . '?response_type=code&client_id=client%20id'));
        Assert::true(str_contains($url, '&redirect_uri=https%3A%2F%2Fx.test%2Fmodules%2Faddons%2Fefactura%2Foauth_callback.php'));
        Assert::true(str_contains($url, '&token_content_type=jwt&state=st'));
    },
    'code exchange uses Basic authentication and form fields' => static function () use ($now): void {
        $fake = (new FakeTransport())->queueJson(200, FakeTransport::tokenResponse($now->modify('+90 days'), $now->modify('+365 days')));
        (new OAuthClient($fake))->exchangeCode('id', 'secret', 'the-code', 'https://x.test/cb', $now);
        $request = $fake->requests[0];
        Assert::same(OAuthClient::TOKEN_URL, $request->url);
        Assert::same('Basic ' . base64_encode('id:secret'), $request->headers['Authorization']);
        parse_str($request->body, $fields);
        Assert::same(['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => 'https://x.test/cb', 'token_content_type' => 'jwt'], $fields);
    },
    'refresh sends the refresh token' => static function () use ($now): void {
        $fake = (new FakeTransport())->queueJson(200, FakeTransport::tokenResponse($now->modify('+90 days'), $now->modify('+365 days')));
        (new OAuthClient($fake))->refresh('id', 'secret', 'old-refresh', $now);
        parse_str($fake->requests[0]->body, $fields);
        Assert::same('refresh_token', $fields['grant_type']);
        Assert::same('old-refresh', $fields['refresh_token']);
    },
    'expired refresh token and invalid_grant require a new authorization' => static function () use ($now): void {
        foreach ([
            [400, ['error' => 'invalid_grant', 'error_description' => 'Invalid grant']],
            [400, ['error' => 'invalid_request', 'error_description' => 'Refresh Token status is expired']],
        ] as [$status, $json]) {
            $fake = (new FakeTransport())->queueJson($status, $json);
            try {
                (new OAuthClient($fake))->refresh('id', 'secret', 'r', $now);
                throw new RuntimeException('no exception');
            } catch (OAuthException $e) {
                Assert::true($e->reauthorize, $e->getMessage());
            }
        }
    },
    'invalid client and gateway pages are errors without re-authorization' => static function () use ($now): void {
        $fake = (new FakeTransport())
            ->queueJson(400, ['error' => 'invalid_client'])
            ->queue(new Response(302, ['location' => '/my.policy'], '<html><body>Redirect</body></html>'))
            ->queue(new Response(0, [], '', 28, 'Operation timed out', true));
        $client = new OAuthClient($fake);
        foreach (['invalid_client', 'HTTP 302', 'Could not reach ANAF'] as $expected) {
            try {
                $client->refresh('id', 'secret', 'r', $now);
                throw new RuntimeException('no exception');
            } catch (OAuthException $e) {
                Assert::false($e->reauthorize, $e->getMessage());
                Assert::true(str_contains($e->getMessage(), $expected), $e->getMessage());
            }
        }
    },
    'alert thresholds: 30, 14, 7, 3, 1 days and expiry' => static function (): void {
        $cases = [400 => null, 31 => null, 30 => 30, 25 => 30, 14 => 14, 8 => 14, 7 => 7, 4 => 7, 3 => 3, 2 => 3, 1 => 1, 0 => 0, -5 => 0];
        foreach ($cases as $days => $expected) {
            Assert::same($expected, ConnectionMonitor::threshold($days), "{$days} days");
        }
    },
];
