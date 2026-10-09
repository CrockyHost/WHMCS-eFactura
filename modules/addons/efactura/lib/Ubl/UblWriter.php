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

namespace WHMCS\Module\Addon\Efactura\Ubl;

use DOMDocument;
use DOMElement;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Serializes an Invoice to UBL 2.1 XML, elements in the order of the OASIS
 * XSD. Text goes through DOM, so special characters are always escaped.
 * Optional elements without a value are left out (an empty element fails
 * the validation).
 */
final class UblWriter
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private DOMDocument $dom;
    private string $currency = 'RON';

    public function write(Invoice $invoice): string
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->currency = $invoice->currency;

        $root = $this->dom->createElementNS(self::NS_INVOICE, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $this->dom->appendChild($root);

        $this->cbc($root, 'CustomizationID', Invoice::CUSTOMIZATION_ID);
        $this->cbc($root, 'ID', $invoice->number);
        $this->cbc($root, 'IssueDate', $invoice->issueDate->format('Y-m-d'));
        $this->cbc($root, 'DueDate', $invoice->dueDate?->format('Y-m-d'));
        $this->cbc($root, 'InvoiceTypeCode', '380');
        foreach ($invoice->notes as $note) {
            $this->cbc($root, 'Note', $note);
        }
        $this->cbc($root, 'DocumentCurrencyCode', $invoice->currency);
        $this->cbc($root, 'TaxCurrencyCode', $invoice->taxCurrency());

        if ($invoice->precedingNumber !== null) {
            $reference = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
            $this->cbc($reference, 'ID', $invoice->precedingNumber);
            $this->cbc($reference, 'IssueDate', $invoice->precedingDate?->format('Y-m-d'));
        }

        $this->party($this->cac($root, 'AccountingSupplierParty'), $invoice->seller, true);
        $this->party($this->cac($root, 'AccountingCustomerParty'), $invoice->buyer, false);

        if ($invoice->paymentMeans !== null) {
            $means = $this->cac($root, 'PaymentMeans');
            $this->cbc($means, 'PaymentMeansCode', $invoice->paymentMeans->code);
            if ($invoice->paymentMeans->iban !== null) {
                $account = $this->cac($means, 'PayeeFinancialAccount');
                $this->cbc($account, 'ID', $invoice->paymentMeans->iban);
                $this->cbc($account, 'Name', $invoice->paymentMeans->accountName);
                if ($invoice->paymentMeans->bic !== null) {
                    $this->cbc($this->cac($account, 'FinancialInstitutionBranch'), 'ID', $invoice->paymentMeans->bic);
                }
            }
        }

        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->amount($taxTotal, 'TaxAmount', $invoice->taxTotalCents);
        foreach ($invoice->taxSubtotals as $subtotal) {
            $element = $this->cac($taxTotal, 'TaxSubtotal');
            $this->amount($element, 'TaxableAmount', $subtotal->taxableCents);
            $this->amount($element, 'TaxAmount', $subtotal->taxCents);
            $category = $this->cac($element, 'TaxCategory');
            $this->cbc($category, 'ID', $subtotal->category);
            $this->cbc($category, 'Percent', $subtotal->rate === null ? null : Money::trimDecimal($subtotal->rate));
            $this->cbc($category, 'TaxExemptionReasonCode', $subtotal->exemptionCode);
            $this->cbc($category, 'TaxExemptionReason', $subtotal->exemptionReason);
            $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');
        }
        if ($invoice->taxCurrency() !== null) {
            // BT-111: a second TaxTotal with only the VAT in RON.
            $this->amount($this->cac($root, 'TaxTotal'), 'TaxAmount', (int) $invoice->taxTotalRonCents, 'RON');
        }

        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($totals, 'LineExtensionAmount', $invoice->lineTotalCents());
        $this->amount($totals, 'TaxExclusiveAmount', $invoice->lineTotalCents());
        $this->amount($totals, 'TaxInclusiveAmount', $invoice->totalCents());
        if ($invoice->prepaidCents !== 0) {
            $this->amount($totals, 'PrepaidAmount', $invoice->prepaidCents);
        }
        $this->amount($totals, 'PayableAmount', $invoice->payableCents());

        foreach ($invoice->lines as $line) {
            $this->line($root, $line);
        }

        return (string) $this->dom->saveXML();
    }

    private function party(DOMElement $parent, Party $party, bool $seller): void
    {
        $element = $this->cac($parent, 'Party');
        if ($party->tradeName !== null && $party->tradeName !== '') {
            $this->cbc($this->cac($element, 'PartyName'), 'Name', $party->tradeName);
        }

        $address = $this->cac($element, 'PostalAddress');
        $this->cbc($address, 'StreetName', $party->street);
        $this->cbc($address, 'AdditionalStreetName', $party->additionalStreet);
        $this->cbc($address, 'CityName', $party->city);
        $this->cbc($address, 'PostalZone', $party->postcode);
        $this->cbc($address, 'CountrySubentity', $party->county);
        $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', $party->country);

        if ($party->vatId !== null && $party->vatId !== '') {
            $scheme = $this->cac($element, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $party->vatId);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }
        if ($seller && $party->taxRegistrationId !== null && $party->taxRegistrationId !== '') {
            // BT-32: tax registration of a seller that is not a VAT payer.
            $scheme = $this->cac($element, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $party->taxRegistrationId);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'FC');
        }

        $legal = $this->cac($element, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', $party->name);
        $this->cbc($legal, 'CompanyID', $party->legalId);
        if ($seller) {
            $this->cbc($legal, 'CompanyLegalForm', $party->legalForm);
        }

        if ($party->contactName !== null || $party->phone !== null || $party->email !== null) {
            $contact = $this->cac($element, 'Contact');
            $this->cbc($contact, 'Name', $party->contactName);
            $this->cbc($contact, 'Telephone', $party->phone);
            $this->cbc($contact, 'ElectronicMail', $party->email);
        }
    }

    private function line(DOMElement $root, Line $line): void
    {
        $element = $this->cac($root, 'InvoiceLine');
        $this->cbc($element, 'ID', $line->id);
        $this->cbc($element, 'Note', $line->note);
        $this->cbc($element, 'InvoicedQuantity', (string) $line->quantity)?->setAttribute('unitCode', $line->unitCode);
        $this->amount($element, 'LineExtensionAmount', $line->amountCents);

        $item = $this->cac($element, 'Item');
        $this->cbc($item, 'Description', $line->description);
        $this->cbc($item, 'Name', $line->name);
        if ($line->sellerItemId !== null) {
            $this->cbc($this->cac($item, 'SellersItemIdentification'), 'ID', $line->sellerItemId);
        }
        $category = $this->cac($item, 'ClassifiedTaxCategory');
        $this->cbc($category, 'ID', $line->category);
        $this->cbc($category, 'Percent', $line->rate === null ? null : Money::trimDecimal($line->rate));
        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');

        $this->amount($this->cac($element, 'Price'), 'PriceAmount', $line->priceCents());
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->dom->createElementNS(self::NS_CAC, 'cac:' . $name);
        $parent->appendChild($element);

        return $element;
    }

    private function cbc(DOMElement $parent, string $name, ?string $value): ?DOMElement
    {
        if ($value === null || $value === '') {
            return null;
        }
        $element = $this->dom->createElementNS(self::NS_CBC, 'cbc:' . $name);
        $element->appendChild($this->dom->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private function amount(DOMElement $parent, string $name, int $cents, ?string $currency = null): void
    {
        $this->cbc($parent, $name, Money::format($cents))?->setAttribute('currencyID', $currency ?? $this->currency);
    }
}
