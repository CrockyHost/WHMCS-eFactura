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

use WHMCS\Module\Addon\Efactura\Ubl\Invoice;
use WHMCS\Module\Addon\Efactura\Ubl\Line;
use WHMCS\Module\Addon\Efactura\Ubl\Party;
use WHMCS\Module\Addon\Efactura\Ubl\PaymentMeans;
use WHMCS\Module\Addon\Efactura\Ubl\TaxSubtotal;
use WHMCS\Module\Addon\Efactura\Ubl\Text;

/**
 * The e-Factura scenarios of the addon, with FICTIVE data only: the seller
 * EXEMPLU HOSTING S.R.L. (CUI 12345674) and invented clients. They are the
 * source of tests/fixtures/ubl/*.xml and the only documents ever sent to
 * the public ANAF validator.
 */
final class UblScenarios
{
    public const FICTIVE_SELLER_VAT = 'RO12345674';

    public static function seller(): Party
    {
        return new Party(
            name: 'EXEMPLU HOSTING S.R.L.',
            street: 'Strada Exemplului nr. 10, et. 2',
            city: 'SECTOR3',
            country: 'RO',
            county: 'RO-B',
            postcode: '030000',
            tradeName: 'Exemplu Hosting',
            vatId: self::FICTIVE_SELLER_VAT,
            legalId: 'J40/1234/2020',
            legalForm: 'Capital social: 200 RON',
            contactName: 'Departament facturare',
            phone: '+40 700 000 000',
            email: 'facturare@exemplu-hosting.invalid',
        );
    }

