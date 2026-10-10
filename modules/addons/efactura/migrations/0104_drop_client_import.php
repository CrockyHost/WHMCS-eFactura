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
        // A short-lived version of 0103 kept a report of values imported from
        // other client fields; the addon no longer imports anything. The
        // values it copied stay in the addon's fields.
        $db->statement('DROP TABLE IF EXISTS `mod_efactura_client_import`');

        // The client field mapping settings are no longer used.
        $db->table('mod_efactura_settings')
            ->whereIn('name', ['client_field_cui', 'client_field_regcom', 'client_field_cnp', 'client_field_county'])
            ->delete();
    }
};
