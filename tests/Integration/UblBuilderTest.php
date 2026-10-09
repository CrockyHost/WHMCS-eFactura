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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Ubl\BuildResult;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;

/*
 * The XML generated from WHMCS invoices (fictive seller and clients, in the
 * rolled-back test transaction): amounts identical to WHMCS, buyer data from
 * the client profile, problems reported instead of guessed.
 */

/** @var array<string, string> generated XML, validated with the official validators at the end */
$generated = [];

$setup = static function (): void {
    Settings::save([
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 1,
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_trade_name' => 'Exemplu Hosting', 'company_cui' => '12345674',
        'company_vat_payer' => true, 'company_vat_on_collection' => false, 'company_reg_com' => 'J40/1234/2020', 'company_share_capital' => '200 RON',
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B', 'company_postcode' => '030000',
        'company_email' => 'facturare@exemplu-hosting.invalid', 'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890',
        'client_field_cui' => 'tax_id', 'client_field_cnp' => '', 'client_field_county' => 'state', 'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    NumberingLock::overridePaymentTimeout(1);
};

$client = static function (array $overrides = []): int {
    $result = localAPI('AddClient', $overrides + [
        'firstname' => 'Test', 'lastname' => 'eFactura', 'email' => 'efactura-ubl-' . uniqid() . '@example.invalid',
        'address1' => 'Strada Test nr. 1', 'city' => 'Craiova', 'state' => 'Dolj', 'postcode' => '200000', 'country' => 'RO',
        'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2, 'noemail' => true, 'skipvalidation' => true,
    ]);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient: ' . ($result['message'] ?? ''));
    }

    return (int) $result['clientid'];
};

/**
 * @param list<array{0: string, 1: float, 2?: bool}> $items description, amount, taxed
 */
$invoice = static function (int $clientId, array $items, string $gateway = 'banktransfer', string $notes = ''): int {
    $params = ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => $gateway,
        'date' => date('Y-m-d'), 'duedate' => date('Y-m-d', strtotime('+14 days')), 'notes' => $notes];
    foreach (array_values($items) as $i => $item) {
        $params['itemdescription' . ($i + 1)] = $item[0];
        $params['itemamount' . ($i + 1)] = $item[1];
        $params['itemtaxed' . ($i + 1)] = $item[2] ?? true;
    }

    return (int) localAPI('CreateInvoice', $params)['invoiceid'];
};

$pay = static function (int $invoiceId): void {
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'T-' . uniqid(), 'gateway' => 'banktransfer',
        'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);
};

$build = static function (int $invoiceId): BuildResult {
    $document = Addon::documents()->forInvoice($invoiceId) ?? throw new RuntimeException("no document for invoice {$invoiceId}");

    return Addon::documentBuilder()->build($document);
};

$rules = static fn (BuildResult $result): array => array_map(static fn (array $issue): string => $issue['rule'], $result->issues);

/**
 * Checks that the XML carries exactly the WHMCS amounts of the invoice.
 */
$sameAmounts = static function (BuildResult $result, int $invoiceId, int $sign = 1): void {
    Assert::true($result->ok(), 'not generated: ' . json_encode($result->issues, JSON_UNESCAPED_UNICODE));
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    $xml = new SimpleXMLElement((string) $result->xml);
    $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $value = static fn (string $path): int => Money::cents((string) ($xml->xpath($path)[0] ?? 'NaN'));
    $cur = (string) $xml->xpath('//cbc:DocumentCurrencyCode')[0];

    Assert::same($sign * Money::cents($invoice->subtotal), $value('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount'), 'subtotal');
    Assert::same($sign * Money::cents($invoice->tax), $value("//cac:TaxTotal/cbc:TaxAmount[@currencyID='{$cur}']"), 'VAT');
    Assert::same($sign * Money::cents($invoice->total), $value('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'), 'total');
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->pluck('amount')->map(static fn ($a): int => $sign * Money::cents((string) $a))->all();
    $lines = array_map(static fn ($node): int => Money::cents((string) $node), $xml->xpath('//cac:InvoiceLine/cbc:LineExtensionAmount'));
    if ($sign === 1 || count($lines) === count($items)) {
        Assert::same($items, $lines, 'line amounts');
    }
};