    /**
     * @return array<string, Invoice>
     */
    public static function all(): array
    {
        $issued = new DateTimeImmutable('2026-10-05');
        $seller = self::seller();
        $company = new Party(
            name: 'CLIENT TEST & ASOCIAȚII S.R.L.',
            street: 'Bulevardul Eroilor nr. 1',
            city: 'Cluj-Napoca',
            country: 'RO',
            county: 'RO-CJ',
            postcode: '400129',
            vatId: 'RO87654329',
            legalId: '87654329',
            contactName: 'Ion Popescu',
            email: 'contabilitate@client-test.invalid',
        );
        $hosting = Text::item("Găzduire Business - client-test.ro (05/10/2026 - 04/11/2026)\nSpațiu: 20 GB SSD\nTrafic: nelimitat");
        $b2b = new Invoice(
            number: 'FX-0001',
            issueDate: $issued,
            currency: 'RON',
            seller: $seller,
            buyer: $company,
            lines: [
                new Line('1', $hosting['name'], 4990, 'S', '21', $hosting['description'], $hosting['note']),
                new Line('2', 'Înregistrare domeniu - client-test.ro - 1 an/ani', 5900, 'S', '21'),
                new Line('3', 'Cod promoțional: TEST10 <reducere 10%>', -1000, 'S', '21', quantity: -1),
            ],
            // WHMCS rounds the VAT per line: 10.48 + 12.39 - 2.10.
            taxSubtotals: [new TaxSubtotal('S', '21', 9890, 2077)],
            taxTotalCents: 2077,
            prepaidCents: 11967,
            paymentMeans: new PaymentMeans('30', 'RO49AAAA1B31007593840000', 'EXEMPLU HOSTING S.R.L.'),
        );

        $individual = new Party(
            name: 'Maria Ionescu',
            street: 'Strada Florilor nr. 5, bl. A2, ap. 14',
            city: 'SECTOR2',
            country: 'RO',
            county: 'RO-B',
            legalId: '0000000000000',
            email: 'maria.ionescu@exemplu.invalid',
        );
        $b2c = new Invoice(
            number: 'FX-0002',
            issueDate: $issued,
            currency: 'RON',
            seller: $seller,
            buyer: $individual,
            lines: [new Line('1', 'Găzduire Start - maria.ro (05/10/2026 - 04/10/2027)', 11470, 'S', '21')],
            taxSubtotals: [new TaxSubtotal('S', '21', 11470, 2409)],
            taxTotalCents: 2409,
            prepaidCents: 13879,
            paymentMeans: new PaymentMeans('48'),
        );

        $long = Text::item('Server dedicat Pro - srv1.client-test.ro (05/10/2026 - 04/11/2026)' . "\n"
            . str_repeat('Configurație: 2 x Intel Xeon, 128 GB RAM, 4 x 1.92 TB NVMe în RAID 10, 1 Gbps nelimitat, IPv4 dedicat, backup zilnic. ', 3));
        $eur = new Invoice(
            number: 'FX-0003',
            issueDate: $issued,
            currency: 'EUR',
            seller: $seller,
            buyer: $company,
            lines: [new Line('1', $long['name'], 10890, 'S', '21', $long['description'], $long['note'])],
            taxSubtotals: [new TaxSubtotal('S', '21', 10890, 2287)],
            taxTotalCents: 2287,
            dueDate: new DateTimeImmutable('2026-10-19'),
            // 22.87 EUR x 5.0812 (fictive BNR rate) = 116.21 RON.
            taxTotalRonCents: 11621,
            notes: ['Curs BNR 1 EUR = 5,0812 RON din 02.10.2026; TVA: 116,21 RON'],
            paymentMeans: new PaymentMeans('30', 'RO66BACX0000001234567890', 'EXEMPLU HOSTING S.R.L.'),
        );

        $stornoLines = array_map(static fn (Line $line): Line => new Line(
            $line->id, $line->name, -$line->amountCents, $line->category, $line->rate, $line->description, $line->note, -$line->quantity
        ), $b2b->lines);
        $storno = new Invoice(
            number: 'FX-0004',
            issueDate: new DateTimeImmutable('2026-10-07'),
            currency: 'RON',
            seller: $seller,
            buyer: $company,
            lines: $stornoLines,
            taxSubtotals: [new TaxSubtotal('S', '21', -9890, -2077)],
            taxTotalCents: -2077,
            notes: ['Stornare totală a facturii FX-0001 din 05.10.2026 (rambursare)'],
            precedingNumber: 'FX-0001',
            precedingDate: $issued,
        );

        $partial = new Invoice(
            number: 'FX-0005',
            issueDate: new DateTimeImmutable('2026-10-07'),
            currency: 'RON',
            seller: $seller,
            buyer: $company,
            lines: [new Line('1', 'Stornare parțială factura FX-0001', -2500, 'S', '21', quantity: -1)],
            taxSubtotals: [new TaxSubtotal('S', '21', -2500, -525)],
            taxTotalCents: -525,
            notes: ['Stornare parțială a facturii FX-0001 din 05.10.2026 (rambursare parțială)'],
            precedingNumber: 'FX-0001',
            precedingDate: $issued,
        );

        $nonEu = new Invoice(
            number: 'FX-0006',
            issueDate: $issued,
            currency: 'RON',
            seller: $seller,
            buyer: new Party(name: 'Example Hosting Corp.', street: '100 Example Street', city: 'New York', country: 'US', county: 'NY', postcode: '10001', legalId: '12-3456789'),
            lines: [new Line('1', 'VPS Cloud M - vps.example.com (05/10/2026 - 04/11/2026)', 10000, 'E', '0')],
            taxSubtotals: [new TaxSubtotal('E', '0', 10000, 0, null, 'Neimpozabil în România, art. 278 alin. (2) Cod fiscal')],
            taxTotalCents: 0,
            prepaidCents: 10000,
            paymentMeans: new PaymentMeans('68'),
        );

        $eu = new Invoice(
            number: 'FX-0007',
            issueDate: $issued,
            currency: 'EUR',
            seller: $seller,
            buyer: new Party(name: 'Beispiel GmbH', street: 'Musterstraße 1', city: 'Berlin', country: 'DE', postcode: '10115', vatId: 'DE123456789'),
            lines: [new Line('1', 'Găzduire Business - beispiel.de (05/10/2026 - 04/11/2026)', 5200, 'AE', '0')],
            taxSubtotals: [new TaxSubtotal('AE', '0', 5200, 0, 'VATEX-EU-AE', 'Taxare inversă')],
            taxTotalCents: 0,
            prepaidCents: 5200,
            taxTotalRonCents: 0,
            notes: ['Taxare inversă', 'Curs BNR 1 EUR = 5,0812 RON din 02.10.2026; TVA: 0,00 RON'],
            paymentMeans: new PaymentMeans('68'),
        );

        return [
            'b2b-ron-paid' => $b2b,
            'b2c-bucharest-no-cnp' => $b2c,
            'b2b-eur-unpaid-long-description' => $eur,
            'storno-full' => $storno,
            'storno-partial' => $partial,
            'non-eu-exempt' => $nonEu,
            'eu-reverse-charge-eur' => $eu,
        ];
    }
}
