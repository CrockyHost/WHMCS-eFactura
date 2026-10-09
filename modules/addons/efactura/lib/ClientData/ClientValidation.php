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
use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * ClientDetailsValidation and ContactDetailsValidation for the client area
 * forms. In strict mode the problems block the form; in warning mode the
 * form is saved and the browser shows the same problems next to the fields.
 * A company without CUI is always refused.
 */
final class ClientValidation
{
    private const ADDRESS_FIELDS = ['country', 'state', 'city', 'address1', 'address2'];

    /** Native client fields read for the identity rules. */
    private const IDENTITY_FIELDS = ['companyname', 'tax_id'];

    /** Name of the client type choice the forms post. */
    public const TYPE_FIELD = 'efactura_client_type';

    /**
     * @param array<string, mixed> $vars the submitted client details
     * @return list<string> error messages
     */
    public static function client(array $vars, ?string $context = null): array
    {
        $context ??= FormContext::current();
        if (!in_array($context, [FormContext::REGISTER, FormContext::CHECKOUT, FormContext::PROFILE], true)) {
            return [];
        }
        $mode = FormContext::mode($context);
        if ($mode === FormContext::OFF) {
            return [];
        }

        $clientId = $context === FormContext::PROFILE ? self::currentClientId() : null;
        $stored = $clientId !== null ? self::storedClient($clientId) : [];
        $locked = $clientId !== null ? FormContext::lockedProfileFields() : [];
        $data = [];
        foreach ([...self::ADDRESS_FIELDS, ...self::IDENTITY_FIELDS] as $field) {
            $submitted = array_key_exists($field, $vars) && is_scalar($vars[$field]);
            if ($clientId !== null && (!$submitted || in_array($field, $locked, true))) {
                // WHMCS keeps the stored value of a locked or missing field.
                $data[$field] = $stored[$field] ?? '';
                $locked[] = $field;
            } else {
                $data[$field] = $submitted ? trim((string) $vars[$field]) : '';
            }
        }

        $issues = AddressRules::check($data, $locked);
        $errors = [];

        $fields = FieldMap::fromSettings();
        if ($fields->id('cui') !== null) {
            $storedIds = $clientId !== null ? $fields->stored($clientId) : [];
            foreach (FieldMap::ROLES as $role) {
                $data[$role] = $fields->submitted($vars, $role) ?? ($storedIds[$role] ?? '');
            }
            $data['type'] = (string) ($vars[self::TYPE_FIELD] ?? $_POST[self::TYPE_FIELD] ?? '');

            if ($clientId !== null && Settings::bool('client_profile_lock')) {
                if (self::identityChanged($vars, $fields, $storedIds, $stored, $locked)) {
                    $errors[] = Texts::client()->get('cd_error_locked');
                }
                $locked = array_merge($locked, ['type', 'companyname', 'tax_id', ...FieldMap::ROLES]);
            }
            $issues = array_merge($issues, IdentityRules::check($data, $locked));
        }

        return self::messages($issues, $mode, $data, $errors);
    }

    /**
     * @param array<string, mixed> $vars the submitted contact details
     * @return list<string> error messages
     */
    public static function contact(array $vars, ?string $context = null): array
    {
        $context ??= FormContext::current();
        if ($context !== FormContext::CONTACT) {
            return [];
        }
        $mode = FormContext::mode($context);
        if ($mode === FormContext::OFF) {
            return [];
        }
        $address = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $address[$field] = is_scalar($vars[$field] ?? null) ? trim((string) $vars[$field]) : '';
        }

        return self::messages(AddressRules::check($address), $mode, $address, []);
    }

    /**
     * @param list<Issue> $issues
     * @param array<string, string> $data
     * @param list<string> $errors messages already decided
     * @return list<string>
     */
    private static function messages(array $issues, string $mode, array $data, array $errors): array
    {
        $texts = Texts::client();
        foreach ($issues as $issue) {
            // WHMCS itself reports an empty required field; one message is enough.
            if ($issue->blocks($mode) && !self::reportedByWhmcs($issue, $data)) {
                $errors[] = $texts->get($issue->key);
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Whether a profile submission changes the billing identity the client
     * is not allowed to change there (the form shows it read-only).
     *
     * @param array<string, mixed> $vars
     * @param array<string, string> $storedIds
     * @param array<string, string> $stored
     * @param list<string> $whmcsLocked
     */
    private static function identityChanged(array $vars, FieldMap $fields, array $storedIds, array $stored, array $whmcsLocked): bool
    {
        foreach (FieldMap::ROLES as $role) {
            $submitted = $fields->submitted($vars, $role);
            if ($submitted !== null && $submitted !== ($storedIds[$role] ?? '')) {
                return true;
            }
        }
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!in_array($field, $whmcsLocked, true)
                && is_scalar($vars[$field] ?? null)
                && trim((string) $vars[$field]) !== ($stored[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $data
     */
    private static function reportedByWhmcs(Issue $issue, array $data): bool
    {
        if ($issue->level === Issue::REQUIRED || !in_array($issue->field, self::ADDRESS_FIELDS, true) || ($data[$issue->field] ?? '') !== '') {
            return false;
        }
        $optional = array_map('trim', explode(',', strtolower((string) \WHMCS\Config\Setting::getValue('ClientsProfileOptionalFields'))));

        return !in_array($issue->field, $optional, true);
    }

    /**
     * @return array<string, string>
     */
    private static function storedClient(int $clientId): array
    {
        $row = Capsule::table('tblclients')->where('id', $clientId)->first([...self::ADDRESS_FIELDS, ...self::IDENTITY_FIELDS]);
        if ($row === null) {
            return [];
        }

        return array_map(static fn ($value): string => trim((string) $value), (array) $row);
    }

    private static function currentClientId(): ?int
    {
        $id = (int) ($_SESSION['uid'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
