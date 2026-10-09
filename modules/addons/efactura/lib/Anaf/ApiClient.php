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
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Http\Transport;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\ModuleLog;

/**
 * Calls to the e-Factura REST API (api.anaf.ro/{test|prod}/FCTEL/rest) with
 * the OAuth access token. ANAF reports most errors with HTTP 200 and a
 * message in the body, so callers classify the content, not the status.
 */
final class ApiClient
{
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
     * Messages of the last $days days (1 to 60) for the seller CUI. Also the
     * cheapest way to check that the token has SPV rights for the CUI.
     */
    public function listMessages(string $cui, int $days): Response
    {
        return $this->get('listaMesajeFactura', ['zile' => max(1, min(60, $days)), 'cif' => $cui]);
    }

    /**
     * @param array<string, string|int> $query
     */
    private function get(string $endpoint, array $query): Response
    {
        $token = $this->connection->accessToken();
        $request = new Request('GET', self::baseUrl($this->environment) . $endpoint . '?' . http_build_query($query), [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ], '', 60, 15);
        $response = $this->transport->send($request);
        ModuleLog::call($endpoint . ' (' . $this->environment . ')', $request, $response, [$token]);

        return $response;
    }
}
