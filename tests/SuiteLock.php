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
 * One test run at a time on the development database: two integration
 * suites (or a suite and a live script) at once deadlock on the same rows
 * and fight over the worker lock. The MySQL lock is released when the
 * process ends, also when it is killed.
 */
function efactura_suite_lock(): void
{
    $name = 'efactura_test_run:' . Capsule::connection()->getDatabaseName();
    $acquire = static fn (int $timeout): bool => (int) Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout])->acquired === 1;
    if ($acquire(0)) {
        return;
    }
    echo "Another test run is using the database; waiting for it to finish...\n";
    if (!$acquire(3600)) {
        fwrite(STDERR, "Gave up after an hour of waiting for the other test run.\n");
        exit(2);
    }
}
