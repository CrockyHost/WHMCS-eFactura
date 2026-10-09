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

namespace WHMCS\Module\Addon\Efactura\Support;

use WHMCS\Database\Capsule;

/**
 * Small values the worker keeps between runs (mod_efactura_state), stored as
 * JSON. Not settings: nothing here is configured by the admin.
 */
final class RuntimeState
{
    public const TABLE = 'mod_efactura_state';

    public function get(string $name, mixed $default = null): mixed
    {
        $value = Capsule::table(self::TABLE)->where('name', $name)->value('value');

        return $value === null ? $default : json_decode((string) $value, true);
    }

    public function set(string $name, mixed $value): void
    {
        Capsule::table(self::TABLE)->updateOrInsert(['name' => $name], [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function forget(string $name): void
    {
        Capsule::table(self::TABLE)->where('name', $name)->delete();
    }
}
