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

namespace WHMCS\Module\Addon\Efactura\Admin;

/**
 * The result of an admin action, shown once on the next page (stored in the
 * admin session).
 */
final class Flash
{
    private const KEY = 'efactura_flash';

    /**
     * @param string $type success, info, warning or danger
     * @param list<string> $details
     */
    public static function set(string $type, string $text, array $details = []): void
    {
        $_SESSION[self::KEY] = ['type' => $type, 'text' => $text, 'details' => $details];
    }

    /**
     * @return array{type: string, text: string, details: list<string>}|null
     */
    public static function pull(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_array($flash) && isset($flash['type'], $flash['text']) ? $flash : null;
    }
}
