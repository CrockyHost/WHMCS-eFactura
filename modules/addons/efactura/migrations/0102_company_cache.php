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
use WHMCS\Module\Addon\Efactura\Database\Migration;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return new class implements Migration {
    public function up(ConnectionInterface $db): void
    {
        // Answers of the ANAF company lookup (PlatitorTvaRest), by CUI.
        // status: "found" (data holds the company) or "not_found".
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_company_cache` (
            `cui` VARCHAR(10) NOT NULL,
            `status` VARCHAR(16) NOT NULL,
            `data` MEDIUMTEXT NULL,
            `fetched_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`cui`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // One row per lookup, for the rate limits (bucket = hash of the IP,
        // the session or the admin) and for the one call per second to ANAF
        // (bucket "anaf"). Rows older than a day are deleted.
        $db->statement('CREATE TABLE IF NOT EXISTS `mod_efactura_lookup_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket` VARCHAR(64) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `bucket_created` (`bucket`, `created_at`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
