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

namespace WHMCS\Module\Addon\Efactura\Anaf;

use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Http\Transport;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\ModuleLog;

/**
 * Calls to the e-Factura REST API (api.anaf.ro/{test|prod}/FCTEL/rest) with
 * the OAuth access token. ANAF reports most errors with HTTP 200 and a
 * message in the body, so callers classify the content (ResponseParser),
 * not the status. A 401 or 403 is retried once after refreshing the token:
 * the gateway rejects such requests before ANAF processes them.
 */
final class ApiClient
{
    /** Upload timeout: generous, because a request cut short has an unknown outcome. */
    private const UPLOAD_TIMEOUT = 120;
    private const TIMEOUT = 45;

    public function __construct(
        private readonly Transport $transport,
        private readonly Connection $connection,
        private readonly string $environment,
    ) {
    }

    public static function baseUrl(string $environment): string
    {
        return 'https://api.anaf.ro/' . ($environment === Settings::ENV_PROD ? 'prod' : 'test') . '/FCTEL/rest/';
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * Uploads an invoice (standard UBL). B2C invoices go to uploadb2c;
     * extern=DA marks a buyer outside Romania.
     */
    public function upload(string $xml, bool $b2c, string $cif, bool $extern): Response
    {
        $query = ['standard' => 'UBL', 'cif' => $cif];
        if ($extern) {
            $query['extern'] = 'DA';
        }

        return $this->call('POST', $b2c ? 'uploadb2c' : 'upload', $query, $xml, self::UPLOAD_TIMEOUT);
    }

    public function messageState(string $uploadIndex): Response
    {
        return $this->call('GET', 'stareMesaj', ['id_incarcare' => $uploadIndex]);
    }

    public function download(string $downloadId): Response
    {
        return $this->call('GET', 'descarcare', ['id' => $downloadId]);
    }

    /**
     * Messages of the last $days days (1 to 60) for the seller CUI. Also the
     * cheapest way to check that the token has SPV rights for the CUI.
     */
    public function listMessages(string $cui, int $days): Response
    {
        return $this->call('GET', 'listaMesajeFactura', ['zile' => max(1, min(60, $days)), 'cif' => $cui]);
    }

    /**
     * Messages between two moments (Unix milliseconds), 500 per page.
     *
     * @param string|null $filter E (errors), T (sent), P (received), R (buyer messages)
     */
    public function listMessagesPaged(string $cui, int $startMs, int $endMs, int $page = 1, ?string $filter = null): Response
    {
        $query = ['startTime' => $startMs, 'endTime' => $endMs, 'cif' => $cui, 'pagina' => $page];
        if ($filter !== null) {
            $query['filtru'] = $filter;
        }

        return $this->call('GET', 'listaMesajePaginatieFactura', $query);
    }

    /**
     * @param array<string, string|int> $query
     */
    private function call(string $method, string $endpoint, array $query, string $body = '', int $timeout = self::TIMEOUT): Response
    {
        $response = $this->send($method, $endpoint, $query, $body, $timeout, $this->connection->accessToken());
        if (in_array($response->status, [401, 403], true)) {
            try {
                $this->connection->refresh(true);
            } catch (OAuthException) {
                return $response;
            }
            $response = $this->send($method, $endpoint, $query, $body, $timeout, $this->connection->accessToken());
        }

        return $response;
    }

    /**
     * @param array<string, string|int> $query
     */
    private function send(string $method, string $endpoint, array $query, string $body, int $timeout, string $token): Response
    {
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => '*/*'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'text/plain';
        }
        $request = new Request($method, self::baseUrl($this->environment) . $endpoint . '?' . http_build_query($query), $headers, $body, $timeout, 15);
        $response = $this->transport->send($request);
        // The invoice XML holds client data: the log gets its size and hash only.
        $logged = $body === '' ? $request : new Request($method, $request->url, $headers, sprintf('[XML, %d bytes, sha256 %s]', strlen($body), hash('sha256', $body)));
        ModuleLog::call($endpoint . ' (' . $this->environment . ')', $logged, $response, [$token]);

        return $response;
    }
}
