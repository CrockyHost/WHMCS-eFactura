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
        $schema = Capsule::schema();
        $columns = [
            // Upload parameter: the buyer is outside Romania (extern=DA).
            'upload_extern' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `upload_endpoint`',
            // The signed ZIP (or the error ZIP) downloaded from ANAF.
            'archive_id' => 'BIGINT UNSIGNED NULL AFTER `download_id`',
            // Alerts already sent, so that each one is sent once.
            'deadline_alert' => 'SMALLINT NULL AFTER `deadline_date`',
            'processing_alert' => 'SMALLINT NULL AFTER `deadline_alert`',
            'alerted_state' => 'VARCHAR(24) NULL AFTER `processing_alert`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn('mod_efactura_documents', $name)) {
                $db->statement('ALTER TABLE `mod_efactura_documents` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }

        // Worker state shared between cron runs: circuit breaker, pauses,
        // reconciliation bookkeeping.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_state` (
            `name` VARCHAR(64) NOT NULL,
            `value` MEDIUMTEXT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
