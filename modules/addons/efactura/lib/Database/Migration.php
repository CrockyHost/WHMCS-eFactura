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

namespace WHMCS\Module\Addon\Efactura\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * A schema change in migrations/. Each file returns one instance.
 *
 * MySQL commits DDL implicitly, so migrations cannot be rolled back and must
 * be safe to run again (CREATE TABLE IF NOT EXISTS and similar).
 */
interface Migration
{
    public function up(ConnectionInterface $db): void;
}
