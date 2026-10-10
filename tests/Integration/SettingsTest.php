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
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Admin\SettingsForm;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\ClientDirectory;

/**
 * Valid form input; individual tests override fields.
 *
 * @return array<string, mixed>
 */
$validPost = static function (array $overrides = []): array {
    return $overrides + [
        'enabled' => '0',
        'environment' => 'test',
        'ui_language' => 'english',
        'company_legal_name' => 'TEST COMPANY S.R.L.',
        'company_cui' => 'RO 50515950',
        'company_vat_payer' => '1',
        'company_street' => 'Strada Test nr. 1',
        'company_city' => 'Sector 3',
        'company_county' => 'RO-B',
        'iban_ron' => 'RO49 AAAA 1B31 0075 9384 0000',
        'send_delay_days' => '2',
        'early_issue_groups' => [],
        'early_issue_clients' => '',
        'exclude_eu_reverse_charge' => '1',
        'exclude_non_eu' => '1',
        'exclude_zero_total' => '1',
        'exclude_add_funds' => '0',
    ];
};

Lang::boot('english');

return [
    'all migrations are applied' => static function (): void {
        Assert::same([], Addon::migrator()->pending());
        foreach (['settings', 'documents', 'archive', 'oauth', 'messages', 'audit', 'migrations'] as $table) {
            Assert::true(Capsule::schema()->hasTable('mod_efactura_' . $table), "mod_efactura_{$table} is missing");
        }
    },
    'missing settings fall back to defaults' => static function (): void {
        Capsule::table(Settings::TABLE)->delete();
        Settings::reset();
        Assert::same(false, Settings::get('enabled'));
        Assert::same('test', Settings::environment());
        Assert::same(1, Settings::int('send_delay_days'));
        Assert::same([], Settings::intList('early_issue_groups'));
    },
    'settings round-trip with their types' => static function (): void {
        Settings::save(['enabled' => true, 'send_delay_days' => 3, 'early_issue_clients' => ['5', 7], 'company_legal_name' => '  X S.R.L. ']);
        Assert::same(true, Settings::get('enabled'));
        Assert::same(3, Settings::get('send_delay_days'));
        Assert::same([5, 7], Settings::get('early_issue_clients'));
        Assert::same('X S.R.L.', Settings::get('company_legal_name'));
    },
    'unknown settings are rejected' => static function (): void {
        Assert::throws(InvalidArgumentException::class, static fn () => Settings::save(['nope' => 1]));
        Assert::throws(InvalidArgumentException::class, static fn () => Settings::get('nope'));
    },
    'form normalizes CUI, IBAN and Bucharest sector' => static function () use ($validPost): void {
        $form = new SettingsForm(new ClientDirectory());
        $values = $form->read($validPost());
        Assert::same('50515950', $values['company_cui']);
        Assert::same('SECTOR3', $values['company_city']);
        Assert::same('RO49AAAA1B31007593840000', $values['iban_ron']);
        Assert::same([], $form->validate($values, true));
    },
    'form rejects invalid values' => static function () use ($validPost): void {
        $form = new SettingsForm(new ClientDirectory());
        $values = $form->read($validPost([
            'company_cui' => '50515951',
            'company_city' => 'Bucuresti',
            'iban_ron' => 'RO00INVALID',
            'send_delay_days' => '4',
            'early_issue_groups' => ['999999'],
            'early_issue_clients' => '999999999',
            'environment' => 'live',
        ]));
        $errors = $form->validate($values, true);
        foreach (['company_cui', 'company_city', 'iban_ron', 'send_delay_days', 'early_issue_groups', 'early_issue_clients', 'environment'] as $name) {
            Assert::true(isset($errors[$name]), "expected an error for {$name}");
        }
    },
    'processing cannot be enabled without seller data or WHMCS numbering' => static function () use ($validPost): void {
        $form = new SettingsForm(new ClientDirectory());
        $values = $form->read($validPost(['enabled' => '1', 'company_legal_name' => '']));
        $errors = $form->validate($values, false);
        Assert::true(isset($errors['company_legal_name']));
        Assert::true(isset($errors['enabled']));

        $values = $form->read($validPost(['enabled' => '1']));
        Assert::same([], $form->validate($values, true));
    },
];
