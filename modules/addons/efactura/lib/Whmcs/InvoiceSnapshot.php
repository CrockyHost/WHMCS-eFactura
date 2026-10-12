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
use WHMCS\Module\Addon\Efactura\ClientData\FieldMap;
use WHMCS\Module\Addon\Efactura\Romania\Cui;

/**
 * The client details WHMCS keeps for an invoice (Store Client Data
 * Snapshot, mod_invoicedata): the PDF is made from this copy, taken when
 * the invoice is created and not refreshed at payment.
 *
 * The e-Factura XML is built from the client profile when the document is
 * sent, so the copy is refreshed from the profile when the invoice becomes
 * fiscal and every time its XML is built for sending: the PDF and the XML
 * then show the same buyer. Once sent, the copy is not touched again.
 *
 * For a VAT payer whose WHMCS VAT number (tax_id) is RO + the CUI of the
 * addon client field, the copy leaves the CUI field out: the PDF shows the
 * code once, as the VAT number. The copy WHMCS takes when it creates the
 * invoice is tidied the same way, so a proforma shows it once too.
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
        $snapshot->customFields = self::withoutRepeatedCui(self::customFields($userId), (string) ($details['tax_id'] ?? ''));
        $snapshot->save();

        return true;
    }

    /**
     * Leaves the CUI field out of the copy WHMCS took when it created the
     * invoice, when the VAT number shows the same code. Returns whether the
     * copy changed.
     */
    public static function tidy(int $invoiceId): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $snapshot = Snapshot::where('invoiceid', $invoiceId)->first();
        if ($snapshot === null) {
            return false;
        }
        $fields = is_array($snapshot->customFields) ? $snapshot->customFields : [];
        $details = is_array($snapshot->clientsDetails) ? $snapshot->clientsDetails : [];
        $tidy = self::withoutRepeatedCui($fields, (string) ($details['tax_id'] ?? ''));
        if (count($tidy) === count($fields)) {
            return false;
        }
        $snapshot->customFields = $tidy;
        $snapshot->save();

        return true;
    }

    /**
     * The fields without the addon CUI when the VAT number (tax_id) is RO +
     * the same CUI; a client that is not a VAT payer keeps its CUI.
     *
     * @param list<array<string, mixed>> $fields as in the copy: id, fieldname, value
     * @return list<array<string, mixed>>
     */
    public static function withoutRepeatedCui(array $fields, string $taxId): array
    {
        $cuiField = FieldMap::load()->id('cui');
        $taxId = strtoupper(preg_replace('/[\s.\-]+/', '', FieldMap::text($taxId)) ?? '');
        if ($cuiField === null || !str_starts_with($taxId, 'RO')) {
            return $fields;
        }

        return array_values(array_filter($fields, static fn (array $field): bool => (int) ($field['id'] ?? 0) !== $cuiField
            || Cui::normalize(FieldMap::text($field['value'] ?? '')) !== Cui::normalize($taxId)));
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
