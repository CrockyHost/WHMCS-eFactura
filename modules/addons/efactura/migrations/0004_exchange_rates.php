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
        // Reference exchange rates, as published (rate = value / multiplier
        // RON for one unit). rate_date is the publication date.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_exchange_rates` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source` VARCHAR(16) NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `rate_date` DATE NOT NULL,
            `value` DECIMAL(14,4) NOT NULL,
            `multiplier` INT UNSIGNED NOT NULL DEFAULT 1,
            `fetched_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_currency_date` (`source`, `currency`, `rate_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // The exchange rate used for the VAT in RON of a foreign-currency document.
        $schema = Capsule::schema();
        $columns = [
            'exchange_rate' => 'DECIMAL(14,6) NULL AFTER `total`',
            'exchange_rate_date' => 'DATE NULL AFTER `exchange_rate`',
            'exchange_rate_source' => 'VARCHAR(16) NULL AFTER `exchange_rate_date`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn('mod_efactura_documents', $name)) {
                $db->statement('ALTER TABLE `mod_efactura_documents` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }
};
