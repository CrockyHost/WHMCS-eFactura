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

require_once dirname(__DIR__) . '/UblScenarios.php';

use WHMCS\Module\Addon\Efactura\Exchange\ExchangeRate;
use WHMCS\Module\Addon\Efactura\Exchange\VatInRon;
use WHMCS\Module\Addon\Efactura\Fiscal\References;
use WHMCS\Module\Addon\Efactura\Romania\Cnp;
use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Ubl\Invoice;
use WHMCS\Module\Addon\Efactura\Ubl\Line;
use WHMCS\Module\Addon\Efactura\Ubl\Party;
use WHMCS\Module\Addon\Efactura\Ubl\PaymentMeans;
use WHMCS\Module\Addon\Efactura\Ubl\TaxSubtotal;
use WHMCS\Module\Addon\Efactura\Ubl\Text;
use WHMCS\Module\Addon\Efactura\Ubl\UblWriter;
use WHMCS\Module\Addon\Efactura\Ubl\Validator;

$fixtures = dirname(__DIR__) . '/fixtures/ubl';

/**
 * The rules the local validator reports for an invoice.
 *
 * @return list<string>
 */
$rules = static fn (Invoice $invoice): array => array_map(static fn (array $issue): string => $issue['rule'], (new Validator())->validate($invoice));

/**
 * A copy of the B2B scenario with some constructor arguments replaced.
 */
$variant = static function (array $changes): Invoice {
    $base = UblScenarios::all()['b2b-ron-paid'];
    $arguments = [
        'number' => $base->number, 'issueDate' => $base->issueDate, 'currency' => $base->currency, 'seller' => $base->seller,
        'buyer' => $base->buyer, 'lines' => $base->lines, 'taxSubtotals' => $base->taxSubtotals, 'taxTotalCents' => $base->taxTotalCents,
        'prepaidCents' => $base->prepaidCents, 'dueDate' => $base->dueDate, 'taxTotalRonCents' => $base->taxTotalRonCents,
        'notes' => $base->notes, 'paymentMeans' => $base->paymentMeans, 'references' => $base->references,
    ];

    return new Invoice(...array_merge($arguments, $changes));
};

$buyer = static fn (array $changes): Party => new Party(...array_merge([
    'name' => 'CLIENT TEST S.R.L.', 'street' => 'Strada Test 1', 'city' => 'Craiova', 'country' => 'RO',
    'county' => 'RO-DJ', 'legalId' => '87654329',
], $changes));

