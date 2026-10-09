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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;

/**
 * The stylesheet, the configuration and the script of the client forms,
 * added through the output hooks to the pages that hold a client address
 * form. The theme files are never changed: the script extends the forms
 * WHMCS renders (StatesDropdown.js does the county dropdown itself once the
 * Romanian list is registered).
 */
final class PageAssets
{
    /** Client area template => form context. */
    private const CLIENT_TEMPLATES = [
        'clientregister' => FormContext::REGISTER,
        'checkout' => FormContext::CHECKOUT,
        'viewcart' => FormContext::CHECKOUT,
        'clientareadetails' => FormContext::PROFILE,
        'account-contacts-new' => FormContext::CONTACT,
        'account-contacts-manage' => FormContext::CONTACT,
        'account-paymentmethods-manage' => FormContext::CONTACT,
    ];

    /** Admin pages with a client or contact address form. */
    private const ADMIN_PAGES = ['clientsprofile', 'clientsadd', 'clientscontacts'];

    /** Strings the script needs. */
    private const SCRIPT_TEXTS = [
        'cd_hint_state_invalid',
        'cd_hint_sector_missing',
        'cd_sector',
        'cd_sector_choose',
    ];

    /**
     * @param array<string, mixed> $vars ClientAreaHeadOutput parameters
     */
    public static function clientHead(array $vars): string
    {
        if (self::clientContext($vars) === null) {
            return '';
        }

        return self::stylesheet(self::clientBase($vars));
    }

    /**
     * @param array<string, mixed> $vars ClientAreaFooterOutput parameters
     */
    public static function clientFooter(array $vars): string
    {
        $context = self::clientContext($vars);
        if ($context === null) {
            return '';
        }

        return self::script(self::clientBase($vars), self::config($context, Texts::client()));
    }

    /**
     * @param array<string, mixed> $vars AdminAreaHeadOutput parameters
     */
    public static function adminHead(array $vars): string
    {
        return self::isAdminPage($vars) ? self::stylesheet(self::adminBase()) : '';
    }

    /**
     * @param array<string, mixed> $vars AdminAreaFooterOutput parameters
     */
    public static function adminFooter(array $vars): string
    {
        if (!self::isAdminPage($vars)) {
            return '';
        }

        return self::script(self::adminBase(), self::config(FormContext::ADMIN, Texts::for(self::adminLanguage())));
    }

    /**
     * Everything the script needs, as one JSON object.
     *
     * @return array<string, mixed>
     */
    public static function config(string $context, Texts $texts): array
    {
        $mode = FormContext::mode($context);

        return [
            'context' => $context,
            'strict' => $mode === FormContext::STRICT,
            'counties' => CountyField::names(),
            'codes' => CountyField::codes(),
            'bucharest' => CountyField::bucharest(),
            'sectors' => CountyField::sectors(),
            'text' => $texts->pick(self::SCRIPT_TEXTS),
        ];
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function clientContext(array $vars): ?string
    {
        $template = (string) ($vars['templatefile'] ?? '');
        if (!isset(self::CLIENT_TEMPLATES[$template]) || !Settings::bool('client_forms')) {
            return null;
        }

        return self::CLIENT_TEMPLATES[$template];
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function isAdminPage(array $vars): bool
    {
        return in_array((string) ($vars['filename'] ?? ''), self::ADMIN_PAGES, true) && Settings::bool('client_forms');
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function clientBase(array $vars): string
    {
        return rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/') . '/modules/addons/' . Addon::MODULE . '/assets/client';
    }

    private static function adminBase(): string
    {
        return '../modules/addons/' . Addon::MODULE . '/assets/client';
    }

    private static function stylesheet(string $base): string
    {
        return '<link rel="stylesheet" href="' . self::url($base, 'clientdata.css') . '">';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function script(string $base, array $config): string
    {
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Not deferred: the script must register the counties before StatesDropdown.js runs on DOM ready.
        return '<script>window.efacturaClientData = ' . $json . ';</script>'
            . '<script src="' . self::url($base, 'clientdata.js') . '"></script>';
    }

    private static function url(string $base, string $file): string
    {
        $path = Addon::path('assets/client/' . $file);
        $version = Addon::VERSION . '.' . (is_file($path) ? (string) filemtime($path) : '0');

        return htmlspecialchars($base . '/' . $file . '?v=' . $version, ENT_QUOTES);
    }

    /**
     * The interface language of the addon for the current administrator.
     */
    private static function adminLanguage(): string
    {
        $setting = Settings::string('ui_language');
        if ($setting !== 'auto') {
            return $setting;
        }
        $adminId = AdminContext::id();

        return $adminId === null ? 'english' : strtolower((string) Capsule::table('tbladmins')->where('id', $adminId)->value('language'));
    }
}
