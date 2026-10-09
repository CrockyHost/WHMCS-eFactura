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
use WHMCS\Module\Addon\Efactura\Romania\Cnp;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * Makes sure the CUI, the trade register number and the CNP each have a
 * client custom field mapped in the settings. A setting that already points
 * to a custom field is kept. Otherwise an existing text field whose values
 * are mostly of that kind (for Romanian clients) is mapped, and only when
 * there is none a new field is created. Runs once, as a migration.
 */
final class FieldProvisioner
{
    /** A field is taken when at least this many values and this share of them match. */
    private const MIN_MATCHES = 2;
    private const MIN_SHARE = 0.6;

    /** Fields the addon creates: name, description, shown on invoices. */
    private const NEW_FIELDS = [
        'cui' => ['CUI / Company ID', 'Romanian companies: CUI (fiscal code) without RO. Other countries: company registration number.', true],
        'regcom' => ['Nr. Reg. Com.', 'Trade register number of a Romanian company (ONRC).', true],
        'cnp' => ['CNP', 'Personal numeric code of a Romanian individual (optional).', false],
    ];

    /**
     * @return array<string, array{id: int, action: string}> role => mapped field and
     *         what was done: "kept", "detected" or "created"
     */
    public static function provision(): array
    {
        $result = [];
        $used = [];
        foreach (FieldMap::ROLES as $role) {
            $id = FieldMap::customFieldId(Settings::string('client_field_' . $role));
            if ($id !== null && self::exists($id)) {
                $result[$role] = ['id' => $id, 'action' => 'kept'];
                $used[] = $id;
            }
        }

        foreach (FieldMap::ROLES as $role) {
            if (isset($result[$role])) {
                continue;
            }
            $id = self::detect($role, $used);
            $action = 'detected';
            if ($id === null) {
                $id = self::create($role);
                $action = 'created';
            }
            $used[] = $id;
            $result[$role] = ['id' => $id, 'action' => $action];
            Settings::save(['client_field_' . $role => 'cf:' . $id]);
            if (function_exists('logActivity')) {
                logActivity(sprintf('WHMCS-eFactura: client field "%s" %s as custom field #%d', $role, $action, $id));
            }
        }

        return $result;
    }

    /**
     * The text field whose values, for Romanian clients, are mostly of the
     * kind of the role.
     *
     * @param list<int> $exclude fields already mapped to another role
     */
    public static function detect(string $role, array $exclude = []): ?int
    {
        $rows = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->join('tblclients as c', 'c.id', '=', 'v.relid')
            ->where('f.type', 'client')
            ->where('f.fieldtype', 'text')
            ->where('c.country', 'RO')
            ->where('v.value', '<>', '')
            ->when($exclude !== [], static fn ($query) => $query->whereNotIn('f.id', $exclude))
            ->get(['v.fieldid', 'v.value']);

        $stats = [];
        foreach ($rows as $row) {
            $id = (int) $row->fieldid;
            $stats[$id] ??= ['values' => 0, 'matches' => 0];
            $stats[$id]['values']++;
            if (self::matches($role, trim((string) $row->value))) {
                $stats[$id]['matches']++;
            }
        }

        $best = null;
        foreach ($stats as $id => $stat) {
            if ($stat['matches'] < self::MIN_MATCHES || $stat['matches'] / $stat['values'] < self::MIN_SHARE) {
                continue;
            }
            if ($best === null || $stat['matches'] > $stats[$best]['matches']) {
                $best = $id;
            }
        }

        return $best;
    }

    public static function matches(string $role, string $value): bool
    {
        return match ($role) {
            'cui' => Cui::isValid($value),
            'regcom' => RegCom::isValid($value),
            'cnp' => Cnp::isValid($value),
            default => false,
        };
    }

    private static function create(string $role): int
    {
        [$name, $description, $onInvoice] = self::NEW_FIELDS[$role];
        $now = date('Y-m-d H:i:s');
        $order = (int) Capsule::table('tblcustomfields')->where('type', 'client')->max('sortorder');

        return (int) Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'client',
            'relid' => 0,
            'fieldname' => $name,
            'fieldtype' => 'text',
            'description' => $description,
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => '',
            'required' => '',
            'showorder' => 'on',
            'showinvoice' => $onInvoice ? 'on' : '',
            'sortorder' => $order + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function exists(int $id): bool
    {
        return Capsule::table('tblcustomfields')->where('id', $id)->where('type', 'client')->exists();
    }
}
