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
            // received, buyer, sent or errors (from the ANAF "tip").
            'kind' => 'VARCHAR(16) NOT NULL DEFAULT \'\' AFTER `type`',
            // The document of the addon the message is about, when there is one.
            'document_id' => 'BIGINT UNSIGNED NULL AFTER `kind`',
            // The text of a message from a buyer (RASP).
            'message_text' => 'TEXT NULL AFTER `total`',
            // Downloads of the ZIP: attempts and the last error.
            'download_attempts' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `archive_id`',
            'last_error' => 'TEXT NULL AFTER `download_attempts`',
            // When the administrators were told about it.
            'alerted_at' => 'DATETIME NULL AFTER `processed_by`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn('mod_efactura_messages', $name)) {
                $db->statement('ALTER TABLE `mod_efactura_messages` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
        foreach (['kind' => ['kind'], 'document_id' => ['document_id']] as $index => $fields) {
            $exists = $db->select('SHOW INDEX FROM `mod_efactura_messages` WHERE Key_name = ?', [$index]);
            if ($exists === []) {
                $db->statement('ALTER TABLE `mod_efactura_messages` ADD INDEX `' . $index . '` (`' . implode('`, `', $fields) . '`)');
            }
        }
    }
};
