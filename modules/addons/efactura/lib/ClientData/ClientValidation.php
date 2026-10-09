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

/**
 * ClientDetailsValidation and ContactDetailsValidation for the client area
 * forms. In strict mode the problems block the form; in warning mode the
 * form is saved and the browser shows the same problems next to the fields.
 */
final class ClientValidation
{
    private const ADDRESS_FIELDS = ['country', 'state', 'city', 'address1', 'address2'];

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

        return self::run($vars, $context, $context === FormContext::PROFILE ? self::currentClientId() : null);
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

        return self::run($vars, $context, null);
    }

    /**
     * @param array<string, mixed> $vars
     * @return list<string>
     */
    private static function run(array $vars, string $context, ?int $clientId): array
    {
        if (FormContext::mode($context) !== FormContext::STRICT) {
            return [];
        }

        [$address, $locked] = self::effectiveAddress($vars, $context === FormContext::PROFILE ? $clientId : null);
        $texts = Texts::client();
        $errors = [];
        foreach (AddressRules::check($address, $locked) as $issue) {
            // WHMCS itself reports an empty required field; one message is enough.
            if ($issue->blocking && !self::reportedByWhmcs($issue, $address)) {
                $errors[] = $texts->get($issue->key);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $address
     */
    private static function reportedByWhmcs(Issue $issue, array $address): bool
    {
        if (($address[$issue->field] ?? '') !== '') {
            return false;
        }
        $optional = array_map('trim', explode(',', strtolower((string) \WHMCS\Config\Setting::getValue('ClientsProfileOptionalFields'))));

        return !in_array($issue->field, $optional, true);
    }

    /**
     * The address WHMCS will save: the submitted values, except fields the
     * client cannot change (locked in the profile or not submitted), which
     * keep the stored value.
     *
     * @param array<string, mixed> $vars
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function effectiveAddress(array $vars, ?int $clientId): array
    {
        $locked = $clientId !== null ? FormContext::lockedProfileFields() : [];
        $stored = $clientId !== null ? self::storedAddress($clientId) : [];
        $address = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $submitted = array_key_exists($field, $vars) && is_scalar($vars[$field]);
            if ($clientId !== null && (!$submitted || in_array($field, $locked, true))) {
                $address[$field] = $stored[$field] ?? '';
                $locked[] = $field;
            } else {
                $address[$field] = $submitted ? trim((string) $vars[$field]) : '';
            }
        }

        return [$address, array_values(array_unique($locked))];
    }

    /**
     * @return array<string, string>
     */
    private static function storedAddress(int $clientId): array
    {
        $row = Capsule::table('tblclients')->where('id', $clientId)->first(self::ADDRESS_FIELDS);
        if ($row === null) {
            return [];
        }

        return array_map(static fn ($value): string => (string) $value, (array) $row);
    }

    private static function currentClientId(): ?int
    {
        $id = (int) ($_SESSION['uid'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
