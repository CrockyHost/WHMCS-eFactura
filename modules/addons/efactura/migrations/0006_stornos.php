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
            // The amounts of a storno, fixed when it is issued (negative):
            // net and VAT, in the currency of the invoice.
            'amount_net' => 'DECIMAL(16,2) NULL AFTER `total`',
            'amount_tax' => 'DECIMAL(16,2) NULL AFTER `amount_net`',
        ];
        foreach ($columns as $name => $definition) {
            if (!$schema->hasColumn('mod_efactura_documents', $name)) {
                $db->statement('ALTER TABLE `mod_efactura_documents` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }
};
