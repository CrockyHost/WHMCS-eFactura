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

namespace WHMCS\Module\Addon\Efactura\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Lookups of WHMCS client metadata used by the settings: client custom
 * fields, client groups and client IDs.
 */
final class ClientDirectory
{
    /**
     * @return array<int, string> custom field id => display name
     */
    public function customFields(): array
    {
        $fields = [];
        $rows = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('sortorder')
            ->orderBy('id')
            ->get(['id', 'fieldname']);
        foreach ($rows as $row) {
            $fields[(int) $row->id] = self::displayName((string) $row->fieldname);
        }

        return $fields;
    }

    /**
     * @return array<int, string> group id => name
     */
    public function groups(): array
    {
        $groups = [];
        foreach (Capsule::table('tblclientgroups')->orderBy('groupname')->get(['id', 'groupname']) as $row) {
            $groups[(int) $row->id] = (string) $row->groupname;
        }

        return $groups;
    }

    /**
     * @param list<int> $ids
     * @return list<int> the IDs that belong to existing clients
     */
    public function existingClientIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Capsule::table('tblclients')->whereIn('id', $ids)->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * WHMCS custom field names may be "key|Display name".
     */
    private static function displayName(string $name): string
    {
        $position = strpos($name, '|');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
