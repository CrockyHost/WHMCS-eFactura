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
use WHMCS\Module\Addon\Efactura\ClientData\ClientFields;
use WHMCS\Module\Addon\Efactura\ClientData\ClientSummary;
use WHMCS\Module\Addon\Efactura\ClientData\FormContext;
use WHMCS\Module\Addon\Efactura\ClientData\PageAssets;
use WHMCS\Module\Addon\Efactura\ClientData\Texts;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Ubl\BuyerMapper;
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

/**
 * The client fields the addon owns (created if missing).
 *
 * @return array<string, int>
 */
$mapFields = static fn (): array => ClientFields::ids();

/**
 * @param array<string, int> $ids
 * @param array<string, string> $values role => value
 */
$setFields = static function (int $clientId, array $ids, array $values): void {
    foreach ($values as $role => $value) {
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(['fieldid' => $ids[$role], 'relid' => $clientId], ['value' => $value]);
    }
};

/**
 * Runs a callback as the logged-in client, with the WHMCS locked profile fields given.
 */
$asClient = static function (int $clientId, string $lockedFields, callable $callback): void {
    $previousUid = $_SESSION['uid'] ?? null;
    $previousLocked = (string) \WHMCS\Config\Setting::getValue('ClientsProfileUneditableFields');
    $_SESSION['uid'] = $clientId;
    \WHMCS\Config\Setting::setValue('ClientsProfileUneditableFields', $lockedFields);
    try {
        $callback();
    } finally {
        \WHMCS\Config\Setting::setValue('ClientsProfileUneditableFields', $previousLocked);
        if ($previousUid === null) {
            unset($_SESSION['uid']);
        } else {
            $_SESSION['uid'] = $previousUid;
        }
    }
};

$english = Texts::for('english');

