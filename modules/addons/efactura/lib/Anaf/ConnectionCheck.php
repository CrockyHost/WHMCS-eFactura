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

use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Romania\Text;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Checks the connection end to end: the token is accepted and its holder
 * has SPV rights for the seller CUI. Uses the message list of the last day,
 * which reads data only.
 */
final class ConnectionCheck
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function run(string $cui): array
    {
        if ($cui === '') {
            return ['ok' => false, 'message' => Lang::get('anaf_check_no_cui')];
        }
        try {
            $response = $this->api->listMessages($cui, 1);
        } catch (OAuthException $e) {
            return ['ok' => false, 'message' => Lang::get('anaf_check_token', $e->getMessage())];
        }

        return self::interpret($response, $cui, $this->api->environment());
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function interpret(Response $response, string $cui, string $environment): array
    {
        $env = Lang::get($environment === 'prod' ? 'env_prod' : 'env_test');
        if ($response->failed()) {
            return ['ok' => false, 'message' => Lang::get('anaf_check_unreachable', $response->error)];
        }
        if (in_array($response->status, [401, 403], true)) {
            return ['ok' => false, 'message' => Lang::get('anaf_check_rejected', $response->status)];
        }

        $json = $response->json();
        if ($json === null) {
            $excerpt = mb_substr(trim(strip_tags($response->body)), 0, 200);

            return ['ok' => false, 'message' => Lang::get('anaf_check_unexpected', $response->status, $excerpt)];
        }
        if (isset($json['mesaje']) && is_array($json['mesaje'])) {
            return ['ok' => true, 'message' => Lang::get('anaf_check_ok', $cui, $env, count($json['mesaje']))];
        }

        $error = trim((string) ($json['eroare'] ?? $json['message'] ?? ''));
        $folded = Text::fold($error);
        if (str_contains($folded, 'nu exista mesaje')) {
            return ['ok' => true, 'message' => Lang::get('anaf_check_ok', $cui, $env, 0)];
        }
        if (str_contains($folded, 'nu aveti drept')) {
            return ['ok' => false, 'message' => Lang::get('anaf_check_no_rights', $cui, $error)];
        }

        return ['ok' => false, 'message' => Lang::get('anaf_check_error', $error !== '' ? $error : 'HTTP ' . $response->status)];
    }
}
