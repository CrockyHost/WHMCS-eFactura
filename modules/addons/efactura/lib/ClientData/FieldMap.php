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
 * The client custom fields that hold the CUI, the trade register number and
 * the CNP, as mapped in the settings ("cf:<id>"). The forms need custom
 * fields: the native VAT number (tax_id) is validated by WHMCS against VIES
 * and only holds RO + CUI for VAT payers.
 */
final class FieldMap
{
    public const ROLES = ['cui', 'regcom', 'cnp'];

    /**
     * @param array<string, int|null> $ids role => custom field id
     */
    public function __construct(private readonly array $ids)
    {
    }

    public static function fromSettings(): self
    {
        $wanted = [];
        foreach (self::ROLES as $role) {
            $wanted[$role] = self::customFieldId(Settings::string('client_field_' . $role));
        }
        $existing = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->whereIn('id', array_values(array_filter($wanted)))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $ids = [];
        foreach ($wanted as $role => $id) {
            $ids[$role] = $id !== null && in_array($id, $existing, true) ? $id : null;
        }

        return new self($ids);
    }

    /**
     * The id in a "cf:<id>" setting value, or null.
     */
    public static function customFieldId(string $setting): ?int
    {
        return preg_match('/^cf:(\d+)$/', $setting, $match) === 1 ? (int) $match[1] : null;
    }

    public function id(string $role): ?int
    {
        return $this->ids[$role] ?? null;
    }

    /**
     * @return array<string, int|null>
     */
    public function all(): array
    {
        return $this->ids;
    }

    /**
     * The submitted value of a role from the client form data: "customfield"
     * (client area forms) keyed by field id.
     *
     * @param array<string, mixed> $vars
     */
    public function submitted(array $vars, string $role): ?string
    {
        $id = $this->id($role);
        $values = $vars['customfield'] ?? null;
        if ($id === null || !is_array($values) || !array_key_exists($id, $values) || !is_scalar($values[$id])) {
            return null;
        }

        return trim((string) $values[$id]);
    }

    /**
     * @return array<string, string> role => stored value for a client
     */
    public function stored(int $clientId): array
    {
        $ids = array_filter($this->ids);
        $values = $ids === [] ? [] : Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->whereIn('fieldid', array_values($ids))
            ->pluck('value', 'fieldid')
            ->all();

        $stored = [];
        foreach ($this->ids as $role => $id) {
            $stored[$role] = $id !== null ? trim((string) ($values[$id] ?? '')) : '';
        }

        return $stored;
    }
}
