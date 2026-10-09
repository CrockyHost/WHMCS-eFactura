<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

namespace WHMCS\Module\Addon\Efactura\Admin;

use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\ClientDirectory;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * Builds the sections and fields of the settings form, ready for the
 * generic template (templates/admin/settings.tpl).
 */
final class SettingsPage
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    public function __construct(
        private readonly array $values,
        private readonly array $errors,
        private readonly SettingsForm $form,
        private readonly ClientDirectory $directory,
        private readonly InvoicingConfig $invoicing,
    ) {
    }

    /**
     * @return list<array{title: string, intro: string, fields: list<array<string, mixed>>}>
     */
    public function sections(): array
    {
        $counties = ['' => Lang::get('select_choose')] + Counties::sortedByName();
        $delays = [];
        for ($days = 0; $days <= Settings::MAX_SEND_DELAY_DAYS; $days++) {
            $delays[(string) $days] = Lang::get($days === 1 ? 'send_delay_one' : 'send_delay_many', $days);
        }
        $groups = $this->directory->groups();

        return [
            $this->section('section_general', [
                $this->checkbox('enabled'),
                $this->radio('environment', [Settings::ENV_TEST => Lang::get('env_test'), Settings::ENV_PROD => Lang::get('env_prod')]),
                $this->select('ui_language', ['auto' => Lang::get('language_auto'), 'romanian' => 'Română', 'english' => 'English']),
            ]),
            $this->section('section_company', [
                $this->text('company_legal_name', 200),
                $this->text('company_trade_name', 200),
                $this->text('company_cui', 12),
                $this->checkbox('company_vat_payer'),
                $this->checkbox('company_vat_on_collection'),
                $this->text('company_reg_com', 100),
                $this->text('company_share_capital', 100),
                $this->text('company_street', 150),
                $this->text('company_city', 50),
                $this->select('company_county', $counties),
                $this->text('company_postcode', 20),
                $this->text('company_contact_name', 100),
                $this->text('company_phone', 100),
                $this->text('company_email', 100),
            ]),
            $this->section('section_bank', [
                $this->text('bank_name', 200),
                $this->text('iban_ron', 34),
                $this->text('iban_eur', 34),
                $this->text('bank_bic', 11),
            ]),
            $this->section('section_numbering', [
                $this->info('numbering_proforma', $this->invoicing->proformaNumbering()
                    ? $this->invoicing->proformaFormat()
                    : Lang::get('numbering_proforma_none')),
                $this->info('numbering_fiscal', $this->invoicing->fiscalFormat()),
                $this->info('numbering_next', InvoicingConfig::format($this->invoicing->fiscalFormat(), $this->invoicing->fiscalCounter())),
            ]),
            $this->section('section_sending', [
                $this->select('send_delay_days', $delays),
            ]),
            $this->section('section_early_issue', [
                $groups === []
                    ? $this->info('early_issue_groups', Lang::get('no_client_groups'))
                    : $this->checklist('early_issue_groups', $groups),
                $this->text('early_issue_clients', 0, implode(', ', $this->values['early_issue_clients'])),
            ]),
            $this->section('section_client_fields', [
                $this->select('client_field_cui', $this->form->fieldChoices('tax_id', false)),
                $this->select('client_field_regcom', $this->form->fieldChoices(null, true)),
                $this->select('client_field_cnp', $this->form->fieldChoices(null, true)),
                $this->select('client_field_county', $this->form->fieldChoices('state', false)),
            ]),
            $this->section('section_exclusions', [
                $this->checkbox('exclude_eu_reverse_charge'),
                $this->checkbox('exclude_non_eu'),
                $this->checkbox('exclude_zero_total'),
                $this->checkbox('exclude_add_funds'),
                $this->info('exclude_mass_pay', Lang::get('exclude_mass_pay_always')),
            ]),
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array{title: string, intro: string, fields: list<array<string, mixed>>}
     */
    private function section(string $key, array $fields): array
    {
        return [
            'title' => Lang::get($key),
            'intro' => Lang::has($key . '_intro') ? Lang::get($key . '_intro') : '',
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(string $type, string $name): array
    {
        return [
            'type' => $type,
            'name' => $name,
            'id' => 'efactura-' . str_replace('_', '-', $name),
            'label' => Lang::get('setting_' . $name),
            'help' => Lang::has('help_' . $name) ? Lang::get('help_' . $name) : '',
            'error' => $this->errors[$name] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $name, int $maxLength, ?string $value = null): array
    {
        return $this->base('text', $name) + [
            'value' => $value ?? (string) $this->values[$name],
            'maxlength' => $maxLength,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkbox(string $name): array
    {
        return $this->base('checkbox', $name) + ['checked' => (bool) $this->values[$name]];
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function select(string $name, array $options): array
    {
        return $this->base('select', $name) + ['options' => $this->options($options, [(string) $this->values[$name]])];
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     */
    private function radio(string $name, array $options): array
    {
        return $this->base('radio', $name) + ['options' => $this->options($options, [(string) $this->values[$name]])];
    }

    /**
     * @param array<int, string> $options
     * @return array<string, mixed>
     */
    private function checklist(string $name, array $options): array
    {
        $selected = array_map('strval', (array) $this->values[$name]);

        return $this->base('checklist', $name) + ['options' => $this->options($options, $selected)];
    }

    /**
     * @return array<string, mixed>
     */
    private function info(string $name, string $value): array
    {
        return $this->base('info', $name) + ['value' => $value];
    }

    /**
     * @param array<int|string, string> $options
     * @param list<string> $selected
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function options(array $options, array $selected): array
    {
        $list = [];
        foreach ($options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => $label, 'selected' => in_array((string) $value, $selected, true)];
        }

        return $list;
    }
}
