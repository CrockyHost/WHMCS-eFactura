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
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\CompanyLookup;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\LookupLimiter;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\LookupResult;
use WHMCS\Module\Addon\Efactura\ClientData\LookupEndpoint;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * An ANAF answer with one company.
 *
 * @return array<string, mixed>
 */
$answer = static fn (int $cui, string $name): array => [
    'found' => [[
        'date_generale' => ['cui' => $cui, 'denumire' => $name, 'nrRegCom' => 'J2024020698007', 'stare_inregistrare' => 'INREGISTRAT', 'statusRO_e_Factura' => true],
        'inregistrare_scop_Tva' => ['scpTVA' => true],
        'adresa_sediu_social' => ['sdenumire_Localitate' => 'Mun. Craiova', 'sdenumire_Strada' => 'Str. Test', 'snumar_Strada' => '1', 'scod_JudetAuto' => 'DJ', 'scod_Postal' => '200001'],
    ]],
    'notFound' => [],
];

/** Fresh lookup tables for each test (rolled back afterwards). */
$clean = static function (): void {
    Capsule::table(CompanyLookup::CACHE_TABLE)->delete();
    Capsule::table(CompanyLookup::LOG_TABLE)->delete();
};

return [
    'a company found at ANAF is cached for a day' => static function () use ($answer, $clean): void {
        $clean();
        $transport = (new FakeTransport())->queueJson(200, $answer(50515950, 'CROCKY S.R.L.'));
        $lookup = new CompanyLookup($transport);
        $first = $lookup->find('RO 50515950');
        Assert::same(LookupResult::FOUND, $first->status);
        Assert::false($first->cached);
        Assert::same('CROCKY S.R.L.', $first->company->name);
        Assert::same('Craiova', $first->company->city);
        $request = $transport->requests[0];
        Assert::same(CompanyLookup::URL, $request->url);
        $body = json_decode($request->body, true);
        Assert::same(50515950, $body[0]['cui']);
        Assert::same(\WHMCS\Module\Addon\Efactura\Fiscal\Clock::today()->format('Y-m-d'), $body[0]['data']);

        // No second request: the FakeTransport would throw.
        $second = $lookup->find('50515950');
        Assert::true($second->cached);
        Assert::same('CROCKY S.R.L.', $second->company->name);
        $row = Capsule::table(CompanyLookup::CACHE_TABLE)->where('cui', '50515950')->first();
        Assert::same(86400, strtotime($row->expires_at) - strtotime($row->fetched_at));
    },
    'an unknown CUI is cached for an hour; errors are not cached' => static function () use ($clean): void {
        $clean();
        $transport = (new FakeTransport())
            ->queueJson(404, ['found' => [], 'notFound' => [98765012]])
            ->queue(new Response(0, [], '', 28, 'Operation timed out', false))
            ->queueJson(500, ['error' => 'x'])
            ->queue(new Response(200, [], '<html>maintenance</html>'));
        $lookup = new CompanyLookup($transport);
        Assert::same(LookupResult::NOT_FOUND, $lookup->find('98765012')->status);
        Assert::same(LookupResult::NOT_FOUND, $lookup->find('98765012')->status);
        $row = Capsule::table(CompanyLookup::CACHE_TABLE)->where('cui', '98765012')->first();
        Assert::same(3600, strtotime($row->expires_at) - strtotime($row->fetched_at));

        foreach (['50515950', '50515950', '50515950'] as $cui) {
            Assert::same(LookupResult::UNAVAILABLE, $lookup->find($cui)->status);
        }
        Assert::same(0, Capsule::table(CompanyLookup::CACHE_TABLE)->where('cui', '50515950')->count());
    },
    'requests to ANAF are at least a second apart' => static function () use ($answer, $clean): void {
        $clean();
        $transport = (new FakeTransport())
            ->queueJson(200, $answer(50515950, 'A'))
            ->queueJson(200, $answer(14399840, 'B'));
        $lookup = new CompanyLookup($transport);
        $start = microtime(true);
        $lookup->find('50515950');
        $lookup->find('14399840');
        Assert::true(microtime(true) - $start >= 1.05, 'the second request waited');
        Assert::same(2, Capsule::table(CompanyLookup::LOG_TABLE)->where('bucket', 'anaf')->count());
    },
    'lookups are limited per IP and per session, and only hashes are kept' => static function () use ($clean): void {
        $clean();
        [$max] = LookupLimiter::LIMITS['session'];
        for ($i = 0; $i < $max; $i++) {
            Assert::true(LookupLimiter::allow(['ip' => '203.0.113.7', 'session' => 'session-a']));
        }
        Assert::false(LookupLimiter::allow(['ip' => '203.0.113.7', 'session' => 'session-a']));
        // Another session from the same IP still has room under the IP limit.
        Assert::true(LookupLimiter::allow(['ip' => '203.0.113.7', 'session' => 'session-b']));
        Assert::same(0, Capsule::table(CompanyLookup::LOG_TABLE)->where('bucket', 'like', '%203.0.113.7%')->count());
        Assert::same(64, strlen((string) Capsule::table(CompanyLookup::LOG_TABLE)->value('bucket')));
    },
    'the endpoint checks the method, the token and the CUI before calling ANAF' => static function () use ($clean): void {
        $clean();
        Settings::save(['client_forms' => true]);
        $token = (string) generate_token('plain');
        $post = static fn (array $values, string $method = 'POST'): array => LookupEndpoint::process(
            $values,
            ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '203.0.113.9'],
            new CompanyLookup(new FakeTransport())
        );

        Assert::same(405, $post(['token' => $token, 'cui' => '50515950'], 'GET')['status']);
        Assert::same('token', $post(['token' => 'x', 'cui' => '50515950'])['body']['error']);
        Assert::same('invalid_cui', $post(['token' => $token, 'cui' => '50515951'])['body']['error']);
        Assert::same(403, $post(['token' => $token, 'cui' => '50515950', 'scope' => 'admin'])['status']);
        Settings::save(['client_forms' => false]);
        Assert::same('disabled', $post(['token' => $token, 'cui' => '50515950'])['body']['error']);
    },
    'the endpoint answers with the company, or with a clear error' => static function () use ($answer, $clean): void {
        $clean();
        Settings::save(['client_forms' => true]);
        $token = (string) generate_token('plain');
        $server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.10'];
        $transport = (new FakeTransport())
            ->queueJson(200, $answer(50515950, 'CROCKY S.R.L.'))
            ->queueJson(404, ['found' => [], 'notFound' => [98765012]])
            ->queue(new Response(0, [], '', 7, 'Failed to connect', false));
        $lookup = new CompanyLookup($transport);

        $found = LookupEndpoint::process(['token' => $token, 'cui' => 'RO50515950'], $server, $lookup);
        Assert::same(200, $found['status']);
        Assert::true($found['body']['ok']);
        Assert::same('Dolj', $found['body']['company']['county']);
        Assert::true($found['body']['company']['vatPayer']);

        Assert::same(404, LookupEndpoint::process(['token' => $token, 'cui' => '98765012'], $server, $lookup)['status']);
        $down = LookupEndpoint::process(['token' => $token, 'cui' => '14399840'], $server, $lookup);
        Assert::same(503, $down['status']);
        Assert::same('unavailable', $down['body']['error']);
        Assert::true($down['body']['message'] !== '');
    },
    'the endpoint stops a visitor over the limit' => static function () use ($clean): void {
        $clean();
        Settings::save(['client_forms' => true]);
        $token = (string) generate_token('plain');
        [$max] = LookupLimiter::LIMITS['ip'];
        for ($i = 0; $i < $max; $i++) {
            LookupLimiter::allow(['ip' => '203.0.113.11']);
        }
        $limited = LookupEndpoint::process(['token' => $token, 'cui' => '50515950'], ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.11'], new CompanyLookup(new FakeTransport()));
        Assert::same(429, $limited['status']);
        Assert::same('120', $limited['headers']['Retry-After']);
    },
];
