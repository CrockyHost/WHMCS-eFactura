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

// Redirect target of the ANAF OAuth authorization, registered at ANAF as the
// "Callback URL" of the application. It does not require a WHMCS admin
// session, because the person with the qualified certificate may authorize
// from another computer; the single-use state value created in the admin
// area authenticates the request.

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/bootstrap.php';

WHMCS\Module\Addon\Efactura\Anaf\OAuth\CallbackHandler::handle($_GET);
