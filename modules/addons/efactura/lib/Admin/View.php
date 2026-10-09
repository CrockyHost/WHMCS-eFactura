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

use WHMCS\Module\Addon\Efactura\Addon;

/**
 * Renders the addon templates (templates/<folder>/*.tpl) with the WHMCS
 * Smarty engine. Templates only print values prepared in PHP. Output is
 * HTML-escaped by default; trusted markup needs an explicit "nofilter".
 */
final class View
{
    /**
     * @param array<string, mixed> $vars
     * @param string $folder "admin" or "public"
     */
    public static function render(string $template, array $vars, string $folder = 'admin'): string
    {
        $smarty = new \WHMCS\Smarty(true);
        $smarty->escape_html = true;
        $smarty->setTemplateDir(Addon::path('templates/' . $folder));
        $smarty->assign($vars);

        return (string) $smarty->fetch($template . '.tpl');
    }
}
