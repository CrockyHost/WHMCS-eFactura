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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;

/**
 * The fiscal invoice behind a WHMCS invoice, as the PDF and the invoice page
 * show it.
 */
final class FiscalInvoice
{
    /**
     * The fiscal invoice document of a WHMCS invoice that shows its number
     * (issued at payment or before it, also when cancelled or refunded later),
     * otherwise null: a proforma, or an invoice whose number was edited.
     */
    public static function document(int $invoiceId): ?object
    {
        if ($invoiceId <= 0) {
            return null;
        }

        return Capsule::table(Document::TABLE . ' as d')
            ->join('tblinvoices as i', 'i.id', '=', 'd.invoice_id')
            ->where('d.dedupe_key', Document::invoiceKey($invoiceId))
            ->whereColumn('i.invoicenum', 'd.number')
            ->first(['d.*']);
    }

    /**
     * The currency of a client's invoices (WHMCS invoices are in the currency
     * of the client), '' when it has none.
     */
    public static function currency(int $clientId): string
    {
        return strtoupper((string) Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $clientId)
            ->value('tblcurrencies.code'));
    }
}
