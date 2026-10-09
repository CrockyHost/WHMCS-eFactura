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

namespace WHMCS\Module\Addon\Efactura\Admin;

use DateTimeInterface;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Anaf\ConnectionCheck;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * The "ANAF connection" page: OAuth application credentials, authorization
 * with the qualified certificate, token refresh, connection test.
 */
final class AnafPage
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{view: string, vars: array<string, mixed>}
     */
    public function handle(array $post, bool $isPost, bool $tokenValid, bool $justConnected): array
    {
        $alert = $justConnected ? ['type' => 'success', 'text' => Lang::get('anaf_connected')] : null;
        $errors = [];

        if ($isPost) {
            if (!$tokenValid) {
                $alert = ['type' => 'danger', 'text' => Lang::get('error_csrf')];
            } else {
                $action = (string) ($post['action'] ?? '');
                try {
                    switch ($action) {
                        case 'credentials':
                            [$alert, $errors] = $this->saveCredentials($post);
                            break;
                        case 'authorize':
                            return [
                                'view' => 'authorize',
                                'vars' => [
                                    'authorizeUrl' => $this->connection->startAuthorization(AdminContext::id()),
                                    'forOtherPerson' => ($post['mode'] ?? '') === 'link',
                                    'linkIntro' => Lang::get('anaf_authorize_link_intro', Connection::STATE_TTL_MINUTES),
                                ],
                            ];
                        case 'refresh':
                            $this->connection->refresh(true);
                            $alert = ['type' => 'success', 'text' => Lang::get('anaf_refreshed')];
                            break;
                        case 'check':
                            $result = (new ConnectionCheck(Addon::api()))->run(Settings::string('company_cui'));
                            $alert = ['type' => $result['ok'] ? 'success' : 'danger', 'text' => $result['message']];
                            break;
                        case 'disconnect':
                            $this->connection->disconnect(AdminContext::id());
                            $alert = ['type' => 'info', 'text' => Lang::get('anaf_disconnected')];
                            break;
                    }
                } catch (OAuthException $e) {
                    $alert = ['type' => 'danger', 'text' => Lang::get('anaf_action_failed', $e->getMessage())];
                }
            }
        }

        return ['view' => 'anaf', 'vars' => $this->vars($alert, $errors, $post)];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array{type: string, text: string}|null, 1: array<string, string>}
     */
    private function saveCredentials(array $post): array
    {
        $clientId = trim((string) ($post['client_id'] ?? ''));
        $secret = trim((string) ($post['client_secret'] ?? ''));
        $errors = [];
        if ($clientId === '' || strlen($clientId) > 255 || preg_match('/\s/', $clientId) === 1) {
            $errors['client_id'] = Lang::get('anaf_error_client_id');
        }
        if ($secret === '' && !$this->connection->status()['has_secret']) {
            $errors['client_secret'] = Lang::get('anaf_error_client_secret');
        }
        if ($errors !== []) {
            return [['type' => 'danger', 'text' => Lang::get('settings_not_saved')], $errors];
        }

        $this->connection->saveCredentials($clientId, $secret === '' ? null : $secret);

        return [['type' => 'success', 'text' => Lang::get('anaf_credentials_saved')], []];
    }

    /**
     * @param array{type: string, text: string}|null $alert
     * @param array<string, string> $errors
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function vars(?array $alert, array $errors, array $post): array
    {
        $status = $this->connection->status();
        $callbackUrl = Connection::callbackUrl();

        [$stateKey, $stateClass] = match (true) {
            !$status['configured'] => ['anaf_state_not_configured', 'default'],
            $status['needs_reauthorization'] => ['anaf_state_reauthorize', 'danger'],
            !$status['connected'] => ['anaf_state_not_connected', 'warning'],
            $status['days_left'] !== null && $status['days_left'] <= 30 => ['anaf_state_expiring', 'warning'],
            default => ['anaf_state_connected', 'success'],
        };

        $details = [];
        if ($status['connected']) {
            $details[] = [Lang::get('anaf_field_authorized'), Lang::get('anaf_authorized_value', self::format($status['authorized_at']), AdminContext::name($status['authorized_by']) ?: '-')];
            $details[] = [Lang::get('anaf_field_certificate'), $status['certificate_serial'] ?: '-'];
            $details[] = [Lang::get('anaf_field_roles'), $status['token_roles'] ?: '-'];
            $details[] = [Lang::get('anaf_field_access'), self::format($status['access_expires_at'])];
            $details[] = [Lang::get('anaf_field_reauth'), $status['days_left'] === null ? '-' : Lang::get('anaf_days_left', self::format($status['refresh_expires_at'], 'Y-m-d'), max(0, $status['days_left']))];
            $details[] = [Lang::get('anaf_field_refreshed'), $status['refreshed_at'] === null ? Lang::get('anaf_never') : self::format($status['refreshed_at'])];
        }
        $details[] = [Lang::get('anaf_field_environment'), Lang::get(Settings::environment() === Settings::ENV_PROD ? 'env_prod' : 'env_test')];
        if ($status['last_error'] !== '') {
            $details[] = [Lang::get('anaf_field_error'), self::format($status['last_error_at']) . ': ' . $status['last_error']];
        }

        $steps = [];
        for ($i = 1; $i <= 4; $i++) {
            $steps[] = Lang::get('anaf_app_step' . $i);
        }

        return [
            'alert' => $alert,
            'errors' => $errors,
            'status' => $status,
            'stateLabel' => Lang::get($stateKey),
            'stateClass' => $stateClass,
            'details' => $details,
            'callbackUrl' => $callbackUrl,
            'callbackHttps' => str_starts_with($callbackUrl, 'https://'),
            'clientId' => isset($post['client_id']) ? (string) $post['client_id'] : $status['client_id'],
            'appSteps' => $steps,
            'connectHelp' => Lang::get('anaf_connect_help', Connection::STATE_TTL_MINUTES),
        ];
    }

    private static function format(?DateTimeInterface $date, string $format = 'Y-m-d H:i'): string
    {
        return $date === null ? '-' : $date->format($format);
    }
}
