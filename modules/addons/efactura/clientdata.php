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

// Endpoint of the "fetch from ANAF" button of the client forms and of the
// admin client pages (lib/ClientData/LookupEndpoint.php). It answers POST
// requests that carry the WHMCS session token; the registration form is
// public, so lookups are rate limited and cached.

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/bootstrap.php';

WHMCS\Module\Addon\Efactura\ClientData\LookupEndpoint::handle();
