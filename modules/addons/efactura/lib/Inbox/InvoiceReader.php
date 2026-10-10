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

namespace WHMCS\Module\Addon\Efactura\Inbox;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads a received e-Factura (UBL Invoice or CreditNote) for the inbox: who
 * issued it, its number, dates, totals and lines. Only reads; the signed
 * ZIP stays the original.
 */
final class InvoiceReader
{
    /**
     * @return array{type: string, number: string, date: string, due: string, currency: string,
     *               supplier: array{name: string, cui: string, address: string},
     *               buyer: array{name: string, cui: string},
     *               net: string, tax: string, total: string, payable: string, notes: list<string>,
     *               lines: list<array{name: string, quantity: string, unit: string, price: string, amount: string, vat: string}>}|null
     */
    public static function read(string $xml): ?array
    {
        $document = new DOMDocument();
        if ($xml === '' || @$document->loadXML($xml, LIBXML_NONET) === false || $document->documentElement === null) {
            return null;
        }
        $root = $document->documentElement->localName;
        if (!in_array($root, ['Invoice', 'CreditNote'], true)) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $value = static fn (string $path, ?DOMElement $context = null): string => trim((string) $xpath->evaluate('string(' . $path . ')', $context));
        $party = static fn (string $role): string => "/*/*[local-name()='{$role}']/*[local-name()='Party']";
        $supplier = $party('AccountingSupplierParty');
        $buyer = $party('AccountingCustomerParty');
        $currency = $value("/*/*[local-name()='DocumentCurrencyCode']");

        $lines = [];
        $lineName = $root === 'CreditNote' ? 'CreditNoteLine' : 'InvoiceLine';
        $quantityName = $root === 'CreditNote' ? 'CreditedQuantity' : 'InvoicedQuantity';
        foreach ($xpath->query("/*/*[local-name()='{$lineName}']") ?: [] as $line) {
            if (!$line instanceof DOMElement) {
                continue;
            }
            $lines[] = [
                'name' => $value("*[local-name()='Item']/*[local-name()='Name']", $line),
                'quantity' => $value("*[local-name()='{$quantityName}']", $line),
                'unit' => $value("*[local-name()='{$quantityName}']/@unitCode", $line),
                'price' => $value("*[local-name()='Price']/*[local-name()='PriceAmount']", $line),
                'amount' => $value("*[local-name()='LineExtensionAmount']", $line),
                'vat' => $value("*[local-name()='Item']/*[local-name()='ClassifiedTaxCategory']/*[local-name()='Percent']", $line),
            ];
        }
        $notes = [];
        foreach ($xpath->query("/*/*[local-name()='Note']") ?: [] as $note) {
            $notes[] = trim((string) $note->textContent);
        }
        $totals = "/*/*[local-name()='LegalMonetaryTotal']";

        return [
            'type' => $value("/*/*[local-name()='InvoiceTypeCode' or local-name()='CreditNoteTypeCode']") ?: ($root === 'CreditNote' ? '381' : '380'),
            'number' => $value("/*/*[local-name()='ID']"),
            'date' => $value("/*/*[local-name()='IssueDate']"),
            'due' => $value("/*/*[local-name()='DueDate']") ?: $value("/*/*[local-name()='PaymentMeans']/*[local-name()='PaymentDueDate']"),
            'currency' => $currency,
            'supplier' => [
                'name' => $value("{$supplier}/*[local-name()='PartyLegalEntity']/*[local-name()='RegistrationName']") ?: $value("{$supplier}/*[local-name()='PartyName']/*[local-name()='Name']"),
                'cui' => $value("{$supplier}/*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']") ?: $value("{$supplier}/*[local-name()='PartyLegalEntity']/*[local-name()='CompanyID']"),
                'address' => implode(', ', array_filter([
                    $value("{$supplier}/*[local-name()='PostalAddress']/*[local-name()='StreetName']"),
                    $value("{$supplier}/*[local-name()='PostalAddress']/*[local-name()='CityName']"),
                    $value("{$supplier}/*[local-name()='PostalAddress']/*[local-name()='CountrySubentity']"),
                ], static fn (string $part): bool => $part !== '')),
            ],
            'buyer' => [
                'name' => $value("{$buyer}/*[local-name()='PartyLegalEntity']/*[local-name()='RegistrationName']") ?: $value("{$buyer}/*[local-name()='PartyName']/*[local-name()='Name']"),
                'cui' => $value("{$buyer}/*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']") ?: $value("{$buyer}/*[local-name()='PartyLegalEntity']/*[local-name()='CompanyID']"),
            ],
            'net' => $value("{$totals}/*[local-name()='TaxExclusiveAmount']"),
            'tax' => $value("/*/*[local-name()='TaxTotal']/*[local-name()='TaxAmount'][@currencyID='{$currency}']") ?: $value("/*/*[local-name()='TaxTotal']/*[local-name()='TaxAmount']"),
            'total' => $value("{$totals}/*[local-name()='TaxInclusiveAmount']"),
            'payable' => $value("{$totals}/*[local-name()='PayableAmount']"),
            'notes' => array_values(array_filter($notes, static fn (string $note): bool => $note !== '')),
            'lines' => $lines,
        ];
    }

    /**
     * A message from a buyer (RASP): the upload index of the invoice it is
     * about and its text.
     *
     * @return array{index: string, message: string}|null
     */
    public static function buyerMessage(string $xml): ?array
    {
        $document = new DOMDocument();
        if ($xml === '' || @$document->loadXML($xml, LIBXML_NONET) === false || $document->documentElement === null) {
            return null;
        }
        $root = $document->documentElement;

        return [
            'index' => trim($root->getAttribute('index_incarcare')),
            'message' => trim($root->getAttribute('message')),
        ];
    }
}