return [
    'money: parsing, formatting, VAT and conversion' => static function (): void {
        Assert::same(12100, Money::cents('121.00'));
        Assert::same(-550, Money::cents('-5.5'));
        Assert::same(13, Money::cents('0.125'));
        Assert::same(-13, Money::cents('-0.125'));
        Assert::same('-0.05', Money::format(-5));
        Assert::same('1234.50', Money::format(123450));
        Assert::same(2077, Money::percent(9890, '21.000'));
        Assert::same(1048, Money::percent(4990, '21'));
        Assert::same(-210, Money::percent(-1000, '21'));
        Assert::same(11621, Money::multiply(2287, '5.0812'));
        Assert::same('21', Money::trimDecimal('21.000'));
        Assert::same('9.5', Money::trimDecimal('9.50'));
    },
    'CNP check digit' => static function (): void {
        Assert::true(Cnp::isValid('1960131410041'), 'a valid CNP');
        Assert::false(Cnp::isValid('1960131410045'), 'wrong check digit');
        Assert::false(Cnp::isValid(Cnp::UNKNOWN), '13 zeros are not a CNP');
        Assert::false(Cnp::isValid('123'));
    },
    'county from WHMCS free text' => static function (): void {
        $cases = [
            'Dolj' => 'RO-DJ', 'DOLJ' => 'RO-DJ', 'Jud. Dolj' => 'RO-DJ', 'Județul Dolj' => 'RO-DJ', 'RO-DJ' => 'RO-DJ', 'DJ' => 'RO-DJ',
            'Iași' => 'RO-IS', 'Iasi' => 'RO-IS', 'Brașov County' => 'RO-BV', 'Constanța' => 'RO-CT', 'Maramures' => 'RO-MM',
            'Bistrita Nasaud' => 'RO-BN', 'Caraș-Severin' => 'RO-CS', 'Satu-Mare' => 'RO-SM', 'Ilfov' => 'RO-IF',
            'București' => 'RO-B', 'Bucuresti' => 'RO-B', 'Bucharest' => 'RO-B', 'Municipiul București' => 'RO-B', 'Sector 3' => 'RO-B',
            'Romania' => null, '-' => null, 'kkkk' => null, 'NY' => null, '' => null,
        ];
        foreach ($cases as $text => $code) {
            Assert::same($code, Counties::fromText((string) $text), "'{$text}'");
        }
    },
    'Bucharest sector from city, address or county' => static function (): void {
        Assert::same('SECTOR3', Counties::sectorFromText('Sector 3'));
        Assert::same('SECTOR1', Counties::sectorFromText('Bucuresti', 'Str. Victoriei 1, sectorul 1'));
        Assert::same('SECTOR6', Counties::sectorFromText('București, Sect. 6'));
        Assert::same('SECTOR2', Counties::sectorFromText('SECTOR2'));
        Assert::same(null, Counties::sectorFromText('București', 'Strada Sectorului 12'));
        Assert::same(null, Counties::sectorFromText('Sector 7'));
    },
    'item texts: no HTML, normal spaces, CIUS-RO lengths' => static function (): void {
        $short = Text::item('Hosting <b>Start</b>  - a.ro');
        Assert::same(['name' => 'Hosting Start - a.ro', 'description' => null, 'note' => null], $short);
        // As stored by WHMCS: HTML-escaped.
        Assert::same('Taxă <reducere 10%> VPS & "x"', Text::item('Taxă &lt;reducere 10%&gt; &lt;b&gt;VPS&lt;/b&gt; &amp; &quot;x&quot;')['name']);
        Assert::same('Audit securitate & performanță', Text::clean('Audit securitate &amp; performanță'));

        $multi = Text::item("Găzduire Pro - a.ro (01/10/2026 - 31/10/2026)\r\n  Opțiune:   SSL &amp; backup \n\n");
        Assert::same('Găzduire Pro - a.ro (01/10/2026 - 31/10/2026)', $multi['name']);
        Assert::same('Găzduire Pro - a.ro (01/10/2026 - 31/10/2026); Opțiune: SSL & backup', $multi['description']);

        $long = Text::item(str_repeat('cuvânt ', 40) . "\n" . str_repeat('detaliu lung ', 40));
        Assert::true(mb_strlen($long['name']) <= 100 && str_ends_with($long['name'], '...'));
        Assert::true(mb_strlen((string) $long['description']) <= 200);
        Assert::true(mb_strlen((string) $long['note']) <= 300 && $long['note'] !== '');
    },
    'exchange rate per unit with a multiplier' => static function (): void {
        Assert::same('5.0812', ExchangeRate::perUnit('5.0812', 1));
        Assert::same('0.012345', ExchangeRate::perUnit('1.2345', 100));
    },
    'the VAT in RON and its mention, for the XML and the PDF' => static function (): void {
        $rate = new ExchangeRate('EUR', '5.0801', new DateTimeImmutable('2026-10-12'), 'BNR');
        $vat = VatInRon::of('eur', 2100, $rate);
        Assert::same(10668, $vat->taxRonCents);
        Assert::same('Curs BNR 1 EUR = 5,0801 RON din 12.10.2026; TVA: 106,68 RON', $vat->note());
        // A storno: the VAT of the invoice it corrects, negative.
        Assert::same('Curs BNR 1 EUR = 5,0801 RON din 12.10.2026; TVA: -106,68 RON', VatInRon::of('EUR', -2100, $rate)->note());
    },
    'every scenario passes the local validator' => static function () use ($rules): void {
        foreach (UblScenarios::all() as $name => $invoice) {
            Assert::same([], $rules($invoice), $name);
        }
    },
    'the XML matches the reviewed fixtures' => static function () use ($fixtures): void {
        $update = getenv('EFACTURA_UPDATE_FIXTURES') === '1';
        $created = [];
        foreach (UblScenarios::all() as $name => $invoice) {
            $xml = (new UblWriter())->write($invoice);
            $file = $fixtures . '/' . $name . '.xml';
            if ($update || !is_file($file)) {
                file_put_contents($file, $xml);
                $created[] = $name;
                continue;
            }
            Assert::same(file_get_contents($file), $xml, $name);
        }
        if ($created !== []) {
            Assert::skip('fixtures written, review them: ' . implode(', ', $created));
        }
    },
    'the fixtures pass the official UBL 2.1 XSD and the CIUS-RO 1.0.9 Schematron' => static function () use ($fixtures): void {
        if (!DevValidators::available()) {
            Assert::skip('validation tools not installed (see crocky-efactura-env/tools)');
        }
        $documents = [];
        foreach (glob($fixtures . '/*.xml') ?: [] as $file) {
            $documents[basename($file)] = (string) file_get_contents($file);
        }
        Assert::true(count($documents) >= 7, 'fixtures missing');
        foreach (DevValidators::errorsMany($documents) as $name => $errors) {
            Assert::same([], $errors, $name);
        }
    },
    'the XML is escaped and has no empty elements' => static function (): void {
        $xml = (new UblWriter())->write(UblScenarios::all()['b2b-ron-paid']);
        Assert::true(str_contains($xml, 'CLIENT TEST &amp; ASOCIAȚII S.R.L.'));
        Assert::true(str_contains($xml, '&lt;reducere 10%&gt;'));
        Assert::same(0, preg_match('/<cbc:[A-Za-z]+(\s[^>]*)?\/>/', $xml), 'empty element');
        Assert::true(str_contains($xml, '<cbc:CustomizationID>' . Invoice::CUSTOMIZATION_ID . '</cbc:CustomizationID>'));
        Assert::false(str_starts_with($xml, "\xEF\xBB\xBF"), 'no BOM');
    },
    'a storno has negative quantities and amounts, positive prices and the reference' => static function (): void {
        $xml = new SimpleXMLElement((new UblWriter())->write(UblScenarios::all()['storno-full']));
        $xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        Assert::same('FX-0001', (string) $xml->xpath('//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')[0]);
        Assert::same('2026-10-05', (string) $xml->xpath('//cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate')[0]);
        Assert::same(['-1', '-1', '1'], array_map('strval', $xml->xpath('//cac:InvoiceLine/cbc:InvoicedQuantity')));
        Assert::same(['49.90', '59.00', '10.00'], array_map('strval', $xml->xpath('//cac:InvoiceLine/cac:Price/cbc:PriceAmount')));
        Assert::same('-119.67', (string) $xml->xpath('//cac:LegalMonetaryTotal/cbc:PayableAmount')[0]);
    },
    'missing county and Bucharest without sector are reported' => static function () use ($variant, $buyer, $rules): void {
        Assert::true(in_array('BR-RO-110', $rules($variant(['buyer' => $buyer(['county' => null])])), true));
        Assert::true(in_array('BR-RO-110', $rules($variant(['buyer' => $buyer(['county' => 'Dolj'])])), true));
        Assert::true(in_array('BR-RO-100', $rules($variant(['buyer' => $buyer(['county' => 'RO-B', 'city' => 'București'])])), true));
    },
    'buyer identifiers are required and checked' => static function () use ($variant, $buyer, $rules): void {
        Assert::true(in_array('BR-RO-120', $rules($variant(['buyer' => $buyer(['legalId' => null])])), true));
        Assert::true(in_array('BR-RO-ID', $rules($variant(['buyer' => $buyer(['legalId' => '87654321'])])), true));
        Assert::true(in_array('BR-RO-ID', $rules($variant(['buyer' => $buyer(['legalId' => '1960131410045'])])), true));
        Assert::same([], $rules($variant(['buyer' => $buyer(['legalId' => '0000000000000'])])));
        Assert::true(in_array('BR-CO-09', $rules($variant(['buyer' => $buyer(['vatId' => '87654329'])])), true));
    },
    'a VAT rounding of 1.00 or more is stopped, not corrected' => static function () use ($variant, $rules): void {
        Assert::same([], $rules($variant(['taxSubtotals' => [new TaxSubtotal('S', '21', 9890, 2176)], 'taxTotalCents' => 2176, 'prepaidCents' => 12066])), '0.99 off is tolerated');
        $found = $rules($variant(['taxSubtotals' => [new TaxSubtotal('S', '21', 9890, 2177)], 'taxTotalCents' => 2177, 'prepaidCents' => 12067]));
        Assert::true(in_array('BR-S-09', $found, true));
        Assert::true(in_array('BR-CO-14', $rules($variant(['taxTotalCents' => 2078, 'prepaidCents' => 11968])), true));
    },
    'lengths, sign of the lines, due date, RON VAT and IBAN are checked' => static function () use ($variant, $rules): void {
        $longName = new Line('1', str_repeat('x', 101), 9890, 'S', '21');
        Assert::true(in_array('BR-RO-L100', $rules($variant(['lines' => [$longName]])), true));
        $wrongSign = new Line('1', 'Discount', -9890, 'S', '21', quantity: 1);
        Assert::true(in_array('BR-27', $rules($variant(['lines' => [$wrongSign], 'taxSubtotals' => [new TaxSubtotal('S', '21', -9890, -2077)], 'taxTotalCents' => -2077, 'prepaidCents' => 0])), true));
        Assert::true(in_array('BR-CO-25', $rules($variant(['prepaidCents' => 0])), true));
        Assert::true(in_array('BR-RO-030', $rules($variant(['currency' => 'EUR'])), true));
        Assert::true(in_array('BR-61', $rules($variant(['paymentMeans' => new PaymentMeans('30')])), true));
        Assert::same([], $rules($variant(['paymentMeans' => new PaymentMeans('42')])), 'code 42 does not require an IBAN');
    },
    'the references go where the UBL schema puts them' => static function (): void {
        $dom = new DOMDocument();
        $dom->loadXML((new UblWriter())->write(UblScenarios::all()['storno-b2g-references']));
        $names = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $names[] = $node->localName;
            }
        }
        $from = array_search('AccountingCost', $names, true);
        Assert::same(['AccountingCost', 'OrderReference', 'BillingReference', 'ReceiptDocumentReference', 'ContractDocumentReference', 'ProjectReference', 'AccountingSupplierParty', 'AccountingCustomerParty'], array_slice($names, (int) $from, 8));
        Assert::same('DocumentCurrencyCode', $names[(int) $from - 1]);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $value = static fn (string $path): string => (string) $xpath->evaluate('string(' . $path . ')');
        Assert::same('PRJ-2026-07 Digitalizare', $value('/*/cac:ProjectReference/cbc:ID'));
        Assert::same('Contract 77/12.01.2026', $value('/*/cac:ContractDocumentReference/cbc:ID'));
        Assert::same('CMD-4521', $value('/*/cac:OrderReference/cbc:ID'));
        Assert::same('NIR 318/05.10.2026', $value('/*/cac:ReceiptDocumentReference/cbc:ID'));
        Assert::same('20.01.30 Servicii IT', $value('/*/cbc:AccountingCost'));
        $party = $xpath->query('/*/cac:AccountingCustomerParty/cac:Party')->item(0);
        Assert::same('PartyIdentification', $party->firstElementChild->localName, 'first in the buyer party');
        Assert::same('CLIENT-0042', $value('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID'));
        Assert::false($xpath->query('//cac:PartyIdentification/cbc:ID[@schemeID]')->length > 0, 'no scheme identifier');
        Assert::same(0, $xpath->query('/*/cac:AccountingSupplierParty//cac:PartyIdentification')->length, 'not for the seller');

        $plain = (new UblWriter())->write(UblScenarios::all()['b2b-ron-paid']);
        foreach (['AccountingCost', 'OrderReference', 'ReceiptDocumentReference', 'ContractDocumentReference', 'ProjectReference', 'PartyIdentification'] as $element) {
            Assert::false(str_contains($plain, ':' . $element . '>'), $element . ' only when there is a value');
        }
    },
    'the lengths of the references follow CIUS-RO' => static function () use ($variant, $rules): void {
        $with = static fn (array $references): array => $rules($variant(['references' => $references]));
        Assert::same([], $with(['BT-19' => str_repeat('x', 100), 'BT-12' => str_repeat('x', 200), 'BT-13' => str_repeat('x', 200), 'BT-15' => str_repeat('x', 200)]));
        Assert::same(['BR-RO-L100'], $with(['BT-19' => str_repeat('x', 101)]));
        Assert::same(['BR-RO-L200', 'BR-RO-L200', 'BR-RO-L200'], $with(['BT-12' => str_repeat('x', 201), 'BT-13' => str_repeat('x', 201), 'BT-15' => str_repeat('ă', 201)]));
        // CIUS-RO sets no length for the project reference and the buyer identifier.
        Assert::same([], $with(['BT-11' => str_repeat('x', 300), 'BT-46' => str_repeat('x', 300)]));
    },
    'references typed by an admin: one line each, known terms only' => static function (): void {
        Assert::same(
            ['BT-12' => 'Contract 77 / 2026', 'BT-46' => 'CLIENT-0042'],
            References::normalize(['BT-46' => ' CLIENT-0042 ', 'BT-12' => "Contract\t77\r\n/ 2026", 'BT-13' => '   ', 'BT-99' => 'x', 'BT-15' => ['x'], 'bt11' => 'y'])
        );
        Assert::same(['BT-19'], References::tooLong(['BT-19' => str_repeat('x', 101), 'BT-12' => str_repeat('x', 200)]));
        Assert::same(['BT-11', 'BT-46'], References::tooLong(['BT-11' => str_repeat('x', 201), 'BT-46' => str_repeat('x', 201)]), 'the limit of the addon');
        Assert::same(null, References::encode([]));
        Assert::same('{"BT-12":"C/1 & 2 ă"}', References::encode(['BT-12' => 'C/1 & 2 ă']));
    },
];
