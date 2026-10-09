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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Anaf\ConnectionCheck;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\ConnectionMonitor;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * A connection over a fake transport and a controllable clock, starting
 * from an empty mod_efactura_oauth (inside the test transaction).
 *
 * @return array{0: Connection, 1: FakeTransport, 2: Closure(string): void}
 */
$setup = static function (): array {
    Capsule::table(Connection::TABLE)->delete();
    $now = new DateTimeImmutable('2026-10-09 12:00:00');
    $fake = new FakeTransport();
    $connection = new Connection(new OAuthClient($fake), static function () use (&$now): DateTimeImmutable {
        return $now;
    });
    $travel = static function (string $modify) use (&$now): void {
        $now = $now->modify($modify);
    };

    return [$connection, $fake, $travel];
};

/**
 * Runs a full authorization; returns the state value used.
 */
$authorize = static function (Connection $connection, FakeTransport $fake, string $suffix = '1'): string {
    $connection->saveCredentials('client-1', 'secret-1');
    $url = $connection->startAuthorization(1);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $base = new DateTimeImmutable('2026-10-09 12:00:00');
    $fake->queueJson(200, FakeTransport::tokenResponse($base->modify('+90 days'), $base->modify('+365 days'), $suffix));
    $connection->completeAuthorization($query['state'], 'code-' . $suffix);

    return $query['state'];
};

$expectOAuth = static function (string $reason, callable $callback): OAuthException {
    try {
        $callback();
    } catch (OAuthException $e) {
        Assert::same($reason, $e->reason, $e->getMessage());

        return $e;
    }
    throw new RuntimeException('Expected an OAuthException (' . $reason . ')');
};

Lang::boot('english');

