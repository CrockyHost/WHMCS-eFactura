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

namespace WHMCS\Module\Addon\Efactura\Database;

use WHMCS\Database\Capsule;

/**
 * Named MySQL locks (GET_LOCK). They belong to the database connection, so a
 * crashed request never leaves a lock behind.
 *
 * Lock names are server-wide, so they are scoped to the current database to
 * keep two WHMCS installations on the same server apart.
 */
final class Lock
{
    public static function acquire(string $name, int $timeoutSeconds): bool
    {
        $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [self::scoped($name), $timeoutSeconds]);

        return (int) ($row->acquired ?? 0) === 1;
    }

    public static function release(string $name): void
    {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::scoped($name)]);
    }

    public static function scoped(string $name): string
    {
        $database = (string) Capsule::connection()->getDatabaseName();

        // MySQL limits lock names to 64 characters.
        return substr('efactura.' . substr(sha1($database), 0, 10) . '.' . $name, 0, 64);
    }
}
