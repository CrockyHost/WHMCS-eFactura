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

use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * Which client form a request comes from, how strictly it is validated and
 * which fields the client cannot change there. Only the client area forms
 * are validated; the admin area, the API and other modules are not.
 */
final class FormContext
{
    public const REGISTER = 'register';
    public const CHECKOUT = 'checkout';
    public const PROFILE = 'profile';
    public const CONTACT = 'contact';
    public const ADMIN = 'admin';
    public const OTHER = 'other';

    public const STRICT = 'strict';
    public const WARN = 'warn';
    public const OFF = 'off';

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $query $_GET
     */
    public static function detect(array $server, array $query, bool $adminArea): string
    {
        if ($adminArea) {
            return self::ADMIN;
        }

        $script = strtolower(basename((string) ($server['SCRIPT_NAME'] ?? $server['SCRIPT_FILENAME'] ?? '')));
        $path = strtolower((string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $route = strtolower((string) ($query['rp'] ?? ''));
        $action = strtolower((string) ($query['action'] ?? ''));

        return match (true) {
            $script === 'register.php' => self::REGISTER,
            $script === 'cart.php' => self::CHECKOUT,
            $script === 'clientarea.php' && $action === 'details' => self::PROFILE,
            $script === 'clientarea.php' && in_array($action, ['contacts', 'addcontact'], true) => self::CONTACT,
            str_contains($route, '/account/contacts') || str_contains($path, '/account/contacts') => self::CONTACT,
            str_contains($route, '/account/paymentmethods') || str_contains($path, '/account/paymentmethods') => self::CONTACT,
            default => self::OTHER,
        };
    }

    public static function current(): string
    {
        return self::detect($_SERVER, $_GET, defined('ADMINAREA'));
    }

    /**
     * strict, warn or off for a context, from the settings.
     */
    public static function mode(string $context): string
    {
        $setting = match ($context) {
            self::REGISTER, self::CHECKOUT => 'client_validation_new',
            self::PROFILE, self::CONTACT => 'client_validation_profile',
            default => null,
        };
        if ($setting === null || !Settings::bool('client_forms')) {
            return self::OFF;
        }

        return Settings::string($setting) === self::WARN ? self::WARN : self::STRICT;
    }

    /**
     * Native fields the client cannot change in the profile
     * (General Settings > Other > Locked Client Profile Fields).
     *
     * @return list<string>
     */
    public static function lockedProfileFields(): array
    {
        $value = (string) \WHMCS\Config\Setting::getValue('ClientsProfileUneditableFields');

        return array_values(array_filter(array_map('trim', explode(',', strtolower($value)))));
    }
}
