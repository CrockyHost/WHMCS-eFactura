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
 * Reads the values of the addon's client fields (CUI, trade register
 * number, CNP): from a submitted form or as stored for a client. The VAT
 * number of VAT payers is the native WHMCS tax_id (validated against VIES),
 * the county the native State/Region field.
 */
final class FieldMap
{
    public const ROLES = ClientFields::ROLES;

    /**
     * @param array<string, int> $ids role => custom field id
     */
    public function __construct(private readonly array $ids)
    {
    }

    public static function load(): self
    {
        return new self(ClientFields::ids());
    }

    public function id(string $role): ?int
    {
        return $this->ids[$role] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->ids;
    }

    /**
     * The submitted value of a role from the client form data: "customfield"
     * (client area forms) keyed by field id. The data must already be
     * unescaped (Support\Input).
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
        $values = $this->ids === [] ? [] : Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->whereIn('fieldid', array_values($this->ids))
            ->pluck('value', 'fieldid')
            ->all();

        $stored = [];
        foreach (self::ROLES as $role) {
            $id = $this->id($role);
            $stored[$role] = $id !== null ? self::text($values[$id] ?? '') : '';
        }

        return $stored;
    }

    /**
     * A client value as WHMCS stored it (HTML-escaped: "H&amp;M"), as text.
     */
    public static function text(mixed $value): string
    {
        return trim(htmlspecialchars_decode((string) $value, ENT_QUOTES));
    }
}
