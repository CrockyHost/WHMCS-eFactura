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
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Http\Response;

/**
 * The ID of the RON currency of the WHMCS installation, for the fictive
 * clients of the tests and the development scripts.
 */
function efactura_ron_currency(): int
{
    $id = (int) Capsule::table('tblcurrencies')->where('code', 'RON')->value('id');
    if ($id <= 0) {
        throw new RuntimeException('The WHMCS installation has no RON currency (Configuration > Payments > Currencies).');
    }

    return $id;
}

/**
 * The text of the WHMCS PDF of an invoice (pdftotext), or a skip when the
 * tool is not installed.
 */
function efactura_pdf_text(int $invoiceId): string
{
    $tool = getenv('EFACTURA_PDFTOTEXT') ?: 'C:/Program Files/Git/mingw64/bin/pdftotext.exe';
    if (!is_file($tool)) {
        throw new SkipTest('pdftotext not found (set EFACTURA_PDFTOTEXT)');
    }
    require_once ROOTDIR . '/includes/invoicefunctions.php';
    $pdf = tempnam(sys_get_temp_dir(), 'efp');
    file_put_contents($pdf, pdfInvoice($invoiceId));
    $text = (string) shell_exec(escapeshellarg($tool) . ' -enc UTF-8 ' . escapeshellarg($pdf) . ' -');
    @unlink($pdf);

    return $text;
}

/**
 * The ID of the EUR currency of the WHMCS installation, or a skip.
 */
function efactura_eur_currency(): int
{
    $id = (int) Capsule::table('tblcurrencies')->where('code', 'EUR')->value('id');
    if ($id <= 0) {
        throw new SkipTest('the WHMCS installation has no EUR currency');
    }

    return $id;
}

/**
 * Imports BNR rates the way the daily download does, through a fake BNR, so
 * that the days the file covers are recorded.
 *
 * @param array<string, array<string, string>> $cubes date => currency => value
 */
function efactura_bnr_import(array $cubes, string $path = 'nbrfxrates10days.xml'): void
{
    (new BnrRates((new FakeTransport())->queue(new Response(200, [], efactura_bnr_xml($cubes)))))->import($path);
}

/**
 * A BNR rates file.
 *
 * @param array<string, array<string, string>> $cubes date => currency => value
 */
function efactura_bnr_xml(array $cubes): string
{
    krsort($cubes);
    $xml = '<?xml version="1.0" encoding="utf-8"?><DataSet xmlns="http://www.bnr.ro/xsd"><Body><OrigCurrency>RON</OrigCurrency>';
    foreach ($cubes as $date => $rates) {
        $xml .= '<Cube date="' . $date . '">';
        foreach ($rates as $currency => $value) {
            $xml .= '<Rate currency="' . $currency . '">' . $value . '</Rate>';
        }
        $xml .= '</Cube>';
    }

    return $xml . '</Body></DataSet>';
}

/**
 * Fictive EUR rates for the working days among the 10 days before today
 * ("5.08" and the number of days ago), as the daily download would leave them.
 *
 * @return array<string, array<string, string>>
 */
function efactura_recent_eur_rates(): array
{
    $cubes = [];
    for ($days = 10; $days >= 1; $days--) {
        $date = Clock::today()->modify("-{$days} days");
        if (WorkingDays::isWorkingDay($date)) {
            $cubes[$date->format('Y-m-d')] = ['EUR' => sprintf('5.08%02d', $days)];
        }
    }

    return $cubes;
}
