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

/**
 * The request input as it was sent. WHMCS escapes $_GET and $_POST when it
 * boots (htmlspecialchars: "A & B" arrives as "A &amp; B"); the addon
 * escapes its output itself, so it reads the values back unescaped.
 */
final class Input
{
    /**
     * @return array<string, mixed>
     */
    public static function post(): array
    {
        return self::clean($_POST);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return self::clean($_GET);
    }

    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::clean(...), $value);
        }

        return is_string($value) ? htmlspecialchars_decode($value, ENT_QUOTES) : $value;
    }
}
