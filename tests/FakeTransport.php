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

use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Http\Transport;

/**
 * Transport that answers from a queue of canned responses and records the
 * requests, so that no test talks to ANAF.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    private array $responses = [];

    public function queue(Response $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function queueJson(int $status, array $data): self
    {
        return $this->queue(new Response($status, ['content-type' => 'application/json'], (string) json_encode($data)));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            throw new RuntimeException('FakeTransport: unexpected request to ' . $request->url);
        }

        return array_shift($this->responses);
    }

    /**
     * An unsigned JWT with the given claims, shaped like the ANAF tokens.
     *
     * @param array<string, mixed> $claims
     */
    public static function jwt(array $claims): string
    {
        $encode = static fn (string $text): string => rtrim(strtr(base64_encode($text), '+/', '-_'), '=');

        return $encode('{"kid":"anaf_test","alg":"RS512"}') . '.' . $encode((string) json_encode($claims)) . '.' . $encode('signature');
    }

    /**
     * Token endpoint response with JWTs that expire at the given times.
     *
     * @return array<string, mixed>
     */
    public static function tokenResponse(DateTimeImmutable $accessExpires, DateTimeImmutable $refreshExpires, string $suffix = '1'): array
    {
        return [
            'access_token' => self::jwt(['exp' => $accessExpires->getTimestamp(), 'serial' => 'CERT-' . $suffix, 'role' => ['EFACTURA', 'HELLO'], 'n' => 'a' . $suffix]),
            'refresh_token' => self::jwt(['exp' => $refreshExpires->getTimestamp(), 'n' => 'r' . $suffix]),
            'token_type' => 'Bearer',
            'expires_in' => 7776000,
            'scope' => 'clientappid issuer role serial',
        ];
    }
}
