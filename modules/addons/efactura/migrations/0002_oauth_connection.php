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
    private const TABLE = 'mod_efactura_oauth';

    public function up(ConnectionInterface $db): void
    {
        $schema = Capsule::schema();

        // ANAF issues one pair of tokens that works for both the test and the
        // production API, so the connection is a single row, not one per
        // environment.
        if ($schema->hasColumn(self::TABLE, 'environment')) {
            $db->statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `environment`, DROP COLUMN `environment`');
        }

        $columns = [
            'redirect_uri' => 'VARCHAR(255) NULL AFTER `client_secret`',
            'certificate_serial' => 'VARCHAR(64) NULL AFTER `refresh_token`',
            'token_roles' => 'VARCHAR(255) NULL AFTER `certificate_serial`',
            'needs_reauthorization' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `refreshed_at`',
            'last_error_at' => 'DATETIME NULL AFTER `last_error`',
            'alert_days_sent' => 'SMALLINT NULL AFTER `last_error_at`',
            // Pending authorization: hash of the single-use state value.
            'state_hash' => 'CHAR(64) NULL AFTER `alert_days_sent`',
            'state_expires_at' => 'DATETIME NULL AFTER `state_hash`',
            'state_admin_id' => 'INT UNSIGNED NULL AFTER `state_expires_at`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn(self::TABLE, $name)) {
                $db->statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }
};