return [
    'registration: a company without CUI is refused even in warning mode' => static function () use ($mapFields, $english): void {
        $ids = $mapFields();
        $vars = ['country' => 'RO', 'state' => 'Cluj', 'city' => 'Cluj-Napoca', 'companyname' => 'X SRL', 'tax_id' => '', 'customfield' => [$ids['cui'] => '']];
        Settings::save(['client_validation_new' => 'strict']);
        Assert::same([$english->get('cd_error_cui_required')], ClientValidation::client($vars, FormContext::REGISTER));
        Settings::save(['client_validation_new' => 'warn']);
        Assert::same([$english->get('cd_error_cui_required')], ClientValidation::client($vars, FormContext::REGISTER));
        // A wrong check digit only warns in that mode.
        $vars['customfield'][$ids['cui']] = '50515951';
        Assert::same([], ClientValidation::client($vars, FormContext::REGISTER));
    },
    'registration: the chosen client type decides the rules' => static function () use ($mapFields, $english): void {
        $ids = $mapFields();
        $base = ['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova', 'tax_id' => ''];
        $company = $base + ['companyname' => 'TEST SRL', 'customfield' => [$ids['cui'] => '50515950', $ids['regcom'] => 'J2024020698007', $ids['cnp'] => '']];
        Assert::same([], ClientValidation::client($company + [ClientValidation::TYPE_FIELD => 'company'], FormContext::REGISTER));
        Assert::same([], ClientValidation::client(['tax_id' => 'RO50515950'] + $company, FormContext::REGISTER));
        Assert::same(
            [$english->get('cd_error_vat_mismatch')],
            ClientValidation::client(['tax_id' => 'RO14399840'] + $company, FormContext::REGISTER)
        );
        $person = $base + ['companyname' => '', 'customfield' => [$ids['cui'] => '', $ids['cnp'] => '1960131410045']];
        Assert::same([$english->get('cd_error_cnp')], ClientValidation::client($person + [ClientValidation::TYPE_FIELD => 'person'], FormContext::CHECKOUT));
        $person['customfield'][$ids['cnp']] = '1960131410041';
        Assert::same([], ClientValidation::client($person + [ClientValidation::TYPE_FIELD => 'person'], FormContext::CHECKOUT));
        Assert::same(
            [$english->get('cd_error_person_company')],
            ClientValidation::client(['companyname' => 'X SRL', ClientValidation::TYPE_FIELD => 'person'] + $person, FormContext::CHECKOUT)
        );
    },
    'profile: the billing identity cannot be changed, an untouched form saves' => static function () use ($fakeClient, $mapFields, $setFields, $asClient, $english): void {
        $ids = $mapFields();
        Settings::save(['client_profile_lock' => true, 'client_validation_profile' => 'strict']);
        $clientId = $fakeClient(['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'TEST SRL', 'tax_id' => '']);
        // Stored data a client cannot fix (no CUI) must not block the save.
        $setFields($clientId, $ids, ['cui' => '', 'regcom' => 'bad', 'cnp' => '']);
        $asClient($clientId, 'companyname,country,tax_id', static function () use ($ids, $english): void {
            $form = ['state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'TEST SRL', 'customfield' => [$ids['cui'] => '', $ids['regcom'] => 'bad', $ids['cnp'] => '']];
            Assert::same([], ClientValidation::client($form, FormContext::PROFILE));
            $changed = $form;
            $changed['customfield'][$ids['cui']] = '50515950';
            Assert::same([$english->get('cd_error_locked')], ClientValidation::client($changed, FormContext::PROFILE));
        });
        // Company name not locked by WHMCS: changing it is refused by the addon lock.
        $asClient($clientId, 'country', static function () use ($ids, $english): void {
            $form = ['state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'OTHER SRL', 'tax_id' => '', 'customfield' => [$ids['cui'] => '', $ids['regcom'] => 'bad', $ids['cnp'] => '']];
            Assert::same([$english->get('cd_error_locked')], ClientValidation::client($form, FormContext::PROFILE));
        });
        // Without the addon lock, the client fixes the data and the rules apply.
        Settings::save(['client_profile_lock' => false]);
        $asClient($clientId, 'country', static function () use ($ids, $english): void {
            $form = ['state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'TEST SRL', 'tax_id' => '', 'customfield' => [$ids['cui'] => '', $ids['regcom'] => '', $ids['cnp'] => '']];
            Assert::same([$english->get('cd_error_cui_required')], ClientValidation::client($form, FormContext::PROFILE));
            $form['customfield'][$ids['cui']] = '50515950';
            Assert::same([], ClientValidation::client($form, FormContext::PROFILE));
        });
    },
    'the addon owns its client fields and creates a missing one again' => static function (): void {
        $ids = ClientFields::ids();
        Assert::same(['cui', 'regcom', 'cnp'], array_keys($ids));
        $cui = Capsule::table('tblcustomfields')->where('id', $ids['cui'])->first();
        Assert::same('CUI (Romanian fiscal code)', $cui->fieldname);
        Assert::same('on', $cui->showorder);
        Assert::same('', (string) Capsule::table('tblcustomfields')->where('id', $ids['cnp'])->value('showinvoice'));
        Assert::same('CUI (cod fiscal)', (string) Capsule::table('tbldynamic_translations')
            ->where('related_type', 'custom_field.{id}.name')->where('related_id', $ids['cui'])->where('language', 'romanian')->value('translation'));

        // An admin deletes the CNP field: it comes back, with a new id.
        Capsule::table('tblcustomfields')->where('id', $ids['cnp'])->delete();
        ClientFields::reset();
        $again = ClientFields::ids();
        Assert::true($again['cnp'] !== $ids['cnp']);
        Assert::same($ids['cui'], $again['cui']);
        Assert::same('CNP (Romanian personal code)', (string) Capsule::table('tblcustomfields')->where('id', $again['cnp'])->value('fieldname'));
        ClientFields::reset();
    },
    'the CUI is shown on invoices as the setting says, also when the field is created again' => static function (): void {
        $showInvoice = static fn (): string => (string) Capsule::table('tblcustomfields')->where('id', ClientFields::id('cui'))->value('showinvoice');
        Settings::save(['client_cui_on_invoice' => false]);
        Assert::same('', $showInvoice());
        Settings::save(['client_cui_on_invoice' => true]);
        Assert::same('on', $showInvoice());

        Settings::save(['client_cui_on_invoice' => false]);
        Capsule::table('tblcustomfields')->where('id', ClientFields::id('cui'))->delete();
        ClientFields::reset();
        Assert::same('', $showInvoice());
        ClientFields::reset();

        $form = new SettingsForm(new ClientDirectory());
        Assert::true($form->read(['client_cui_on_invoice' => '1'])['client_cui_on_invoice']);
        Assert::false($form->read([])['client_cui_on_invoice']);
    },
    'the trade register number is shown on invoices as its own setting says' => static function (): void {
        $showInvoice = static fn (string $role): string => (string) Capsule::table('tblcustomfields')->where('id', ClientFields::id($role))->value('showinvoice');
        Settings::save(['client_regcom_on_invoice' => false, 'client_cui_on_invoice' => true]);
        Assert::same('', $showInvoice('regcom'));
        Assert::same('on', $showInvoice('cui'));
        Settings::save(['client_regcom_on_invoice' => true]);
        Assert::same('on', $showInvoice('regcom'));
        // The CNP has no setting and stays off the invoice.
        Assert::same('', $showInvoice('cnp'));

        Settings::save(['client_regcom_on_invoice' => false]);
        Capsule::table('tblcustomfields')->where('id', ClientFields::id('regcom'))->delete();
        ClientFields::reset();
        Assert::same('', $showInvoice('regcom'));
        ClientFields::reset();
        $form = new SettingsForm(new ClientDirectory());
        Assert::false($form->read([])['client_regcom_on_invoice']);
    },
    'buyer mapping reads the VAT number from tax_id and old CNPs from the CUI field' => static function () use ($fakeClient, $mapFields, $setFields): void {
        $ids = $mapFields();
        $payer = $fakeClient(['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'PAYER SRL', 'tax_id' => 'RO50515950']);
        $setFields($payer, $ids, ['cui' => '50515950']);
        $result = (new BuyerMapper())->map($payer);
        Assert::same('b2b', $result['type']);
        Assert::same('RO50515950', $result['party']->vatId);
        Assert::same('50515950', $result['party']->legalId);

        $nonPayer = $fakeClient(['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'NONPAYER SRL', 'tax_id' => '']);
        $setFields($nonPayer, $ids, ['cui' => '14399840']);
        Assert::same(null, (new BuyerMapper())->map($nonPayer)['party']->vatId);

        $oldPerson = $fakeClient(['country' => 'RO', 'state' => 'Vaslui', 'city' => 'Vaslui']);
        $setFields($oldPerson, $ids, ['cui' => '1960131410041']);
        $result = (new BuyerMapper())->map($oldPerson);
        Assert::same('b2c', $result['type']);
        Assert::same('1960131410041', $result['party']->legalId);

        $eu = $fakeClient(['country' => 'DE', 'state' => 'Bayern', 'city' => 'München', 'companyname' => 'X GmbH', 'tax_id' => 'DE123456789']);
        Assert::same('DE123456789', (new BuyerMapper())->map($eu)['party']->vatId);
    },
    'the admin client summary shows CUI and Reg. Com. for a company, the CNP for an individual' => static function () use ($fakeClient, $mapFields, $setFields): void {
        $ids = $mapFields();
        $texts = Texts::for('romanian');
        $company = $fakeClient(['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova', 'companyname' => 'TEST SRL']);
        $setFields($company, $ids, ['cui' => '50515950', 'regcom' => 'J2024020698007']);
        Assert::same([['CUI', '50515950'], ['Nr. Reg. Com.', 'J2024020698007']], ClientSummary::rows($company, $texts));

        $person = $fakeClient(['country' => 'RO', 'state' => 'Dolj', 'city' => 'Craiova']);
        Assert::same([], ClientSummary::rows($person, $texts));
        $setFields($person, $ids, ['cnp' => '1960131410041']);
        Assert::same([['CNP', '1960131410041']], ClientSummary::rows($person, $texts));

        $foreign = $fakeClient(['country' => 'DE', 'state' => 'Bayern', 'city' => 'München', 'companyname' => 'X GmbH']);
        Assert::same([], ClientSummary::rows($foreign, $texts));
        Assert::same([], ClientSummary::rows(0, $texts));
    },
    'the script gets the field mapping and the profile lock' => static function () use ($mapFields): void {
        $ids = $mapFields();
        Settings::save(['client_forms' => true, 'client_profile_lock' => true]);
        $register = PageAssets::config(FormContext::REGISTER, Texts::for('english'));
        Assert::true($register['identity']);
        Assert::false($register['lock']);
        Assert::same($ids['cui'], $register['fields']['cui']);
        $profile = PageAssets::config(FormContext::PROFILE, Texts::for('english'));
        Assert::true($profile['lock']);
        Assert::false(PageAssets::config(FormContext::CONTACT, Texts::for('english'))['identity']);
    },
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
