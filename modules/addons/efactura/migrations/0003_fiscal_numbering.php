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
    private const TABLE = 'mod_efactura_documents';

    public function up(ConnectionInterface $db): void
    {
        $schema = Capsule::schema();
        $columns = [
            // How the fiscal number was assigned: "payment" (WHMCS, at
            // payment) or "early" (by the addon, before payment).
            'source' => 'VARCHAR(16) NULL AFTER `reason`',
            // The proforma number the invoice had before the fiscal one.
            'proforma_number' => 'VARCHAR(64) NULL AFTER `number`',
            'issued_by' => 'INT UNSIGNED NULL AFTER `issue_date`',
            // Set when the document needs a manual check before sending
            // (e.g. the numbering lock could not be obtained at payment).
            'review_reason' => 'VARCHAR(48) NULL AFTER `held_by`',
            'review_at' => 'DATETIME NULL AFTER `review_reason`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn(self::TABLE, $name)) {
                $db->statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }
};
