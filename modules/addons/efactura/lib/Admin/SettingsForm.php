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

use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Iban;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\ClientDirectory;

/**
 * Reads the settings form, normalizes the values and validates them.
 * Error messages are keyed by setting name.
 */
final class SettingsForm
{
    private const BOOLEANS = [
        'enabled',
        'company_vat_payer',
        'company_vat_on_collection',
        'exclude_eu_reverse_charge',
        'exclude_non_eu',
        'exclude_zero_total',
        'exclude_add_funds',
    ];

    /** Maximum lengths from CIUS-RO (BR-RO-xxx) and EN 16931. */
    private const MAX_LENGTHS = [
        'company_legal_name' => 200,
        'company_trade_name' => 200,
        'company_reg_com' => 100,
        'company_share_capital' => 100,
        'company_street' => 150,
        'company_city' => 50,
        'company_postcode' => 20,
        'company_contact_name' => 100,
        'company_phone' => 100,
        'company_email' => 100,
        'bank_name' => 200,
    ];

    /** Seller fields that must be filled in before processing can be enabled. */
    public const REQUIRED_COMPANY_FIELDS = [
        'company_legal_name',
        'company_cui',
        'company_street',
        'company_city',
        'company_county',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly ClientDirectory $directory)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed> normalized values for every setting
     */
    public function read(array $post): array
    {
        $values = [];
        foreach (Settings::names() as $name) {
            if (in_array($name, self::BOOLEANS, true)) {
                $values[$name] = ($post[$name] ?? '0') === '1';
            } elseif ($name === 'early_issue_groups') {
                $values[$name] = array_values(array_unique(array_map('intval', (array) ($post[$name] ?? []))));
            } elseif ($name === 'early_issue_clients') {
                $values[$name] = self::parseIds((string) ($post[$name] ?? ''));
            } elseif ($name === 'send_delay_days') {
                $values[$name] = (int) ($post[$name] ?? 0);
            } else {
                $values[$name] = trim((string) ($post[$name] ?? ''));
            }
        }

        $values['company_cui'] = Cui::normalize($values['company_cui']);
        $values['company_city'] = self::normalizeSector($values['company_city'], $values['company_county']);
        $values['iban_ron'] = Iban::normalize($values['iban_ron']);
        $values['iban_eur'] = Iban::normalize($values['iban_eur']);
        $values['bank_bic'] = strtoupper(str_replace(' ', '', $values['bank_bic']));

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @param bool $systemReady whether the blocking system checks pass (HealthChecks::systemReady)
     * @return array<string, string> errors by setting name (empty when valid)
     */
    public function validate(array $values, bool $systemReady): array
    {
        $this->errors = [];

        $this->expectChoice($values, 'environment', [Settings::ENV_TEST, Settings::ENV_PROD]);
        $this->expectChoice($values, 'ui_language', ['auto', 'romanian', 'english']);

        foreach (self::MAX_LENGTHS as $name => $max) {
            if (mb_strlen((string) $values[$name]) > $max) {
                $this->errors[$name] = Lang::get('error_too_long', $max);
            }
        }

        if ($values['company_cui'] !== '' && !Cui::isValid($values['company_cui'])) {
            $this->errors['company_cui'] = Lang::get('error_cui');
        }
        if ($values['company_county'] !== '' && !Counties::isValid($values['company_county'])) {
            $this->errors['company_county'] = Lang::get('error_county');
        }
        if ($values['company_county'] === Counties::BUCHAREST && !Counties::isSector($values['company_city'])) {
            $this->errors['company_city'] = Lang::get('error_sector');
        }
        if ($values['company_email'] !== '' && filter_var($values['company_email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['company_email'] = Lang::get('error_email');
        }
        foreach (['iban_ron', 'iban_eur'] as $name) {
            if ($values[$name] !== '' && !Iban::isValid($values[$name])) {
                $this->errors[$name] = Lang::get('error_iban');
            }
        }
        if ($values['bank_bic'] !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $values['bank_bic']) !== 1) {
            $this->errors['bank_bic'] = Lang::get('error_bic');
        }

        if ($values['send_delay_days'] < 0 || $values['send_delay_days'] > Settings::MAX_SEND_DELAY_DAYS) {
            $this->errors['send_delay_days'] = Lang::get('error_send_delay', Settings::MAX_SEND_DELAY_DAYS);
        }

        $unknownGroups = array_diff($values['early_issue_groups'], array_keys($this->directory->groups()));
        if ($unknownGroups !== []) {
            $this->errors['early_issue_groups'] = Lang::get('error_unknown_groups', implode(', ', $unknownGroups));
        }
        $unknownClients = array_diff($values['early_issue_clients'], $this->directory->existingClientIds($values['early_issue_clients']));
        if ($unknownClients !== []) {
            $this->errors['early_issue_clients'] = Lang::get('error_unknown_clients', implode(', ', $unknownClients));
        }

        $this->expectChoice($values, 'client_field_cui', array_keys($this->fieldChoices('tax_id', false)));
        $this->expectChoice($values, 'client_field_regcom', array_keys($this->fieldChoices(null, true)));
        $this->expectChoice($values, 'client_field_cnp', array_keys($this->fieldChoices(null, true)));
        $this->expectChoice($values, 'client_field_county', array_keys($this->fieldChoices('state', false)));

        if ($values['enabled']) {
            foreach (self::REQUIRED_COMPANY_FIELDS as $name) {
                if ($values[$name] === '' && !isset($this->errors[$name])) {
                    $this->errors[$name] = Lang::get('error_required_to_enable');
                }
            }
            if (!$systemReady) {
                $this->errors['enabled'] = Lang::get('error_system_not_ready');
            }
        }

        return $this->errors;
    }

    /**
     * Options for a "where does this client value come from" dropdown.
     *
     * @param string|null $native "tax_id", "state" or null when there is no native field
     * @return array<string, string> value => label
     */
    public function fieldChoices(?string $native, bool $allowNone): array
    {
        $choices = [];
        if ($allowNone) {
            $choices[''] = Lang::get('field_none');
        }
        if ($native !== null) {
            $choices[$native] = Lang::get('field_native_' . $native);
        }
        foreach ($this->directory->customFields() as $id => $name) {
            $choices['cf:' . $id] = Lang::get('field_custom', $name);
        }

        return $choices;
    }

    /**
     * Checks the stored seller details; used by the dashboard.
     *
     * @param array<string, mixed> $values
     * @return list<string> names of missing or invalid seller settings
     */
    public static function companyProblems(array $values): array
    {
        $problems = [];
        foreach (self::REQUIRED_COMPANY_FIELDS as $name) {
            if ((string) $values[$name] === '') {
                $problems[] = $name;
            }
        }
        if ($values['company_cui'] !== '' && !Cui::isValid((string) $values['company_cui'])) {
            $problems[] = 'company_cui';
        }
        if ($values['company_county'] === Counties::BUCHAREST && !Counties::isSector((string) $values['company_city'])) {
            $problems[] = 'company_city';
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<int>
     */
    private static function parseIds(string $text): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * "Sector 3", "sectorul 3" or "S3" become SECTOR3 for Bucharest addresses.
     */
    private static function normalizeSector(string $city, string $county): string
    {
        if ($county === Counties::BUCHAREST
            && preg_match('/^(?:sector(?:ul)?|s)\s*([1-6])$/i', $city, $match) === 1) {
            return 'SECTOR' . $match[1];
        }

        return $city;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     */
    private function expectChoice(array $values, string $name, array $allowed): void
    {
        if (!in_array((string) $values[$name], $allowed, true)) {
            $this->errors[$name] = Lang::get('error_invalid_choice');
        }
    }
}
