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

use WHMCS\Module\Addon\Efactura\ClientData\Anaf\CompanyParser;
use WHMCS\Module\Addon\Efactura\ClientData\Anaf\CompanyRecord;

/**
 * Answers of PlatitorTvaRest v9 as observed live on 2026-10-09, shortened
 * (public company data from the ANAF registry).
 */
$bucharestCompany = [
    'date_generale' => [
        'cui' => 14399840, 'denumire' => 'DANTE INTERNATIONAL SA', 'codPostal' => '',
        'stare_inregistrare' => 'INREGISTRAT din data 29.08.2006', 'statusRO_e_Factura' => false, 'nrRegCom' => 'J2002000372404',
    ],
    'inregistrare_scop_Tva' => ['scpTVA' => true, 'perioade_TVA' => [['data_inceput_ScpTVA' => '2006-09-01']]],
    'inregistrare_RTVAI' => ['statusTvaIncasare' => false],
    'stare_inactiv' => ['dataRadiere' => '', 'statusInactivi' => false],
    'adresa_sediu_social' => [
        'sdenumire_Localitate' => 'Sector 6 Mun. Bucureşti', 'sdenumire_Strada' => 'Şos. Virtuţii', 'snumar_Strada' => '148',
        'sdenumire_Judet' => 'MUNICIPIUL BUCUREŞTI', 'scod_JudetAuto' => 'B', 'sdetalii_Adresa' => 'spatiul E47', 'scod_Postal' => '60787',
    ],
];
$villageCompany = [
    'date_generale' => [
        'cui' => 50515950, 'denumire' => 'CROCKY S.R.L.', 'codPostal' => '', 'stare_inregistrare' => 'INREGISTRAT din data 06.09.2024',
        'statusRO_e_Factura' => true, 'nrRegCom' => 'J2024020698007',
    ],
    'inregistrare_scop_Tva' => ['scpTVA' => true, 'perioade_TVA' => []],
    'inregistrare_RTVAI' => ['statusTvaIncasare' => false],
    'stare_inactiv' => ['dataRadiere' => '', 'statusInactivi' => false],
    'adresa_sediu_social' => [
        'sdenumire_Localitate' => 'Loc. Balasan Mun. Băileşti', 'sdenumire_Strada' => 'Str DRĂGAICA', 'snumar_Strada' => '5',
        'sdenumire_Judet' => 'DOLJ', 'scod_JudetAuto' => 'DJ', 'sdetalii_Adresa' => '', 'scod_Postal' => '',
    ],
];
$deregistered = [
    'date_generale' => [
        'cui' => 12345674, 'denumire' => 'BARS COMPANY S.R.L.', 'stare_inregistrare' => 'RADIERE din data 29.06.2006',
        'statusRO_e_Factura' => false, 'nrRegCom' => 'J40/9159/1999',
    ],
    'inregistrare_scop_Tva' => ['scpTVA' => false],
    'stare_inactiv' => ['dataRadiere' => '', 'statusInactivi' => false],
    'adresa_sediu_social' => ['sdenumire_Localitate' => '', 'sdenumire_Strada' => ''],
    'adresa_domiciliu_fiscal' => [
        'ddenumire_Localitate' => 'Sector 3 Mun. Bucureşti', 'ddenumire_Strada' => 'Aleea Suraia', 'dnumar_Strada' => '3',
        'ddenumire_Judet' => 'MUNICIPIUL BUCUREŞTI', 'dcod_JudetAuto' => 'B', 'ddetalii_Adresa' => '', 'dcod_Postal' => '',
    ],
];

return [
    'a Bucharest company: sector as city, comma-below letters, postal code with its zero' => static function () use ($bucharestCompany): void {
        $company = CompanyParser::company(['found' => [$bucharestCompany], 'notFound' => []], 'RO14399840');
        Assert::true($company instanceof CompanyRecord);
        Assert::same('14399840', $company->cui);
        Assert::same('DANTE INTERNATIONAL SA', $company->name);
        Assert::same('J2002000372404', $company->regCom);
        Assert::same('Șos. Virtuții nr. 148', $company->address1);
        Assert::same('spatiul E47', $company->address2);
        Assert::same('București', $company->county);
        Assert::same('Sector 6', $company->city);
        Assert::same('060787', $company->postcode);
        Assert::true($company->vatPayer);
        Assert::false($company->eInvoiceRegistry);
        Assert::false($company->deregistered);
    },
    'a village of a municipality and the county from the car plate code' => static function () use ($villageCompany): void {
        $company = CompanyParser::company(['found' => [$villageCompany], 'notFound' => []], '50515950');
        Assert::same('Balasan, Băilești', $company->city);
        Assert::same('Dolj', $company->county);
        Assert::same('Str DRĂGAICA nr. 5', $company->address1);
        Assert::same('', $company->postcode);
        Assert::true($company->eInvoiceRegistry);
    },
    'a deregistered company is flagged from its registration state' => static function () use ($deregistered): void {
        $company = CompanyParser::company(['found' => [$deregistered]], '12345674');
        Assert::true($company->deregistered);
        Assert::false($company->vatPayer);
        // No office address: the fiscal domicile is used.
        Assert::same('Aleea Suraia nr. 3', $company->address1);
        Assert::same('Sector 3', $company->city);
        Assert::same('J40/9159/1999', $company->regCom);
    },
    'the documented envelope and a missing CUI are tolerated' => static function () use ($villageCompany): void {
        $wrapped = ['cod' => 200, 'message' => 'SUCCESS', 'found' => [$villageCompany], 'notFound' => []];
        Assert::same('CROCKY S.R.L.', CompanyParser::company($wrapped, '50515950')?->name);
        Assert::same(null, CompanyParser::company($wrapped, '14399840'));
        Assert::true(CompanyParser::notFound(['found' => [], 'notFound' => [98765012]], '98765012'));
        Assert::false(CompanyParser::notFound(['found' => []], '98765012'));
        Assert::same(null, CompanyParser::company(['unexpected' => true], '50515950'));
    },
    'localities and postal codes are cleaned up' => static function (): void {
        Assert::same('Craiova', CompanyParser::locality('Mun. Craiova'));
        Assert::same('Balasan, Băilești', CompanyParser::locality('Loc. Balasan Mun. Băileşti'));
        Assert::same('Rediu, Rediu', CompanyParser::locality('Sat Rediu Com. Rediu'));
        Assert::same('Cluj-Napoca', CompanyParser::locality('Cluj-Napoca'));
        Assert::same('400000', CompanyParser::postcode('400000'));
        Assert::same('030167', CompanyParser::postcode('30167'));
        Assert::same('', CompanyParser::postcode('12'));
    },
    'records round-trip through the cache format' => static function () use ($bucharestCompany): void {
        $company = CompanyParser::record($bucharestCompany);
        Assert::same($company->toArray(), CompanyRecord::fromArray($company->toArray())->toArray());
    },
];
