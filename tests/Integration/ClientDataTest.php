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
use WHMCS\Module\Addon\Efactura\Admin\SettingsForm;
use WHMCS\Module\Addon\Efactura\ClientData\ClientValidation;
use WHMCS\Module\Addon\Efactura\ClientData\FormContext;
use WHMCS\Module\Addon\Efactura\ClientData\PageAssets;
use WHMCS\Module\Addon\Efactura\ClientData\Texts;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\ClientDirectory;

/**
 * A fictitious client (rolled back with the test transaction).
 *
 * @param array<string, string> $address
 */
$fakeClient = static function (array $address): int {
    $now = date('Y-m-d H:i:s');

    return (int) Capsule::table('tblclients')->insertGetId($address + [
        'uuid' => '',
        'firstname' => 'Test',
        'lastname' => 'ClientData',
        'companyname' => '',
        'email' => 'clientdata-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'address1' => 'Str. Test 1',
        'address2' => '',
        'postcode' => '000000',
        'phonenumber' => '',
        'tax_id' => '',
        'password' => '',
        'currency' => 1,
        'notes' => '',
        'status' => 'Active',
        'language' => '',
        'datecreated' => date('Y-m-d'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};

$english = Texts::for('english');

return [
    'client forms are on and strict by default' => static function (): void {
        Capsule::table(Settings::TABLE)->whereIn('name', ['client_forms', 'client_validation_new', 'client_validation_profile'])->delete();
        Settings::reset();
        Assert::true(Settings::bool('client_forms'));
        Assert::same(FormContext::STRICT, FormContext::mode(FormContext::REGISTER));
        Assert::same(FormContext::STRICT, FormContext::mode(FormContext::PROFILE));
        Assert::same(FormContext::OFF, FormContext::mode(FormContext::ADMIN));
        Assert::same(FormContext::OFF, FormContext::mode(FormContext::OTHER));
    },
    'registration with an invalid county or no Bucharest sector is refused' => static function () use ($english): void {
        $base = ['country' => 'RO', 'city' => 'Cluj-Napoca', 'address1' => 'Str. Test 1', 'address2' => ''];
        Assert::same([$english->get('cd_error_county')], ClientValidation::client($base + ['state' => 'Romania'], FormContext::REGISTER));
        Assert::same([], ClientValidation::client($base + ['state' => 'Cluj'], FormContext::REGISTER));
        Assert::same(
            [$english->get('cd_error_sector')],
            ClientValidation::client(['state' => 'București', 'city' => 'Bucuresti'] + $base, FormContext::CHECKOUT)
        );
        Assert::same([], ClientValidation::client(['state' => 'București', 'city' => 'Sector 4'] + $base, FormContext::CHECKOUT));
        Assert::same([], ClientValidation::client(['country' => 'FR', 'state' => 'Paris', 'city' => 'Paris'], FormContext::REGISTER));
    },
    'an empty county is reported once, by WHMCS, unless the field is optional' => static function () use ($english): void {
        $previous = (string) \WHMCS\Config\Setting::getValue('ClientsProfileOptionalFields');
        $empty = ['country' => 'RO', 'state' => '', 'city' => 'Cluj-Napoca'];
        try {
            \WHMCS\Config\Setting::setValue('ClientsProfileOptionalFields', '');
            Assert::same([], ClientValidation::client($empty, FormContext::REGISTER));
            \WHMCS\Config\Setting::setValue('ClientsProfileOptionalFields', 'state,postcode');
            Assert::same([$english->get('cd_error_county')], ClientValidation::client($empty, FormContext::REGISTER));
        } finally {
            \WHMCS\Config\Setting::setValue('ClientsProfileOptionalFields', $previous);
        }
    },
    'requests outside the client area forms are not validated' => static function (): void {
        $invalid = ['country' => 'RO', 'state' => 'Romania', 'city' => 'X'];
        Assert::same([], ClientValidation::client($invalid, FormContext::OTHER));
        Assert::same([], ClientValidation::client($invalid, FormContext::ADMIN));
        Assert::same([], ClientValidation::contact($invalid, FormContext::REGISTER));
        Assert::same(1, count(ClientValidation::contact($invalid, FormContext::CONTACT)));
    },
    'warning mode never blocks' => static function (): void {
        Settings::save(['client_validation_new' => 'warn']);
        Assert::same([], ClientValidation::client(['country' => 'RO', 'state' => 'Romania', 'city' => 'X'], FormContext::REGISTER));
        Settings::save(['client_forms' => false, 'client_validation_new' => 'strict']);
        Assert::same([], ClientValidation::client(['country' => 'RO', 'state' => 'Romania', 'city' => 'X'], FormContext::REGISTER));
    },
    'profile: an old invalid county must be fixed, unless the client cannot change it' => static function () use ($fakeClient, $english): void {
        $clientId = $fakeClient(['country' => 'RO', 'state' => 'Romania', 'city' => 'Craiova']);
        $previousUid = $_SESSION['uid'] ?? null;
        $previousLocked = (string) \WHMCS\Config\Setting::getValue('ClientsProfileUneditableFields');
        $_SESSION['uid'] = $clientId;
        try {
            // Country is locked and not submitted: the stored RO applies.
            Assert::same([$english->get('cd_error_county')], ClientValidation::client(['state' => 'Romania', 'city' => 'Craiova'], FormContext::PROFILE));
            Assert::same([], ClientValidation::client(['state' => 'Dolj', 'city' => 'Craiova'], FormContext::PROFILE));

            \WHMCS\Config\Setting::setValue('ClientsProfileUneditableFields', 'companyname,country,tax_id,state');
            Assert::same([], ClientValidation::client(['state' => 'Romania', 'city' => 'Craiova'], FormContext::PROFILE));
        } finally {
            \WHMCS\Config\Setting::setValue('ClientsProfileUneditableFields', $previousLocked);
            if ($previousUid === null) {
                unset($_SESSION['uid']);
            } else {
                $_SESSION['uid'] = $previousUid;
            }
        }
    },
    'the script and its settings are added only to the client address forms' => static function (): void {
        Settings::save(['client_forms' => true]);
        $footer = PageAssets::clientFooter(['templatefile' => 'clientregister', 'WEB_ROOT' => '']);
        Assert::true(str_contains($footer, 'window.efacturaClientData = '));
        Assert::true(str_contains($footer, '/modules/addons/efactura/assets/client/clientdata.js?v='));
        Assert::true(str_contains($footer, '"București"'));
        Assert::true(str_contains(PageAssets::clientHead(['templatefile' => 'clientareadetails', 'WEB_ROOT' => '/billing']), 'href="/billing/modules/addons/efactura/assets/client/clientdata.css?v='));
        Assert::same('', PageAssets::clientFooter(['templatefile' => 'clientareainvoices', 'WEB_ROOT' => '']));
        Assert::true(str_contains(PageAssets::adminFooter(['filename' => 'clientsprofile']), '../modules/addons/efactura/assets/client/clientdata.js'));
        Assert::same('', PageAssets::adminFooter(['filename' => 'invoices']));

        Settings::save(['client_forms' => false]);
        Assert::same('', PageAssets::clientFooter(['templatefile' => 'clientregister', 'WEB_ROOT' => '']));
    },
    'the script configuration cannot break out of the script tag' => static function (): void {
        $config = PageAssets::config(FormContext::REGISTER, Texts::for('romanian'));
        Assert::same(42, count($config['counties']));
        Assert::same('București', $config['bucharest']);
        Assert::true(str_contains($config['text']['cd_hint_state_invalid'], '%s'));
        $footer = PageAssets::clientFooter(['templatefile' => 'clientregister', 'WEB_ROOT' => '"></script><script>']);
        Assert::false(str_contains($footer, '"></script><script>'));
    },
    'client texts exist in both languages and the settings strings load in the admin' => static function (): void {
        foreach (['cd_error_county', 'cd_error_sector', 'cd_hint_state_invalid', 'cd_hint_sector_missing', 'cd_sector', 'cd_sector_choose'] as $key) {
            Assert::true(Texts::for('romanian')->get($key) !== $key, "romanian {$key}");
            Assert::true(Texts::for('english')->get($key) !== $key, "english {$key}");
        }
        Assert::same('Alegeți județul din listă.', Texts::for('romanian')->get('cd_error_county'));
        Assert::same(Texts::for('english')->get('cd_error_county'), Texts::for('german')->get('cd_error_county'));
        Lang::boot('romanian');
        Assert::same('Formulare client', Lang::get('section_client_forms'));
        Lang::boot('english');
    },
    'the settings form reads the client form options' => static function (): void {
        $form = new SettingsForm(new ClientDirectory());
        $values = $form->read(['client_forms' => '1', 'client_validation_new' => 'warn', 'client_validation_profile' => 'anything']);
        Assert::true($values['client_forms']);
        Assert::same('warn', $values['client_validation_new']);
        Assert::same('strict', $values['client_validation_profile']);
        Assert::false($form->read([])['client_forms']);
    },
];
