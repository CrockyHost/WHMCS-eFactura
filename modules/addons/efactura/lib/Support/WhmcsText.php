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
 * Text that WHMCS stores HTML-escaped (client and admin names, custom field
 * and group names saved from its forms: "H&amp;M"). The addon templates
 * escape their output, so this text is read back unescaped first.
 */
final class WhmcsText
{
    public static function decode(mixed $value): string
    {
        return htmlspecialchars_decode((string) $value, ENT_QUOTES);
    }

    /**
     * The form WHMCS stores, for searching text saved from its forms.
     */
    public static function encode(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
