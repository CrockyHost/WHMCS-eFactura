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

use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Support\Iban;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

return [
    'valid IBANs pass the mod 97 check' => static function (): void {
        foreach (['RO49AAAA1B31007593840000', 'RO49 AAAA 1B31 0075 9384 0000', 'DE89370400440532013000', 'GB82WEST12345698765432'] as $iban) {
            Assert::true(Iban::isValid($iban), "{$iban} should be valid");
        }
    },
    'invalid IBANs are rejected' => static function (): void {
        foreach (['RO49AAAA1B31007593840001', 'RO49AAAA1B3100759384000', '', 'RO', 'XX00'] as $iban) {
            Assert::false(Iban::isValid($iban), "{$iban} should be invalid");
        }
    },
    'number pattern captures the counter of the series' => static function (): void {
        $pattern = InvoicingConfig::numberPattern('CRK-{NUMBER}');
        Assert::true(preg_match($pattern, 'CRK-0093', $match) === 1);
        Assert::same('0093', $match['n']);
        Assert::same(0, preg_match($pattern, 'CRK-0093-X'));
        Assert::same(0, preg_match($pattern, 'XCRK-1'));
    },
    'number pattern handles date tags and regex characters' => static function (): void {
        $pattern = InvoicingConfig::numberPattern('F.{YEAR}/{MONTH}-{NUMBER}');
        Assert::true(preg_match($pattern, 'F.2026/10-7', $match) === 1);
        Assert::same('7', $match['n']);
        Assert::same(0, preg_match($pattern, 'Fx2026/10-7'));
        Assert::same(null, InvoicingConfig::numberPattern('CRK-'));
    },
    'proforma and fiscal series with different prefixes do not overlap' => static function (): void {
        Assert::false(InvoicingConfig::formatsOverlap('CRK-{NUMBER}', 'CRP-{NUMBER}'));
        Assert::false(InvoicingConfig::formatsOverlap('CRK-{NUMBER}', 'CRK-P{NUMBER}'));
        Assert::false(InvoicingConfig::formatsOverlap('F{NUMBER}', '{NUMBER}'));
        Assert::false(InvoicingConfig::formatsOverlap('CRK-{NUMBER}', 'PRO-{YEAR}-{NUMBER}'));
    },
    'series with the same prefix or reachable numbers overlap' => static function (): void {
        Assert::true(InvoicingConfig::formatsOverlap('CRK-{NUMBER}', 'CRK-{NUMBER}'));
        Assert::true(InvoicingConfig::formatsOverlap('CRK-{NUMBER}', 'CRK-{YEAR}{NUMBER}'));
        Assert::true(InvoicingConfig::formatsOverlap('{NUMBER}', '{YEAR}{NUMBER}'));
        // Different literal prefixes, but "{NUMBER}" also produces "2026...".
        Assert::true(InvoicingConfig::formatsOverlap('{NUMBER}', '2026{NUMBER}'));
    },
    'literal prefix stops at the first tag only' => static function (): void {
        Assert::same('CRK-', InvoicingConfig::literalPrefix('CRK-{NUMBER}'));
        Assert::same('A{B}-', InvoicingConfig::literalPrefix('A{B}-{YEAR}/{NUMBER}'));
        Assert::same('', InvoicingConfig::literalPrefix('{NUMBER}'));
    },
    'format fills the counter as stored and the date tags' => static function (): void {
        Assert::same('CRK-0094', InvoicingConfig::format('CRK-{NUMBER}', '0094'));
        Assert::same('F-2026/03/09-7', InvoicingConfig::format('F-{YEAR}/{MONTH}/{DAY}-{NUMBER}', '7', new DateTimeImmutable('2026-03-09')));
    },
    'attribution notice is shown unaltered' => static function (): void {
        $text = html_entity_decode(strip_tags(str_replace('<br>', "\n", Addon::attributionHtml())));
        Assert::same(implode("\n", Addon::ATTRIBUTION), $text);
        Assert::true(str_contains(Addon::attributionHtml(), '<a href="' . Addon::SOURCE_URL . '"'));
        Assert::same('WHMCS-eFactura by CrockyHost. Free software under the GNU GPL v3.', Addon::ATTRIBUTION[0]);
        Assert::same('Source code: https://github.com/CrockyHost/WHMCS-eFactura', Addon::ATTRIBUTION[1]);
    },
];
