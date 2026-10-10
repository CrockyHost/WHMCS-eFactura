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
use WHMCS\Module\Addon\Efactura\Support\Input;

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

    /** Admin pages with a client or contact address form, and the client summary. */
    private const ADMIN_PAGES = ['clientsprofile', 'clientsadd', 'clientscontacts', 'clientssummary'];

    /** Strings the script needs. */
    private const SCRIPT_TEXTS = [
        'cd_hint_state_invalid',
        'cd_hint_sector_missing',
        'cd_sector',
        'cd_sector_choose',
        'cd_billing_title',
        'cd_type_label',
        'cd_type_person',
        'cd_type_person_hint',
        'cd_type_company',
        'cd_type_company_hint',
        'cd_cui',
        'cd_cui_placeholder',
        'cd_company_name',
        'cd_regcom',
        'cd_regcom_placeholder',
        'cd_optional',
        'cd_vat_payer',
        'cd_vat_payer_hint',
        'cd_vat_code',
        'cd_vat_number',
        'cd_cnp',
        'cd_cnp_help',
        'cd_missing',
        'cd_yes',
        'cd_no',
        'cd_lock_note',
        'cd_lock_link',
        'cd_error_cui_required',
        'cd_error_cui_invalid',
        'cd_error_company_name',
        'cd_error_regcom',
        'cd_error_cnp',
        'cd_lookup_button',
        'cd_lookup_address_button',
        'cd_lookup_busy',
        'cd_lookup_filled',
        'cd_lookup_same',
        'cd_lookup_review',
        'cd_lookup_apply',
        'cd_lookup_keep',
        'cd_lookup_now',
        'cd_lookup_anaf',
        'cd_lookup_locked',
        'cd_lookup_error',
        'cd_lookup_need_cui',
        'cd_field_address1',
        'cd_field_address2',
        'cd_field_city',
        'cd_field_state',
        'cd_field_postcode',
        'cd_status_vat',
        'cd_status_novat',
        'cd_status_vat_collection',
        'cd_status_inactive',
        'cd_status_deregistered',
        'cd_status_einvoice',
    ];

    /** Forms that get the billing details section (client type, CUI, CNP). */
    private const IDENTITY_CONTEXTS = [FormContext::REGISTER, FormContext::CHECKOUT, FormContext::PROFILE];

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

        $root = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
        $config = self::config($context, Texts::client());
        $config['ticketUrl'] = $root . '/submitticket.php';
        $config['lookupUrl'] = $root . '/modules/addons/' . Addon::MODULE . '/clientdata.php';

        return self::script(self::clientBase($vars), $config);
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

        $texts = Texts::admin(AdminContext::id());
        $config = self::config(FormContext::ADMIN, $texts);
        $config['lookupUrl'] = '../modules/addons/' . Addon::MODULE . '/clientdata.php';
        $config['lookupScope'] = 'admin';
        if (($vars['filename'] ?? '') === 'clientssummary') {
            // Rows for the "Clients Information" panel.
            $config['summary'] = ClientSummary::rows((int) (Input::get()['userid'] ?? 0), $texts);
        }

        return self::script(self::adminBase(), $config);
    }

    /**
     * Everything the script needs, as one JSON object.
     *
     * @return array<string, mixed>
     */
    public static function config(string $context, Texts $texts): array
    {
        $mode = FormContext::mode($context);
        $fields = FieldMap::load();
        $identity = in_array($context, self::IDENTITY_CONTEXTS, true) && $fields->id('cui') !== null;
        $postedType = (string) (Input::post()[ClientValidation::TYPE_FIELD] ?? '');

        return [
            'context' => $context,
            'strict' => $mode === FormContext::STRICT,
            'counties' => CountyField::names(),
            'codes' => CountyField::codes(),
            'bucharest' => CountyField::bucharest(),
            'sectors' => CountyField::sectors(),
            'identity' => $identity,
            'fields' => $fields->all(),
            'type' => in_array($postedType, [IdentityRules::PERSON, IdentityRules::COMPANY], true) ? $postedType : '',
            'lock' => $identity && $context === FormContext::PROFILE && Settings::bool('client_profile_lock'),
            'locked' => $context === FormContext::PROFILE ? FormContext::lockedProfileFields() : [],
            'year' => (int) date('Y'),
            // The ANAF lookup needs the CUI field; the endpoint checks the session token.
            'lookup' => $fields->id('cui') !== null && in_array($context, [...self::IDENTITY_CONTEXTS, FormContext::ADMIN], true),
            'lookupScope' => 'client',
            'token' => function_exists('generate_token') ? (string) generate_token('plain') : '',
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
}
