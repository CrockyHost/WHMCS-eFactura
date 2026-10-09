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

use WHMCS\Config\Setting;
use WHMCS\Module\Addon\Efactura\Admin\HealthChecks;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * Applies WHMCS numbering settings (inside the test transaction).
 *
 * @param array<string, string> $values
 */
$configure = static function (array $values): void {
    $values += [
        'SequentialInvoiceNumbering' => '1',
        'EnableProformaInvoicing' => '1',
        'TaxSetInvoiceDateOnPayment' => '1',
        'SequentialInvoiceNumberFormat' => 'CRK-{NUMBER}',
        'SequentialInvoiceNumberValue' => '999999',
        'TaxCustomInvoiceNumbering' => '1',
        'TaxCustomInvoiceNumberFormat' => 'CRP-{NUMBER}',
        'TaxNextCustomInvoiceNumber' => '0001',
    ];
    foreach ($values as $name => $value) {
        Setting::setValue($name, $value);
    }
};

$seriesCheck = static function (): array {
    foreach ((new HealthChecks(new InvoicingConfig()))->all() as $check) {
        if ($check['label'] === Lang::get('check_series')) {
            return $check;
        }
    }
    throw new RuntimeException('series check missing');
};

Lang::boot('english');

return [
    'proforma series CRP next to fiscal series CRK is accepted' => static function () use ($configure, $seriesCheck): void {
        $configure([]);
        Assert::true((new HealthChecks(new InvoicingConfig()))->whmcsReady());
        Assert::same('ok', $seriesCheck()['status']);
        Assert::true(str_contains($seriesCheck()['detail'], 'CRP-{NUMBER}'));
    },
    'proformas without numbers are accepted' => static function () use ($configure, $seriesCheck): void {
        $configure(['TaxCustomInvoiceNumbering' => '0']);
        Assert::true((new HealthChecks(new InvoicingConfig()))->whmcsReady());
        Assert::same('ok', $seriesCheck()['status']);
    },
    'overlapping proforma and fiscal series block processing' => static function () use ($configure, $seriesCheck): void {
        $configure(['TaxCustomInvoiceNumberFormat' => 'CRK-{NUMBER}']);
        Assert::false((new HealthChecks(new InvoicingConfig()))->whmcsReady());
        Assert::same('danger', $seriesCheck()['status']);
    },
    'a fiscal counter not above the last fiscal number blocks processing' => static function () use ($configure): void {
        $configure(['SequentialInvoiceNumberValue' => '0001']);
        $highest = (new InvoicingConfig())->highestIssuedNumber();
        Assert::same($highest !== null, !(new HealthChecks(new InvoicingConfig()))->whmcsReady());
    },
    'proforma mode switched off blocks processing' => static function () use ($configure): void {
        $configure(['EnableProformaInvoicing' => '0']);
        Assert::false((new HealthChecks(new InvoicingConfig()))->whmcsReady());
    },
];
