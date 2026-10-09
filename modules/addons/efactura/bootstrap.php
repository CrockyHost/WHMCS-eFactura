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

// Autoloader for the WHMCS\Module\Addon\Efactura\ namespace (lib/). It has no
// other side effects, so the test runner can load it without WHMCS.
if (!defined('EFACTURA_AUTOLOADER')) {
    define('EFACTURA_AUTOLOADER', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'WHMCS\\Module\\Addon\\Efactura\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
