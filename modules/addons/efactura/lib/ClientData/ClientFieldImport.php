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

/**
 * One-time copy of the CUI, trade register numbers and CNPs that clients
 * already have in other custom fields (for example "Registration Number"
 * and "Commerce Registry No. (RO)") into the fields the addon owns. The old
 * fields are neither deleted nor hidden: other modules may read them. What
 * was copied, and from where, is kept in mod_efactura_client_import for the
 * settings page.
 */
final class ClientFieldImport
{
    public const TABLE = 'mod_efactura_client_import';

    /** Settings of the old field mapping, removed with this import. */
    private const OLD_SETTINGS = ['client_field_cui', 'client_field_regcom', 'client_field_cnp', 'client_field_county'];

    /** A field is detected when at least this many values and this share of them match. */
    private const MIN_MATCHES = 2;
    private const MIN_SHARE = 0.6;

    /** How many client ids are kept for each list of the report. */
    private const REPORT_IDS = 50;

    /**
     * Fields the addon created before it owned them (migration 0101):
     * role => [name, description]. They are taken over, not imported.
     */
    private const EARLIER_FIELDS = [
        'cui' => ['CUI / Company ID', 'Romanian companies: CUI (fiscal code) without RO. Other countries: company registration number.'],
        'regcom' => ['Nr. Reg. Com.', 'Trade register number of a Romanian company (ONRC).'],
        'cnp' => ['CNP', 'Personal numeric code of a Romanian individual (optional).'],
    ];

    /**
     * @return list<array<string, mixed>> the report rows written
     */
    public static function run(): array
    {
        $old = self::oldMapping();
        foreach (self::EARLIER_FIELDS as $role => [$name, $description]) {
            $earlier = Capsule::table('tblcustomfields')
                ->where('type', 'client')->where('fieldname', $name)->where('description', $description)
                ->value('id');
            if ($earlier !== null) {
                ClientFields::adopt($role, (int) $earlier);
            }
        }
        $owned = ClientFields::ensure();

        $report = [];
        foreach (['cui', 'regcom', 'cnp'] as $role) {
            $source = $old[$role] ?? null;
            if ($source === null || in_array($source, $owned, true) || !self::isClientField($source)) {
                $source = self::detect($role, array_values($owned));
            }
            if ($source !== null && !in_array($source, $owned, true)) {
                $report = array_merge($report, self::copy($role, $source, $owned));
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($report as $row) {
            Capsule::table(self::TABLE)->insert([
                'source_field_id' => $row['source'],
                'source_name' => self::fieldName($row['source']),
                'target_role' => $row['target'],
                'imported' => $row['imported'],
                'unchanged' => $row['unchanged'],
                'conflicts' => json_encode(array_slice($row['conflicts'], 0, self::REPORT_IDS)),
                'conflict_count' => count($row['conflicts']),
                'invalid' => json_encode(array_slice($row['invalid'], 0, self::REPORT_IDS)),
                'invalid_count' => count($row['invalid']),
                'foreign_count' => $row['foreign'],
                'created_at' => $now,
            ]);
        }
        Capsule::table('mod_efactura_settings')->whereIn('name', self::OLD_SETTINGS)->delete();

        return $report;
    }

    /**
     * The text field whose values, for Romanian clients, are mostly of the
     * kind of the role.
     *
     * @param list<int> $exclude
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

    /**
     * Copies the values of one old field. Values of the CUI field that are
     * the CNP of an individual go to the CNP field. Only Romanian clients
     * are copied; existing different values are kept and reported.
     *
     * @param array<string, int> $owned
     * @return list<array{source: int, target: string, imported: int, unchanged: int, conflicts: list<int>, invalid: list<int>, foreign: int}>
     */
    private static function copy(string $role, int $source, array $owned): array
    {
        $rows = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblclients as c', 'c.id', '=', 'v.relid')
            ->where('v.fieldid', $source)
            ->where('v.value', '<>', '')
            ->orderBy('c.id')
            ->get(['c.id', 'c.country', 'c.companyname', 'v.value']);

        $results = [];
        $result = static function (string $target) use (&$results, $source): string {
            $results[$target] ??= ['source' => $source, 'target' => $target, 'imported' => 0, 'unchanged' => 0, 'conflicts' => [], 'invalid' => [], 'foreign' => 0];

            return $target;
        };
        $result($role);

        foreach ($rows as $row) {
            $clientId = (int) $row->id;
            $value = trim((string) $row->value);
            if (strtoupper(trim((string) $row->country)) !== 'RO') {
                $results[$role]['foreign']++;
                continue;
            }

            $target = $role;
            if ($role === 'cui' && trim((string) $row->companyname) === '' && Cnp::isValid($value)) {
                $target = $result('cnp');
                $value = Cnp::normalize($value);
            } elseif (!self::matches($role, $value)) {
                $results[$role]['invalid'][] = $clientId;
                continue;
            } else {
                $value = match ($role) {
                    'cui' => Cui::normalize($value),
                    'regcom' => RegCom::normalize($value),
                    default => Cnp::normalize($value),
                };
            }

            $current = trim((string) Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $owned[$target])->where('relid', $clientId)->value('value'));
            if ($current === $value) {
                $results[$target]['unchanged']++;
            } elseif ($current !== '') {
                $results[$target]['conflicts'][] = $clientId;
            } else {
                self::write($owned[$target], $clientId, $value);
                $results[$target]['imported']++;
            }
        }

        return array_values($results);
    }

    private static function write(int $fieldId, int $clientId, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $clientId)->exists();
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fieldId, 'relid' => $clientId],
            ['value' => $value, 'updated_at' => $now] + ($exists ? [] : ['created_at' => $now])
        );
    }

    /**
     * The custom fields of the old mapping settings ("cf:<id>").
     *
     * @return array<string, int>
     */
    private static function oldMapping(): array
    {
        $mapping = [];
        $rows = Capsule::table('mod_efactura_settings')->whereIn('name', self::OLD_SETTINGS)->pluck('value', 'name');
        foreach ($rows as $name => $value) {
            if (preg_match('/^cf:(\d+)$/', (string) $value, $match) === 1) {
                $mapping[substr((string) $name, strlen('client_field_'))] = (int) $match[1];
            }
        }

        return $mapping;
    }

    private static function isClientField(int $id): bool
    {
        return Capsule::table('tblcustomfields')->where('id', $id)->where('type', 'client')->exists();
    }

    private static function fieldName(int $id): string
    {
        $name = (string) Capsule::table('tblcustomfields')->where('id', $id)->value('fieldname');
        $position = strpos($name, '|');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * The rows of the import report, in the order they were written.
     *
     * @return list<object>
     */
    public static function report(): array
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            return [];
        }

        return Capsule::table(self::TABLE)->orderBy('id')->get()->all();
    }
}
