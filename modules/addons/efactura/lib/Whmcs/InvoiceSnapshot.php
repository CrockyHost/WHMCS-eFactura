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

use WHMCS\Billing\Invoice\Snapshot;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

/**
 * The client details WHMCS keeps for an invoice (Store Client Data
 * Snapshot, mod_invoicedata): the PDF is made from this copy, taken when
 * the invoice is created and not refreshed at payment.
 *
 * The e-Factura XML is built from the client profile when the document is
 * sent, so the copy is refreshed from the profile when the invoice becomes
 * fiscal and every time its XML is built for sending: the PDF and the XML
 * then show the same buyer. Once sent, the copy is not touched again.
 */
final class InvoiceSnapshot
{
    public const SETTING = 'StoreClientDataSnapshotOnInvoiceCreation';

    public static function enabled(): bool
    {
        return in_array(strtolower(trim((string) Setting::getValue(self::SETTING))), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * Takes the copy of the invoice again from the current client profile.
     * Returns false when WHMCS keeps no copy (the setting is off).
     */
    public static function refresh(int $invoiceId): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $userId = (int) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
        if ($userId <= 0) {
            return false;
        }
        require_once ROOTDIR . '/includes/clientfunctions.php';
        $details = getClientsDetails($userId);
        if (!is_array($details) || $details === []) {
            return false;
        }
        // As WHMCS stores it: the details without the client model.
        unset($details['model']);

        $snapshot = Snapshot::where('invoiceid', $invoiceId)->first() ?? new Snapshot();
        $snapshot->invoiceId = $invoiceId;
        $snapshot->clientsDetails = $details;
        $snapshot->customFields = self::customFields($userId);
        $snapshot->save();

        return true;
    }

    /**
     * The client custom fields shown on invoices that have a value, as WHMCS
     * stores them in the copy.
     *
     * @return list<array{id: int, fieldname: string, value: string}>
     */
    private static function customFields(int $userId): array
    {
        $fields = Capsule::table('tblcustomfields as f')
            ->join('tblcustomfieldsvalues as v', static function ($join) use ($userId): void {
                $join->on('v.fieldid', '=', 'f.id')->where('v.relid', '=', $userId);
            })
            ->where('f.type', 'client')
            ->where('f.showinvoice', 'on')
            ->where('v.value', '!=', '')
            ->orderBy('f.sortorder')
            ->orderBy('f.id')
            ->get(['f.id', 'f.fieldname', 'v.value']);

        return array_map(static fn (object $field): array => [
            'id' => (int) $field->id,
            'fieldname' => (string) $field->fieldname,
            'value' => (string) $field->value,
        ], $fields->all());
    }
}