return [
    'a paid invoice to a Romanian company carries the WHMCS amounts' => static function () use ($setup, $client, $invoice, $pay, $build, $sameAmounts, &$generated): void {
        $setup();
        $clientId = $client(['companyname' => 'CLIENT TEST & ASOCIATII SRL', 'tax_id' => 'RO 87654329', 'state' => 'Jud. Cluj', 'city' => 'Cluj-Napoca']);
        $id = $invoice($clientId, [
            ["Găzduire Business - client-test.ro (09/10/2026 - 08/11/2026)\nSpațiu: 20 GB &amp; SSL", 49.90],
            ['Înregistrare domeniu - client-test.ro - 1 an/ani', 59.00],
            ['Cod promoțional: TEST10', -10.00],
            ['Taxă de instalare <b>VPS</b>', 0.33],
        ], 'banktransfer', "Mulțumim!\nPlata în termen de 14 zile.");
        $pay($id);
        $result = $build($id);
        $sameAmounts($result, $id);
        Assert::same('b2b', $result->buyerType);
        $xml = (string) $result->xml;
        Assert::true(str_contains($xml, '<cbc:CompanyID>RO87654329</cbc:CompanyID>'), 'BT-48 with RO');
        Assert::true(str_contains($xml, '<cbc:CompanyID>87654329</cbc:CompanyID>'), 'BT-47 without RO');
        Assert::true(str_contains($xml, '<cbc:CountrySubentity>RO-CJ</cbc:CountrySubentity>'));
        Assert::true(str_contains($xml, 'Spațiu: 20 GB &amp; SSL'), 'entities decoded once, escaped by DOM');
        Assert::true(str_contains($xml, '<cbc:Name>Taxă de instalare VPS</cbc:Name>'), 'no HTML');
        Assert::true(str_contains($xml, '<cbc:PaymentMeansCode>30</cbc:PaymentMeansCode>') && str_contains($xml, 'RO49AAAA1B31007593840000'));
        Assert::true(str_contains($xml, '<cbc:PayableAmount currencyID="RON">0.00</cbc:PayableAmount>'));
        $generated['whmcs-b2b-paid'] = $xml;
    },
    'an individual in Bucharest gets the sector and 13 zeros' => static function () use ($setup, $client, $invoice, $pay, $build, $sameAmounts, &$generated): void {
        $setup();
        $clientId = $client(['firstname' => 'Maria', 'lastname' => 'Ionescu', 'state' => 'București', 'city' => 'Bucuresti', 'address1' => 'Str. Florilor 5, Sector 2']);
        $id = $invoice($clientId, [['Găzduire Start - maria.ro', 114.70]], 'stripe');
        $pay($id);
        $result = $build($id);
        $sameAmounts($result, $id);
        Assert::same('b2c', $result->buyerType);
        Assert::true(str_contains((string) $result->xml, '<cbc:CityName>SECTOR2</cbc:CityName>'));
        Assert::true(str_contains((string) $result->xml, '<cbc:CompanyID>0000000000000</cbc:CompanyID>'));
        Assert::true(str_contains((string) $result->xml, '<cbc:PaymentMeansCode>48</cbc:PaymentMeansCode>'));
        $generated['whmcs-b2c-bucharest'] = (string) $result->xml;
    },
    'an EUR invoice issued early converts the VAT at the BNR rate of the previous banking day' => static function () use ($setup, $client, $invoice, $build, $sameAmounts, &$generated): void {
        $setup();
        // Cached rates for the 10 days before today; the most recent one before today must be used.
        for ($days = 10; $days >= 1; $days--) {
            $date = Clock::today()->modify("-{$days} days");
            if ((int) $date->format('N') < 6) {
                Capsule::table(BnrRates::TABLE)->insertOrIgnore(['source' => 'BNR', 'currency' => 'EUR', 'rate_date' => $date->format('Y-m-d'),
                    'value' => sprintf('5.08%02d', $days), 'multiplier' => 1, 'fetched_at' => date('Y-m-d H:i:s')]);
            }
        }
        $previous = Capsule::table(BnrRates::TABLE)->where('currency', 'EUR')->where('rate_date', '<', Clock::today()->format('Y-m-d'))->orderByDesc('rate_date')->first();
        $clientId = $client(['companyname' => 'CLIENT EUR SRL', 'tax_id' => '87654329', 'currency' => 1]);
        $id = $invoice($clientId, [['Server dedicat Pro - srv1.client-test.ro', 108.90]]);
        Addon::earlyIssue()->issue($id, 1, 5);
        $result = $build($id);
        $sameAmounts($result, $id);
        $tax = Money::cents((string) Capsule::table('tblinvoices')->where('id', $id)->value('tax'));
        $xml = (string) $result->xml;
        Assert::same(Money::trimDecimal((string) $previous->value), $result->exchangeRate?->rate);
        Assert::true(str_contains($xml, '<cbc:TaxCurrencyCode>RON</cbc:TaxCurrencyCode>'));
        Assert::true(str_contains($xml, '<cbc:TaxAmount currencyID="RON">' . Money::format(Money::multiply($tax, (string) $previous->value)) . '</cbc:TaxAmount>'));
        Assert::true(str_contains($xml, 'Curs BNR 1 EUR = ' . str_replace('.', ',', Money::trimDecimal((string) $previous->value))));
        Assert::true(str_contains($xml, '<cbc:DueDate>'), 'unpaid: due date');
        Assert::false(str_contains($xml, '<cbc:CompanyID>RO87654329'), 'no RO prefix typed: not a VAT payer');
        $generated['whmcs-eur-early'] = $xml;
    },
    'a full storno negates the invoice and a partial one has a single line' => static function () use ($setup, $client, $invoice, $pay, $build, $sameAmounts, &$generated): void {
        $setup();
        $clientId = $client(['companyname' => 'CLIENT STORNO SRL', 'tax_id' => 'RO87654329']);
        $id = $invoice($clientId, [['Găzduire Business', 49.90], ['Domeniu', 59.00], ['Reducere', -10.00]]);
        $pay($id);
        $original = Addon::documents()->forInvoice($id);
        $note = Capsule::table('tblbillingnotes')->insertGetId(['note_type' => 'credit', 'custom_number' => '', 'client_id' => $clientId,
            'date_issued' => date('Y-m-d'), 'subtotal' => 25.00, 'tax' => 5.25, 'tax2' => 0, 'total' => 30.25, 'taxrate' => 21, 'taxrate2' => 0,
            'status' => 'issued', 'notes' => '', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $storno = static function (string $reason, ?int $noteId, string $number) use ($id, $clientId, $original): object {
            $docId = Capsule::table(Document::TABLE)->insertGetId(['dedupe_key' => 'test:' . $number, 'kind' => Document::KIND_STORNO, 'reason' => $reason,
                'invoice_id' => $id, 'billing_note_id' => $noteId, 'original_document_id' => $original->id, 'client_id' => $clientId,
                'number' => $number, 'issue_date' => date('Y-m-d'), 'state' => Document::STATE_SCHEDULED, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

            return Capsule::table(Document::TABLE)->where('id', $docId)->first();
        };

        $full = Addon::documentBuilder()->build($storno(DocumentBuilder::REASON_REFUND_FULL, null, 'TEST-S1'));
        $sameAmounts($full, $id, -1);
        Assert::true(str_contains((string) $full->xml, '<cbc:ID>' . $original->number . '</cbc:ID>'), 'BT-25');
        Assert::false(str_contains((string) $full->xml, 'PaymentMeans'));
        $generated['whmcs-storno-full'] = (string) $full->xml;

        $partial = Addon::documentBuilder()->build($storno(DocumentBuilder::REASON_REFUND_PARTIAL, $note, 'TEST-S2'));
        Assert::true($partial->ok(), json_encode($partial->issues, JSON_UNESCAPED_UNICODE));
        $xml = (string) $partial->xml;
        Assert::true(str_contains($xml, '<cbc:Name>Stornare parțială factura ' . $original->number . '</cbc:Name>'));
        Assert::true(str_contains($xml, '<cbc:LineExtensionAmount currencyID="RON">-25.00</cbc:LineExtensionAmount>'));
        Assert::true(str_contains($xml, '<cbc:TaxAmount currencyID="RON">-5.25</cbc:TaxAmount>'));
        Assert::true(str_contains($xml, '<cbc:PayableAmount currencyID="RON">-30.25</cbc:PayableAmount>'));
        $generated['whmcs-storno-partial'] = $xml;
    },
    'missing or unrecognized client data stops the document with a clear reason' => static function () use ($setup, $client, $invoice, $pay, $build, $rules): void {
        $setup();
        $cases = [
            'MAP-CUI' => ['companyname' => 'FIRMA FARA CUI SRL', 'tax_id' => ''],
            'MAP-COUNTY' => ['state' => 'Romania'],
            'MAP-SECTOR' => ['state' => 'Bucuresti', 'city' => 'Bucuresti', 'address1' => 'Strada Lunga 1'],
        ];
        foreach ($cases as $rule => $data) {
            $id = $invoice($client($data), [['Găzduire', 10.00]]);
            $pay($id);
            $result = $build($id);
            Assert::false($result->ok(), $rule);
            Assert::true(in_array($rule, $rules($result), true), $rule . ': ' . json_encode($rules($result)));
            Assert::same(null, $result->xml);
            Assert::true($result->issues[0]['message'] !== '' && !str_starts_with($result->issues[0]['message'], 'map_'), 'a readable message');
        }
    },
    'an invalid CNP in the mapped custom field is reported, not replaced' => static function () use ($setup, $client, $invoice, $pay, $build, $rules): void {
        $setup();
        $fieldId = (int) Capsule::table('tblcustomfields')->insertGetId(['type' => 'client', 'relid' => 0, 'fieldname' => 'CNP', 'fieldtype' => 'text',
            'description' => '', 'fieldoptions' => '', 'regexpr' => '', 'adminonly' => '', 'required' => '', 'showorder' => '', 'showinvoice' => '', 'sortorder' => 0]);
        Settings::save(['client_field_cnp' => 'cf:' . $fieldId]);
        $clientId = $client();
        Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $clientId, 'value' => '1960131410045']);
        $id = $invoice($clientId, [['Găzduire', 10.00]]);
        $pay($id);
        Assert::true(in_array('MAP-CNP', $rules($build($id)), true));
        Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->update(['value' => '1960131410041']);
        $result = $build($id);
        Assert::true($result->ok(), json_encode($result->issues));
        Assert::true(str_contains((string) $result->xml, '<cbc:CompanyID>1960131410041</cbc:CompanyID>'));
    },
    'WHMCS amounts that break a BR-CO rule stop the document unchanged' => static function () use ($setup, $client, $invoice, $pay, $build, $rules): void {
        $setup();
        $id = $invoice($client(), [['Găzduire', 100.00]]);
        $pay($id);
        // VAT off by 1.00 from 21% of the base: ANAF rejects it (BR-S-09).
        Capsule::table('tblinvoices')->where('id', $id)->update(['tax' => 22.00, 'total' => 122.00]);
        $result = $build($id);
        Assert::true(in_array('BR-S-09', $rules($result), true), json_encode($rules($result)));
        Assert::same(2200, $result->invoice?->taxTotalCents, 'the WHMCS VAT is kept as it is');
        // Lines that do not add up to the WHMCS subtotal.
        Capsule::table('tblinvoices')->where('id', $id)->update(['subtotal' => 101.00, 'tax' => 21.00, 'total' => 122.00]);
        Assert::true(in_array('MAP-SUBTOTAL', $rules($build($id)), true));
    },
    'an untaxed line for a Romanian client and a missing IBAN are reported' => static function () use ($setup, $client, $invoice, $pay, $build, $rules): void {
        $setup();
        $id = $invoice($client(), [['Găzduire', 100.00], ['Taxă fără TVA', 5.00, false]]);
        $pay($id);
        Assert::true(in_array('MAP-VAT', $rules($build($id)), true));
        Settings::save(['iban_ron' => '']);
        $id = $invoice($client(), [['Găzduire', 100.00]]);
        $pay($id);
        Assert::true(in_array('BR-61', $rules($build($id)), true));
    },
    'BNR rates are imported and the rate published before the document date is used' => static function (): void {
        $xml = '<?xml version="1.0" encoding="utf-8"?><DataSet xmlns="https://www.bnr.ro/xsd"><Body><OrigCurrency>RON</OrigCurrency>'
            . '<Cube date="2026-10-09"><Rate currency="EUR">5.3414</Rate><Rate currency="HUF" multiplier="100">1.2345</Rate></Cube>'
            . '<Cube date="2026-10-08"><Rate currency="EUR">5.3470</Rate></Cube>'
            . '<Cube date="2026-10-02"><Rate currency="EUR">5.3501</Rate></Cube></Body></DataSet>';
        Capsule::table(BnrRates::TABLE)->where('currency', 'EUR')->where('rate_date', '>=', '2026-09-20')->where('rate_date', '<=', '2026-10-12')->delete();
        $fake = (new FakeTransport())->queue(new Response(200, [], $xml));
        $rates = new BnrRates($fake);
        Clock::freeze(new DateTimeImmutable('2026-10-12 10:00'));
        try {
            // Monday 12.10: the rate published on Friday 09.10.
            Assert::same('5.3414', $rates->forDocumentDate('EUR', new DateTimeImmutable('2026-10-12'))->rate);
            Assert::true(str_ends_with($fake->requests[0]->url, 'nbrfxrates10days.xml'));
            // Friday 09.10: the rate published on Thursday 08.10, from the cache.
            Assert::same('5.3470', $rates->forDocumentDate('EUR', new DateTimeImmutable('2026-10-09'))->rate);
            // Monday 05.10: Friday 02.10.
            Assert::same('5.3501', $rates->forDocumentDate('EUR', new DateTimeImmutable('2026-10-05'))->rate);
            Assert::same('0.012345', $rates->forDocumentDate('HUF', new DateTimeImmutable('2026-10-12'))->rate);
            Assert::same(1, count($fake->requests));
        } finally {
            Clock::freeze(null);
        }
    },
    'the generated documents pass the official UBL 2.1 XSD and the CIUS-RO 1.0.9 Schematron' => static function () use (&$generated): void {
        if (!DevValidators::available()) {
            Assert::skip('validation tools not installed (see crocky-efactura-env/tools)');
        }
        Assert::true(count($generated) >= 5, 'the generation tests above must run first');
        foreach (DevValidators::errorsMany($generated) as $name => $errors) {
            Assert::same([], $errors, $name);
        }
    },
];
