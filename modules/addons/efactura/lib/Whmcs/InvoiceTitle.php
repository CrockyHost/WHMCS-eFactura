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

namespace WHMCS\Module\Addon\Efactura\Whmcs;

use WHMCS\Config\Setting;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * The title of an invoice that has a fiscal invoice. With proforma invoicing
 * WHMCS titles every invoice that is not Paid as a proforma: one issued
 * fiscally before payment, and also a fiscal invoice cancelled or refunded
 * later. Such an invoice gets the title of a paid one ("invoicenumber" and
 * the number, in the language of the page), in the PDF and on the invoice
 * page of the client area. Proformas keep theirs.
 *
 * The PDF title comes from InvoicePdfTemplateVars, which WHMCS calls for
 * every invoice PDF it renders (download, email attachment, admin area) but
 * does not list in its hook reference. The WHMCS version it last ran under is
 * recorded, so the dashboard can tell when an update has not confirmed it
 * yet; TEMPLATE_LINE in invoicepdf.tpl sets the same title without it, and
 * the BNR exchange rate mention of the notes (ExchangeMention).
 */
final class InvoiceTitle
{
    /** The functions TEMPLATE_LINE calls; hooks.php defines them while the addon is active. */
    public const TEMPLATE_FUNCTIONS = ['efactura_invoice_title', 'efactura_invoice_notes'];

    public const TEMPLATE_LINE = "if (function_exists('efactura_invoice_title')) { \$pagetitle = efactura_invoice_title(\$invoiceid, \$pagetitle); \$notes = efactura_invoice_notes(\$invoiceid, \$notes); }";

    /** RuntimeState: the WHMCS version InvoicePdfTemplateVars last ran under. */
    private const CONFIRMED = 'pdf_title_whmcs';

    /**
     * The title of a paid invoice ("Invoice #CRK-0001") when the invoice has
     * a fiscal invoice with the number it shows, otherwise null.
     */
    public static function fiscal(int $invoiceId): ?string
    {
        $document = FiscalInvoice::document($invoiceId);

        return $document === null ? null : \Lang::trans('invoicenumber') . $document->number;
    }

    /**
     * Records that InvoicePdfTemplateVars runs under this WHMCS version.
     */
    public static function confirm(): void
    {
        $version = self::whmcsVersion();
        $state = new RuntimeState();
        if ($state->get(self::CONFIRMED) !== $version) {
            $state->set(self::CONFIRMED, $version);
        }
    }

    public static function confirmed(): bool
    {
        return (new RuntimeState())->get(self::CONFIRMED) === self::whmcsVersion();
    }

    public static function whmcsVersion(): string
    {
        return (string) Setting::getValue('Version');
    }

    /**
     * The invoicepdf.tpl of the default client area theme, relative to the
     * WHMCS directory.
     */
    public static function templateFile(): string
    {
        return 'templates/' . basename((string) Setting::getValue('Template')) . '/invoicepdf.tpl';
    }

    public static function templateHasLine(?string $path = null): bool
    {
        $path ??= ROOTDIR . '/' . self::templateFile();
        $source = is_file($path) ? file_get_contents($path) : false;

        if ($source === false) {
            return false;
        }
        foreach (self::TEMPLATE_FUNCTIONS as $function) {
            if (!str_contains($source, $function . '(')) {
                return false;
            }
        }

        return true;
    }
}
