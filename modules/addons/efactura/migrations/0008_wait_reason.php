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
        // Why the queue waits on a document whose upload answer was lost (the
        // invoices sent not read yet), for the admin; last_error keeps the
        // answer of ANAF, which decides when the upload is sent again.
        if (!Capsule::schema()->hasColumn('mod_efactura_documents', 'wait_reason')) {
            $db->statement('ALTER TABLE `mod_efactura_documents` ADD COLUMN `wait_reason` TEXT NULL AFTER `last_error`');
        }
    }
};
