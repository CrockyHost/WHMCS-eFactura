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
