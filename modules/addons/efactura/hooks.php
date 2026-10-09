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

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

use WHMCS\Module\Addon\Efactura\Hooks\CronHooks;
use WHMCS\Module\Addon\Efactura\Hooks\InvoiceHooks;

// Hook registration only. WHMCS loads this file on every page, so each hook
// is a one-line delegation to a class in lib/Hooks.

// Fiscal numbering at payment. The early priority makes the addon restore a
// fiscal number before other hooks read it; InvoicePaid releases the lock
// last.
add_hook('AddInvoicePayment', -100, static function (array $vars): void {
    InvoiceHooks::addInvoicePayment($vars);
});
add_hook('InvoicePaidPreEmail', -100, static function (array $vars): void {
    InvoiceHooks::invoicePaidPreEmail($vars);
});
add_hook('InvoicePaid', 1000, static function (array $vars): void {
    InvoiceHooks::invoicePaid($vars);
});

// Fiscal invoice before payment for the clients chosen in the settings.
add_hook('InvoiceCreationPreEmail', 10, static function (array $vars): void {
    InvoiceHooks::invoiceCreated($vars);
});
add_hook('InvoiceCreated', 10, static function (array $vars): void {
    InvoiceHooks::invoiceCreated($vars);
});

// Refreshes the ANAF token and warns about the yearly re-authorization.
add_hook('DailyCronJob', 10, static function (): void {
    CronHooks::daily();
});
