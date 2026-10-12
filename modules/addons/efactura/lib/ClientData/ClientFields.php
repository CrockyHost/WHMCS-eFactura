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

use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * The client custom fields the addon owns: CUI, trade register number and
 * CNP on the client forms, and the admin-only defaults of the invoice
 * references. The addon creates them with fixed names (translated to
 * Romanian with the WHMCS dynamic translations), remembers their ids in
 * mod_efactura_client_fields and creates them again when one is missing.
 * There is no admin mapping.
 */
final class ClientFields
{
    public const TABLE = 'mod_efactura_client_fields';

    /** The billing identity fields of the client forms. */
    public const ROLES = ['cui', 'regcom', 'cnp'];

    /** Admin-only defaults of the invoice references (see InvoiceReferences). */
    public const REFERENCE_ROLES = ['bt11', 'bt12', 'bt13', 'bt15', 'bt19', 'bt46'];

    /**
     * role => name, description (English, the base WHMCS values), the same
     * in Romanian, shown on invoices (for the CUI and the trade register
     * number a setting decides, see INVOICE_SETTINGS) and admin only.
     */
    public const DEFINITIONS = [
        'cui' => [
            'name' => 'CUI (Romanian fiscal code)',
            'description' => 'Romanian companies: the fiscal code, without RO.',
            'name_ro' => 'CUI (cod fiscal)',
            'description_ro' => 'Codul fiscal al firmei, fără RO.',
            'invoice' => true,
        ],
        'regcom' => [
            'name' => 'Trade register no. (Romania)',
            'description' => 'Romanian companies: the ONRC number, e.g. J40/1234/2020 or J2024012345008.',
            'name_ro' => 'Nr. Reg. Com.',
            'description_ro' => 'Numărul de înregistrare la Registrul Comerțului.',
            'invoice' => true,
        ],
        'cnp' => [
            'name' => 'CNP (Romanian personal code)',
            'description' => 'Romanian individuals, optional.',
            'name_ro' => 'CNP',
            'description_ro' => 'Codul numeric personal, opțional.',
            'invoice' => false,
        ],
        'bt11' => [
            'name' => 'e-Factura: project reference (BT-11)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: referința proiectului (BT-11)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
        'bt12' => [
            'name' => 'e-Factura: contract reference (BT-12)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: referința contractului (BT-12)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
        'bt13' => [
            'name' => 'e-Factura: purchase order reference (BT-13)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: referința comenzii (BT-13)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
        'bt15' => [
            'name' => 'e-Factura: receiving advice reference (BT-15)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: referința avizului de recepție (BT-15)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
        'bt19' => [
            'name' => 'e-Factura: buyer accounting reference (BT-19)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: referința contabilă a cumpărătorului (BT-19)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
        'bt46' => [
            'name' => 'e-Factura: buyer identifier (BT-46)',
            'description' => self::REFERENCE_NOTE,
            'name_ro' => 'e-Factura: identificatorul cumpărătorului (BT-46)',
            'description_ro' => self::REFERENCE_NOTE_RO,
            'invoice' => false,
            'admin' => true,
        ],
    ];

    private const REFERENCE_NOTE = 'Default for the e-Factura invoices of this client. Admin only, not printed on the invoice.';
    private const REFERENCE_NOTE_RO = 'Valoarea implicită pe facturile e-Factura ale clientului. Doar pentru admin, nu se tipărește pe factură.';

    /** role => setting that decides "Show on Invoice" for that field */
    public const INVOICE_SETTINGS = ['cui' => 'client_cui_on_invoice', 'regcom' => 'client_regcom_on_invoice'];

    private const LOCK = 'client_fields';

    /** @var array<string, int>|null field ids of this request, every role */
    private static ?array $ids = null;

    /**
     * role => custom field id of the billing identity fields, creating the
     * fields that are missing.
     *
     * @return array<string, int>
     */
    public static function ids(): array
    {
        return array_intersect_key(self::all(), array_flip(self::ROLES));
    }

    /**
     * role => custom field id of the invoice reference defaults.
     *
     * @return array<string, int>
     */
    public static function referenceIds(): array
    {
        return array_intersect_key(self::all(), array_flip(self::REFERENCE_ROLES));
    }

    public static function id(string $role): int
    {
        $ids = self::all();
        if (!isset($ids[$role])) {
            throw new RuntimeException('Unknown client field role: ' . $role);
        }

        return $ids[$role];
    }

    /**
     * @return array<string, int> role => custom field id, every role
     */
    private static function all(): array
    {
        if (self::$ids === null || count(self::$ids) !== count(self::DEFINITIONS)) {
            self::$ids = self::ensure();
        }

        return self::$ids;
    }

    /**
     * Forgets the ids of this request (after the fields were changed).
     */
    public static function reset(): void
    {
        self::$ids = null;
    }

    /**
     * Makes sure every field exists and is recorded.
     *
     * @return array<string, int> role => field id
     */
    public static function ensure(): array
    {
        $ids = self::recorded();
        if (count($ids) === count(self::DEFINITIONS)) {
            return $ids;
        }

        if (!Lock::acquire(self::LOCK, 10)) {
            throw new RuntimeException('Another request is creating the WHMCS-eFactura client fields.');
        }
        try {
            // Another request may have created them while this one waited.
            $ids = self::recorded();
            foreach (array_keys(self::DEFINITIONS) as $role) {
                if (!isset($ids[$role])) {
                    $ids[$role] = self::create($role);
                    self::record($role, $ids[$role]);
                    if (function_exists('logActivity')) {
                        logActivity(sprintf('WHMCS-eFactura: created the client custom field "%s" (#%d)', self::DEFINITIONS[$role]['name'], $ids[$role]));
                    }
                }
            }
        } finally {
            Lock::release(self::LOCK);
        }
        $ids = self::ordered($ids);
        self::$ids = $ids;

        return $ids;
    }

    /**
     * Takes over an existing field the addon created before it owned its
     * fields, with the fixed name and translations.
     */
    public static function adopt(string $role, int $fieldId): void
    {
        $definition = self::DEFINITIONS[$role];
        Capsule::table('tblcustomfields')->where('id', $fieldId)->update([
            'fieldname' => $definition['name'],
            'description' => $definition['description'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::translate($fieldId, $definition);
        self::record($role, $fieldId);
        self::$ids = null;
    }

    /**
     * Turns "Show on Invoice" of the CUI and trade register fields on or
     * off, as their settings say (after the settings are saved).
     */
    public static function applyInvoiceSetting(): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            return;
        }
        foreach (array_keys(self::INVOICE_SETTINGS) as $role) {
            Capsule::table('tblcustomfields')->where('id', self::id($role))->update([
                'showinvoice' => self::onInvoice($role) ? 'on' : '',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private static function onInvoice(string $role): bool
    {
        return isset(self::INVOICE_SETTINGS[$role]) ? Settings::bool(self::INVOICE_SETTINGS[$role]) : (bool) self::DEFINITIONS[$role]['invoice'];
    }

    /**
     * The name of a field as WHMCS shows it to the administrator.
     */
    public static function label(string $role): string
    {
        return self::DEFINITIONS[$role]['name'] ?? $role;
    }

    /**
     * @return array<string, int> role => id of the recorded fields that still exist
     */
    private static function recorded(): array
    {
        $rows = Capsule::table(self::TABLE . ' as o')
            ->join('tblcustomfields as f', 'f.id', '=', 'o.field_id')
            ->where('f.type', 'client')
            ->get(['o.role', 'o.field_id']);
        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row->role] = (int) $row->field_id;
        }

        return self::ordered($found);
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, int> the known roles, in the order of DEFINITIONS
     */
    private static function ordered(array $ids): array
    {
        $ordered = [];
        foreach (array_keys(self::DEFINITIONS) as $role) {
            if (isset($ids[$role])) {
                $ordered[$role] = $ids[$role];
            }
        }

        return $ordered;
    }

    private static function record(string $role, int $fieldId): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = Capsule::table(self::TABLE)->where('role', $role)->exists();
        Capsule::table(self::TABLE)->updateOrInsert(
            ['role' => $role],
            ['field_id' => $fieldId, 'updated_at' => $now] + ($exists ? [] : ['created_at' => $now])
        );
    }

    private static function create(string $role): int
    {
        $definition = self::DEFINITIONS[$role];
        $adminOnly = (bool) ($definition['admin'] ?? false);
        $now = date('Y-m-d H:i:s');
        $order = (int) Capsule::table('tblcustomfields')->where('type', 'client')->max('sortorder');
        $id = (int) Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'client',
            'relid' => 0,
            'fieldname' => $definition['name'],
            'fieldtype' => 'text',
            'description' => $definition['description'],
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => $adminOnly ? 'on' : '',
            'required' => '',
            'showorder' => $adminOnly ? '' : 'on',
            'showinvoice' => self::onInvoice($role) ? 'on' : '',
            'sortorder' => $order + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::translate($id, $definition);

        return $id;
    }

    /**
     * Romanian name and description through the WHMCS dynamic translations
     * (shown in the client area when translations are enabled).
     *
     * @param array<string, string|bool> $definition
     */
    private static function translate(int $fieldId, array $definition): void
    {
        if (!Capsule::schema()->hasTable('tbldynamic_translations')) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        foreach (['name' => 'name_ro', 'description' => 'description_ro'] as $part => $key) {
            Capsule::table('tbldynamic_translations')->updateOrInsert(
                ['related_type' => 'custom_field.{id}.' . $part, 'related_id' => $fieldId, 'language' => 'romanian'],
                ['translation' => (string) $definition[$key], 'input_type' => 'text', 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
