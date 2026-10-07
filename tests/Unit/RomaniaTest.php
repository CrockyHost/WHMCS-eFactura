<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Romania\Text;

return [
    'CUI with a correct check digit is valid' => static function (): void {
        foreach (['50515950', 'RO50515950', 'ro 50515950', '14399840', '18547290'] as $cui) {
            Assert::true(Cui::isValid($cui), "{$cui} should be valid");
        }
    },
    'CUI with a wrong check digit or format is invalid' => static function (): void {
        foreach (['50515951', '', 'RO', '1', '0123456', '12345678901', 'ABC123', '5051595O'] as $cui) {
            Assert::false(Cui::isValid($cui), "{$cui} should be invalid");
        }
    },
    'CUI normalization strips RO and separators' => static function (): void {
        Assert::same('50515950', Cui::normalize(' ro 5051-5950 '));
    },
    'there are 42 county codes, including Bucharest' => static function (): void {
        Assert::same(42, count(Counties::all()));
        Assert::true(Counties::isValid('RO-B'));
        Assert::true(Counties::isValid('RO-IF'));
        Assert::false(Counties::isValid('RO-XX'));
        Assert::false(Counties::isValid('B'));
    },
    'counties sort by name ignoring diacritics' => static function (): void {
        $names = array_values(Counties::sortedByName());
        Assert::same('Alba', $names[0]);
        Assert::same('Vrancea', $names[41]);
        Assert::true(array_search('Dâmbovița', $names, true) < array_search('Dolj', $names, true));
    },
    'sectors are only SECTOR1 to SECTOR6' => static function (): void {
        Assert::true(Counties::isSector('SECTOR6'));
        Assert::false(Counties::isSector('SECTOR7'));
        Assert::false(Counties::isSector('Sector 1'));
    },
    'text folding removes both forms of Romanian diacritics' => static function (): void {
        Assert::same('stiinta tara', Text::fold('Știința Țara'));
        Assert::same('stiinta tara', Text::fold('Ştiinţa Ţara'));
    },
];
