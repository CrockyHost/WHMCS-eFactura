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
use WHMCS\Module\Addon\Efactura\ClientData\ClientFieldImport;
use WHMCS\Module\Addon\Efactura\Database\Migration;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return new class implements Migration {
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

        // What the one-time import copied from the client fields used before
        // (lists hold client ids, cut to the first 50).
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_client_import` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_field_id` INT UNSIGNED NOT NULL,
            `source_name` VARCHAR(255) NOT NULL,
            `target_role` VARCHAR(16) NOT NULL,
            `imported` INT UNSIGNED NOT NULL DEFAULT 0,
            `unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
            `conflicts` TEXT NULL,
            `conflict_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `invalid` TEXT NULL,
            `invalid_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `foreign_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Creates the fields and copies the existing values once; the old
        // fields stay as they are.
        ClientFieldImport::run();
    }
};
