<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

use WHMCS\Module\Addon\Efactura\Addon;

// WHMCS entry points. They only dispatch; the logic lives in lib/.

function efactura_config(): array
{
    return Addon::config();
}

function efactura_activate(): array
{
    return Addon::activate();
}

function efactura_deactivate(): array
{
    return Addon::deactivate();
}

function efactura_upgrade($vars): void
{
    Addon::upgrade(is_array($vars) ? $vars : []);
}

function efactura_output($vars): void
{
    Addon::output(is_array($vars) ? $vars : []);
}
