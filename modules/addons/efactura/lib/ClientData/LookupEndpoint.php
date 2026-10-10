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

namespace WHMCS\Module\Addon\Efactura\ClientData;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\CompanyLookup;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\LookupLimiter;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\LookupResult;
use WHMCS\Module\Addon\Efactura\Http\CurlTransport;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Input;

/**
 * JSON endpoint of the "fetch from ANAF" button (clientdata.php): POST with
 * the session token and a CUI, from the client forms (visitors included)
 * or, with scope=admin, from the admin client pages. ANAF is only called
 * from here, never from the browser.
 */
final class LookupEndpoint
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param string|null $clientIp the visitor IP as WHMCS determines it; REMOTE_ADDR when null
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public static function process(array $post, array $server, ?CompanyLookup $lookup = null, ?string $clientIp = null): array
    {
        $admin = ($post['scope'] ?? '') === 'admin';
        $adminId = $admin ? self::adminId() : null;
        $texts = $adminId !== null ? Texts::admin($adminId) : Texts::client();

        if (($server['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return self::error(405, 'method', $texts->get('cd_lookup_error'));
        }
        if (!self::enabled()) {
            return self::error(404, 'disabled', $texts->get('cd_lookup_error'));
        }
        $token = (string) ($post['token'] ?? '');
        if ($token === '' || !hash_equals((string) generate_token('plain'), $token)) {
            return self::error(403, 'token', $texts->get('cd_lookup_error_token'));
        }
        if ($admin && $adminId === null) {
            return self::error(403, 'forbidden', $texts->get('cd_lookup_error'));
        }

        $cui = Cui::normalize((string) ($post['cui'] ?? ''));
        if (!Cui::isValid($cui)) {
            return self::error(422, 'invalid_cui', $texts->get('cd_error_cui_invalid'));
        }

        $keys = $admin
            ? ['admin' => (string) $adminId]
            : ['ip' => $clientIp ?? (string) ($server['REMOTE_ADDR'] ?? ''), 'session' => (string) session_id()];
        if (!LookupLimiter::allow($keys)) {
            $result = self::error(429, 'rate_limited', $texts->get('cd_lookup_error_rate'));
            $result['headers']['Retry-After'] = '120';

            return $result;
        }

        $found = ($lookup ?? new CompanyLookup(new CurlTransport()))->find($cui);

        return match ($found->status) {
            LookupResult::FOUND => [
                'status' => 200,
                'body' => ['ok' => true, 'company' => $found->company?->toArray(), 'cached' => $found->cached],
                'headers' => [],
            ],
            LookupResult::NOT_FOUND => self::error(404, 'not_found', $texts->get('cd_lookup_not_found')),
            default => self::error(503, 'unavailable', $texts->get('cd_lookup_unavailable')),
        };
    }

    /**
     * Answers the current request and ends it.
     */
    public static function handle(): never
    {
        try {
            $result = self::process(Input::post(), $_SERVER, null, self::clientIp());
        } catch (Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('WHMCS-eFactura company lookup error: ' . $e->getMessage());
            }
            $result = self::error(500, 'error', Texts::client()->get('cd_lookup_error'));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($result['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($result['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * The visitor IP as WHMCS determines it: behind Cloudflare or another
     * proxy, WHMCS takes it from the proxy header of its settings (General
     * Settings > Security: Proxy IP Header and Trusted Proxies). Without
     * that, every visitor would share the proxy address and the per-IP limit.
     */
    private static function clientIp(): string
    {
        try {
            $ip = (string) \WHMCS\Utility\Environment\CurrentRequest::getIP();
        } catch (Throwable) {
            $ip = '';
        }

        return $ip !== '' ? $ip : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * The addon is active and its client forms are on.
     */
    private static function enabled(): bool
    {
        $active = array_map('trim', explode(',', (string) \WHMCS\Config\Setting::getValue('ActiveAddonModules')));

        return in_array(Addon::MODULE, $active, true)
            && Capsule::schema()->hasTable(Settings::TABLE)
            && Settings::bool('client_forms');
    }

    /**
     * The logged-in administrator, if the role has access to the addon.
     */
    private static function adminId(): ?int
    {
        if (!class_exists(\WHMCS\Auth::class) || !\WHMCS\Auth::isLoggedIn()) {
            return null;
        }
        $adminId = (int) \WHMCS\Auth::getID();
        $role = Capsule::table('tbladmins')->where('id', $adminId)->where('disabled', 0)->value('roleid');
        if ($role === null) {
            return null;
        }
        $access = (string) Capsule::table('tbladdonmodules')->where('module', Addon::MODULE)->where('setting', 'access')->value('value');

        return in_array((string) $role, array_map('trim', explode(',', $access)), true) ? $adminId : null;
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private static function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $code, 'message' => $message], 'headers' => []];
    }
}
