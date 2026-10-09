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
use WHMCS\Module\Addon\Efactura\ClientData\Issue;

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
