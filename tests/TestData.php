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

use WHMCS\Database\Capsule;

/**
 * The ID of the RON currency of the WHMCS installation, for the fictive
 * clients of the tests and the development scripts.
 */
function efactura_ron_currency(): int
{
    $id = (int) Capsule::table('tblcurrencies')->where('code', 'RON')->value('id');
    if ($id <= 0) {
        throw new RuntimeException('The WHMCS installation has no RON currency (Configuration > Payments > Currencies).');
    }

    return $id;
}
