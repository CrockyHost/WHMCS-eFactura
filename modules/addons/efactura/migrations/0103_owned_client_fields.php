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

use Illuminate\Database\ConnectionInterface;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\ClientData\ClientFields;
use WHMCS\Module\Addon\Efactura\Database\Migration;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return new class implements Migration {
    /**
     * Fields an earlier version of 0101 created (role => name, description).
     * They belong to the addon and are taken over instead of duplicated.
     */
    private const EARLIER_FIELDS = [
        'cui' => ['CUI / Company ID', 'Romanian companies: CUI (fiscal code) without RO. Other countries: company registration number.'],
        'regcom' => ['Nr. Reg. Com.', 'Trade register number of a Romanian company (ONRC).'],
        'cnp' => ['CNP', 'Personal numeric code of a Romanian individual (optional).'],
    ];

    public function up(ConnectionInterface $db): void
    {
        // The client custom fields the addon owns (CUI, trade register
        // number, CNP), by role.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_client_fields` (
            `role` VARCHAR(16) NOT NULL,
            `field_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        foreach (self::EARLIER_FIELDS as $role => [$name, $description]) {
            $earlier = Capsule::table('tblcustomfields')
                ->where('type', 'client')->where('fieldname', $name)->where('description', $description)
                ->value('id');
            if ($earlier !== null) {
                ClientFields::adopt($role, (int) $earlier);
            }
        }
        ClientFields::ensure();
    }
};