return [
    'credentials are stored with the secret encrypted and never exposed' => static function () use ($setup): void {
        [$connection] = $setup();
        $connection->saveCredentials(' client-1 ', 'secret-1');
        $row = Capsule::table(Connection::TABLE)->first();
        Assert::same('client-1', $row->client_id);
        Assert::false(str_contains((string) $row->client_secret, 'secret-1'));
        Assert::same('secret-1', Crypto::decrypt($row->client_secret));
        $status = $connection->status();
        Assert::true($status['configured']);
        Assert::false(str_contains((string) json_encode($status), 'secret-1'));

        $connection->saveCredentials('client-1', null);
        Assert::same('secret-1', Crypto::decrypt(Capsule::table(Connection::TABLE)->value('client_secret')));
    },
    'authorization needs the credentials' => static function () use ($setup, $expectOAuth): void {
        [$connection] = $setup();
        $expectOAuth(OAuthException::NOT_CONFIGURED, static fn () => $connection->startAuthorization(1));
    },
    'the authorization URL carries a state that is stored only as a hash' => static function () use ($setup): void {
        [$connection] = $setup();
        $connection->saveCredentials('client-1', 'secret-1');
        $url = $connection->startAuthorization(1);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        Assert::same(64, strlen($query['state']));
        Assert::same(Connection::callbackUrl(), $query['redirect_uri']);
        $row = Capsule::table(Connection::TABLE)->first();
        Assert::same(hash('sha256', $query['state']), $row->state_hash);
        Assert::same(Connection::callbackUrl(), $row->redirect_uri);
    },
    'a wrong state is rejected before calling ANAF' => static function () use ($setup, $expectOAuth): void {
        [$connection, $fake] = $setup();
        $connection->saveCredentials('client-1', 'secret-1');
        $connection->startAuthorization(1);
        $expectOAuth(OAuthException::INVALID_STATE, static fn () => $connection->completeAuthorization(str_repeat('0', 64), 'code'));
        $expectOAuth(OAuthException::INVALID_STATE, static fn () => $connection->completeAuthorization('', 'code'));
        Assert::same([], $fake->requests);
    },
    'an expired state is rejected' => static function () use ($setup, $expectOAuth): void {
        [$connection, , $travel] = $setup();
        $connection->saveCredentials('client-1', 'secret-1');
        parse_str((string) parse_url($connection->startAuthorization(1), PHP_URL_QUERY), $query);
        $travel('+' . (Connection::STATE_TTL_MINUTES + 1) . ' minutes');
        $expectOAuth(OAuthException::INVALID_STATE, static fn () => $connection->completeAuthorization($query['state'], 'code'));
    },
    'a successful callback stores encrypted tokens and uses the state once' => static function () use ($setup, $authorize, $expectOAuth): void {
        [$connection, $fake] = $setup();
        $state = $authorize($connection, $fake);

        parse_str($fake->requests[0]->body, $fields);
        Assert::same('code-1', $fields['code']);
        Assert::same(Connection::callbackUrl(), $fields['redirect_uri']);

        $row = Capsule::table(Connection::TABLE)->first();
        Assert::same(null, $row->state_hash);
        Assert::false(str_contains((string) $row->access_token, '.'), 'access token must be encrypted');
        $status = $connection->status();
        Assert::true($status['connected']);
        Assert::same('CERT-1', $status['certificate_serial']);
        Assert::same(1, $status['authorized_by']);
        Assert::same('2026-10-09', $status['authorized_at']->format('Y-m-d'));
        Assert::same('2027-10-09', $status['refresh_expires_at']->format('Y-m-d'));
        Assert::same(365, $status['days_left']);

        $expectOAuth(OAuthException::INVALID_STATE, static fn () => $connection->completeAuthorization($state, 'code-again'));
    },
    'a fresh access token is returned without refreshing' => static function () use ($setup, $authorize): void {
        [$connection, $fake] = $setup();
        $authorize($connection, $fake);
        $token = $connection->accessToken();
        Assert::same('a1', \WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt::claims($token)['n']);
        Assert::same(1, count($fake->requests));
    },
    'an access token close to expiry is refreshed, without extending the yearly authorization' => static function () use ($setup, $authorize): void {
        [$connection, $fake, $travel] = $setup();
        $authorize($connection, $fake);
        $travel('+85 days');
        $base = new DateTimeImmutable('2026-10-09 12:00:00');
        // ANAF sends a new pair; the refresh token claims a later expiry.
        $fake->queueJson(200, FakeTransport::tokenResponse($base->modify('+175 days'), $base->modify('+450 days'), '2'));
        $token = $connection->accessToken();
        Assert::same('a2', \WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt::claims($token)['n']);
        parse_str($fake->requests[1]->body, $fields);
        Assert::same('r1', \WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt::claims($fields['refresh_token'])['n']);

        $status = $connection->status();
        Assert::same('2027-10-09', $status['refresh_expires_at']->format('Y-m-d'));
        Assert::true($status['refreshed_at'] !== null);
        // The rotated refresh token is the one used next time.
        $fake->queueJson(200, FakeTransport::tokenResponse($base->modify('+180 days'), $base->modify('+450 days'), '3'));
        $connection->refresh(true);
        parse_str($fake->requests[2]->body, $fields);
        Assert::same('r2', \WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt::claims($fields['refresh_token'])['n']);
    },
    'an expired refresh token flags the re-authorization' => static function () use ($setup, $authorize, $expectOAuth): void {
        [$connection, $fake, $travel] = $setup();
        $authorize($connection, $fake);
        $travel('+89 days');
        $fake->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'Refresh Token status is expired']);
        $e = $expectOAuth(OAuthException::REJECTED, static fn () => $connection->accessToken());
        Assert::true($e->reauthorize);
        Assert::true($connection->status()['needs_reauthorization']);
        // No further calls to ANAF once flagged.
        $expectOAuth(OAuthException::REJECTED, static fn () => $connection->accessToken());
        Assert::same(2, count($fake->requests));
    },
    'a transient refresh failure keeps using a still valid token' => static function () use ($setup, $authorize): void {
        [$connection, $fake, $travel] = $setup();
        $authorize($connection, $fake);
        $travel('+85 days');
        $fake->queue(new Response(0, [], '', 7, 'Failed to connect', false));
        Assert::same('a1', \WHMCS\Module\Addon\Efactura\Anaf\OAuth\Jwt::claims($connection->accessToken())['n']);
        $status = $connection->status();
        Assert::false($status['needs_reauthorization']);
        Assert::true(str_contains($status['last_error'], 'Failed to connect'));
    },
    'disconnecting deletes the tokens and keeps the application' => static function () use ($setup, $authorize, $expectOAuth): void {
        [$connection, $fake] = $setup();
        $authorize($connection, $fake);
        $connection->disconnect(1);
        $status = $connection->status();
        Assert::false($status['connected']);
        Assert::true($status['configured']);
        $expectOAuth(OAuthException::NOT_CONNECTED, static fn () => $connection->accessToken());
    },
    'a new client ID drops the tokens of the previous application' => static function () use ($setup, $authorize): void {
        [$connection, $fake] = $setup();
        $authorize($connection, $fake);
        $connection->saveCredentials('client-2', null);
        Assert::false($connection->status()['connected']);
    },
    'an ANAF error on the callback is recorded only with a valid state' => static function () use ($setup, $expectOAuth): void {
        [$connection] = $setup();
        $connection->saveCredentials('client-1', 'secret-1');
        parse_str((string) parse_url($connection->startAuthorization(1), PHP_URL_QUERY), $query);
        $expectOAuth(OAuthException::INVALID_STATE, static fn () => $connection->failAuthorization('bad', 'access_denied'));
        Assert::same('', $connection->status()['last_error']);
        $connection->failAuthorization($query['state'], 'access_denied');
        Assert::same('ANAF: access_denied', $connection->status()['last_error']);
    },
    'the monitor alerts once per threshold' => static function () use ($setup, $authorize): void {
        [$connection, $fake, $travel] = $setup();
        $authorize($connection, $fake);
        $alerts = [];
        $monitor = new ConnectionMonitor($connection, static function (int $threshold, int $days) use (&$alerts): void {
            $alerts[] = [$threshold, $days];
        });
        $base = new DateTimeImmutable('2026-10-09 12:00:00');

        $monitor->run();
        Assert::same([], $alerts);

        // 340 days later: the access token needs a refresh, 25 days are left.
        $travel('+340 days');
        $fake->queueJson(200, FakeTransport::tokenResponse($base->modify('+430 days'), $base->modify('+700 days'), '2'));
        $monitor->run();
        $monitor->run();
        Assert::same([[30, 25]], $alerts);

        // 6 days left: the 14-day threshold was skipped, the 7-day one is due.
        $travel('+19 days');
        $monitor->run();
        $monitor->run();
        Assert::same([[30, 25], [7, 6]], $alerts);
    },
    'the monitor reports a broken connection as due now' => static function () use ($setup, $authorize): void {
        [$connection, $fake, $travel] = $setup();
        $authorize($connection, $fake);
        $travel('+85 days');
        $fake->queueJson(400, ['error' => 'invalid_grant']);
        $alerts = [];
        (new ConnectionMonitor($connection, static function (int $threshold, int $days) use (&$alerts): void {
            $alerts[] = [$threshold, $days];
        }))->run();
        Assert::same([[0, 0]], $alerts);
    },
    'connection check classifies the ANAF answers' => static function (): void {
        $json = static fn (array $data, int $status = 200): Response => new Response($status, [], (string) json_encode($data));
        $cases = [
            [$json(['mesaje' => [['id' => '1'], ['id' => '2']], 'titlu' => 'Lista Mesaje']), true, '2 messages'],
            [$json(['eroare' => 'Nu exista mesaje in ultimele 1 zile', 'titlu' => 'Lista Mesaje']), true, '0 messages'],
            [$json(['eroare' => 'Nu aveti drept in SPV pentru CIF=50515950', 'titlu' => 'Lista Mesaje']), false, 'no SPV rights'],
            [$json(['message' => 'Unauthorized', 'status' => '401'], 401), false, 'HTTP 401'],
            [new Response(200, [], '<html>Request Rejected</html>'), false, 'Unexpected'],
            [new Response(0, [], '', 6, 'Could not resolve host', false), false, 'could not be reached'],
        ];
        foreach ($cases as [$response, $ok, $text]) {
            $result = ConnectionCheck::interpret($response, '50515950', 'test');
            Assert::same($ok, $result['ok'], $result['message']);
            Assert::true(str_contains($result['message'], $text), $result['message']);
        }
    },
];
