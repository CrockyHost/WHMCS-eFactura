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

use WHMCS\Module\Addon\Efactura\ClientData\AddressRules;
use WHMCS\Module\Addon\Efactura\ClientData\CountyField;
use WHMCS\Module\Addon\Efactura\ClientData\FormContext;
use WHMCS\Module\Addon\Efactura\ClientData\IdentityRules;
use WHMCS\Module\Addon\Efactura\ClientData\Issue;
use WHMCS\Module\Addon\Efactura\ClientData\RegCom;

/**
 * @param list<Issue> $issues
 * @return list<string> "field:key"
 */
$describe = static fn (array $issues): array => array_map(
    static fn (Issue $issue): string => $issue->field . ':' . $issue->key,
    $issues
);

return [
    'the county dropdown lists the 42 official names in alphabetical order' => static function (): void {
        $names = CountyField::names();
        Assert::same(42, count($names));
        Assert::same('Alba', $names[0]);
        Assert::true(in_array('București', $names, true));
        Assert::true(in_array('Bistrița-Năsăud', $names, true));
        Assert::same('București', CountyField::bucharest());
        Assert::same('Cluj', CountyField::codes()['CJ']);
        Assert::same('București', CountyField::codes()['B']);
    },
    'old county values written differently map to the dropdown value' => static function (): void {
        $cases = [
            'DOLJ' => 'Dolj',
            'Maramures' => 'Maramureș',
            'Iași' => 'Iași',
            'Iaşi' => 'Iași',
            'Bucuresti' => 'București',
            'Municipiul București' => 'București',
            'Sector 3' => 'București',
            'Jud. Cluj' => 'Cluj',
            'Județul Bistrița-Năsăud' => 'Bistrița-Năsăud',
            'RO-CT' => 'Constanța',
            'B' => 'București',
        ];
        foreach ($cases as $text => $expected) {
            Assert::same($expected, CountyField::canonical($text), "for {$text}");
        }
    },
    'values that are not a county are not guessed' => static function (): void {
        foreach (['Romania', 'România', '-', 'kkkk', '', 'Shymkent city', 'XX'] as $text) {
            Assert::same(null, CountyField::canonical($text), "for {$text}");
        }
    },
    'the sector is saved as the city "Sector N"' => static function (): void {
        Assert::same(['Sector 1', 'Sector 2', 'Sector 3', 'Sector 4', 'Sector 5', 'Sector 6'], CountyField::sectors());
        Assert::same('Sector 3', CountyField::sector('sectorul 3'));
        Assert::same('Sector 6', CountyField::sector('Bucuresti', 'Str. Exemplu 1, Sect. 6'));
        Assert::same(null, CountyField::sector('Bucuresti', 'Str. Sectorului 7'));
        Assert::true(CountyField::isBucharest('Bucuresti'));
        Assert::false(CountyField::isBucharest('Ilfov'));
    },
    'address rules: a Romanian address needs a known county' => static function () use ($describe): void {
        Assert::same(['state:cd_error_county'], $describe(AddressRules::check(['country' => 'RO', 'state' => 'Romania', 'city' => 'Cluj-Napoca'])));
        Assert::same(['state:cd_error_county'], $describe(AddressRules::check(['country' => 'RO', 'state' => '', 'city' => 'Cluj-Napoca'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'Cluj', 'city' => 'Cluj-Napoca'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'DOLJ', 'city' => 'Băilești'])));
    },
    'address rules: Bucharest needs a sector in the city or the street' => static function () use ($describe): void {
        Assert::same(['city:cd_error_sector'], $describe(AddressRules::check(['country' => 'RO', 'state' => 'București', 'city' => 'Bucuresti'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'București', 'city' => 'Sector 3'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'București', 'city' => 'Bucuresti', 'address1' => 'Str. X 1, sector 2'])));
    },
    'address rules: other countries and locked fields are not checked' => static function () use ($describe): void {
        Assert::same([], $describe(AddressRules::check(['country' => 'DE', 'state' => 'Bayern', 'city' => 'München'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'Romania', 'city' => 'X'], ['state'])));
        Assert::same([], $describe(AddressRules::check(['country' => 'RO', 'state' => 'București', 'city' => 'Bucuresti'], ['city'])));
    },
    'trade register numbers in the new format need the right check digit and county' => static function (): void {
        // Real numbers returned by ANAF, and the examples of python-stdnum.
        foreach (['J2024020698007', 'J2002000372404', 'J2016012984401', 'J2012000750528', ' j2024020698007 '] as $number) {
            Assert::same(RegCom::FORMAT_NEW, RegCom::format($number, 2026), $number);
        }
        // Wrong check digit; county other than 00 after 2024; too short; future year.
        foreach (['J2012000750529', 'J2025012984401', 'J201601298440', 'J2030000000004', 'X2024020698007'] as $number) {
            Assert::same(null, RegCom::format($number, 2026), $number);
        }
    },
    'trade register numbers in the old format are still valid' => static function (): void {
        foreach (['J40/9159/1999', 'J52/750/2012', 'F5/123/2005', 'C 12/45/2010', 'J40/1234/20.03.2020'] as $number) {
            Assert::same(RegCom::FORMAT_OLD, RegCom::format($number, 2026), $number);
        }
        // County 41, number 0, year after the old format, extra part, no year.
        foreach (['J41/1/2000', 'J40/0/2000', 'J40/1/2025', 'J22/1515/2007/123', 'J40/1234', '', '-'] as $number) {
            Assert::same(null, RegCom::format($number, 2026), $number);
        }
        Assert::same('J05/123/2005', RegCom::normalize('j 5/123/2005'));
    },
    'the client type is read from the data like the UBL buyer mapping' => static function (): void {
        Assert::same(IdentityRules::COMPANY, IdentityRules::infer('X SRL', ''));
        Assert::same(IdentityRules::COMPANY, IdentityRules::infer('', '50515950'));
        Assert::same(IdentityRules::COMPANY, IdentityRules::infer('', '', 'RO50515950'));
        Assert::same(IdentityRules::PERSON, IdentityRules::infer('', ''));
        // An individual's CNP typed into the CUI field (older data).
        Assert::same(IdentityRules::PERSON, IdentityRules::infer('', '1960131410041'));
        Assert::true(IdentityRules::isVatNumberOf('ro 5051 5950', '50515950'));
        Assert::false(IdentityRules::isVatNumberOf('50515950', '50515950'));
        Assert::false(IdentityRules::isVatNumberOf('RO14399840', '50515950'));
    },
    'a Romanian company needs its CUI, always' => static function () use ($describe): void {
        $issues = IdentityRules::check(['country' => 'RO', 'type' => 'company', 'companyname' => 'X SRL', 'cui' => '']);
        Assert::same(['cui:cd_error_cui_required'], $describe($issues));
        Assert::same(Issue::REQUIRED, $issues[0]->level);
        Assert::true($issues[0]->blocks(FormContext::WARN));
        // Without a chosen type, a company name alone makes a company.
        Assert::same(['cui:cd_error_cui_required'], $describe(IdentityRules::check(['country' => 'RO', 'companyname' => 'X SRL'])));
    },
    'company data is checked' => static function () use ($describe): void {
        $base = ['country' => 'RO', 'type' => 'company', 'companyname' => 'X SRL', 'cui' => '50515950'];
        Assert::same([], $describe(IdentityRules::check($base + ['regcom' => 'J2024020698007', 'tax_id' => 'RO50515950'])));
        $issues = IdentityRules::check(['cui' => '50515951', 'companyname' => '', 'regcom' => 'J40/1/2030', 'tax_id' => 'RO14399840'] + $base);
        Assert::same(['cui:cd_error_cui_invalid', 'companyname:cd_error_company_name', 'regcom:cd_error_regcom', 'tax_id:cd_error_vat_mismatch'], $describe($issues));
        Assert::false($issues[0]->blocks(FormContext::WARN));
        Assert::true($issues[0]->blocks(FormContext::STRICT));
    },
    'individual data is checked' => static function () use ($describe): void {
        $base = ['country' => 'RO', 'type' => 'person'];
        Assert::same([], $describe(IdentityRules::check($base)));
        Assert::same([], $describe(IdentityRules::check($base + ['cnp' => '1960131410041'])));
        Assert::same([], $describe(IdentityRules::check($base + ['cnp' => '0000000000000'])));
        Assert::same([], $describe(IdentityRules::check($base + ['cui' => '1960131410041'])));
        Assert::same(['cnp:cd_error_cnp'], $describe(IdentityRules::check($base + ['cnp' => '1960131410045'])));
        Assert::same(['companyname:cd_error_person_company', 'tax_id:cd_error_person_vat'], $describe(IdentityRules::check($base + ['companyname' => 'X SRL', 'tax_id' => 'RO50515950'])));
    },
    'identity rules skip other countries and fields the client cannot change' => static function () use ($describe): void {
        Assert::same([], $describe(IdentityRules::check(['country' => 'DE', 'type' => 'company', 'companyname' => 'X GmbH'])));
        $locked = ['type', 'companyname', 'tax_id', 'cui', 'regcom', 'cnp'];
        Assert::same([], $describe(IdentityRules::check(['country' => 'RO', 'companyname' => 'X SRL', 'cui' => '', 'regcom' => 'bad'], $locked)));
    },
    'form context is read from the script and the route' => static function (): void {
        Assert::same(FormContext::REGISTER, FormContext::detect(['SCRIPT_NAME' => '/register.php'], [], false));
        Assert::same(FormContext::CHECKOUT, FormContext::detect(['SCRIPT_NAME' => '/cart.php'], ['a' => 'checkout'], false));
        Assert::same(FormContext::PROFILE, FormContext::detect(['SCRIPT_NAME' => '/clientarea.php'], ['action' => 'details'], false));
        Assert::same(FormContext::OTHER, FormContext::detect(['SCRIPT_NAME' => '/clientarea.php'], ['action' => 'invoices'], false));
        Assert::same(FormContext::CONTACT, FormContext::detect(['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/account/contacts/new'], [], false));
        Assert::same(FormContext::CONTACT, FormContext::detect(['SCRIPT_NAME' => '/index.php'], ['rp' => '/account/contacts/5'], false));
        Assert::same(FormContext::ADMIN, FormContext::detect(['SCRIPT_NAME' => '/register.php'], [], true));
        Assert::same(FormContext::OTHER, FormContext::detect(['SCRIPT_NAME' => '/includes/api.php'], [], false));
    },
];
