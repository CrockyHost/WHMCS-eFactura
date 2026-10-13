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

use WHMCS\Module\Addon\Efactura\ClientData\ClientDataHooks;
use WHMCS\Module\Addon\Efactura\Hooks\AdminHooks;
use WHMCS\Module\Addon\Efactura\Hooks\CronHooks;
use WHMCS\Module\Addon\Efactura\Hooks\InvoiceHooks;
use WHMCS\Module\Addon\Efactura\Whmcs\ExchangeMention;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoiceTitle;

// Hook registration only (and the functions a theme may call for the PDF
// title and notes, at the end). WHMCS loads this file on every page, so each
// hook is a one-line delegation to a class in lib/Hooks.

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

// The client details of the PDF (the CUI once for VAT payers), and the fiscal
// invoice before payment for the clients chosen in the settings.
add_hook('InvoiceCreationPreEmail', 10, static function (array $vars): void {
    InvoiceHooks::invoiceCreated($vars);
});
add_hook('InvoiceCreated', 10, static function (array $vars): void {
    InvoiceHooks::invoiceCreated($vars);
});

// The title of an invoice that has a fiscal invoice (issued before payment,
// cancelled or refunded), which WHMCS would title as a proforma, and the BNR
// exchange rate mention of a fiscal invoice in another currency: in the PDF
// (a hook point WHMCS calls for every invoice PDF, missing from its hook
// reference) and on the invoice page of the client area.
add_hook('InvoicePdfTemplateVars', 10, static function (array $vars): array {
    return InvoiceHooks::pdfTemplateVars($vars);
});
add_hook('ClientAreaPageViewInvoice', 10, static function (array $vars): array {
    return InvoiceHooks::viewInvoicePage($vars);
});

// Stornos of fiscal invoices: cancellation and refunds.
add_hook('InvoiceCancelled', 10, static function (array $vars): void {
    InvoiceHooks::invoiceCancelled($vars);
});
add_hook('AddTransaction', 10, static function (array $vars): void {
    InvoiceHooks::addTransaction($vars);
});
add_hook('InvoiceRefunded', 10, static function (array $vars): void {
    InvoiceHooks::invoiceRefunded($vars);
});

// Refreshes the ANAF token and warns about the yearly re-authorization.
add_hook('DailyCronJob', 10, static function (): void {
    CronHooks::daily();
});

// The e-Factura queue, after every run of the system cron.
add_hook('AfterCronJob', 10, static function (): void {
    CronHooks::afterCron();
});

// The e-Factura panel on the admin invoice page, and on the credit note page.
add_hook('AdminInvoicesControlsOutput', 10, static function (array $vars): string {
    return AdminHooks::invoiceControls($vars);
});
add_hook('AdminAreaFooterOutput', 10, static function (array $vars): string {
    return AdminHooks::footer($vars);
});

// Client forms for Romanian clients (county, sector): registration, checkout,
// profile, contacts and the client pages of the admin area.
ClientDataHooks::register();

if (!function_exists('efactura_invoice_title')) {
    /**
     * The same PDF title for a theme whose invoicepdf.tpl calls it (see
     * InvoiceTitle::TEMPLATE_LINE), should WHMCS stop calling
     * InvoicePdfTemplateVars. Defined only while the addon is active.
     */
    function efactura_invoice_title(mixed $invoiceId, mixed $pagetitle): string
    {
        try {
            return InvoiceTitle::fiscal((int) $invoiceId) ?? (string) $pagetitle;
        } catch (Throwable) {
            return (string) $pagetitle;
        }
    }

    /**
     * The PDF notes with the BNR exchange rate mention, for the same line.
     */
    function efactura_invoice_notes(mixed $invoiceId, mixed $notes): string
    {
        try {
            $mention = ExchangeMention::forInvoice((int) $invoiceId, ExchangeMention::language(), true);

            return $mention === null ? (string) $notes : ExchangeMention::pdfNotes((string) $notes, $mention);
        } catch (Throwable) {
            return (string) $notes;
        }
    }
}
