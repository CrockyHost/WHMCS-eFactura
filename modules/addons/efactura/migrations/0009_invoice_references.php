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
use WHMCS\Module\Addon\Efactura\Database\Migration;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return new class implements Migration {
    public function up(ConnectionInterface $db): void
    {
        // The references an admin types on an invoice (BT-11, BT-12, BT-13,
        // BT-15, BT-19, BT-46), as JSON keyed by business term, until its
        // document is sent.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_references` (
            `invoice_id` INT UNSIGNED NOT NULL,
            `refs` TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `updated_by` INT UNSIGNED NULL,
            PRIMARY KEY (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // The references that went into the XML of a document, frozen with it.
        if (!Capsule::schema()->hasColumn('mod_efactura_documents', 'refs')) {
            $db->statement('ALTER TABLE `mod_efactura_documents` ADD COLUMN `refs` TEXT NULL AFTER `xml_generated_at`');
        }
    }
};
