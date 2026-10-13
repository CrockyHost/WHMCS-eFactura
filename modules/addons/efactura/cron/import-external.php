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

/*
 * Registers the fiscal invoices and stornos issued and reported before the
 * addon (see Fiscal\ExternalDocuments), from a CSV file with a header row:
 *
 *   kind,invoice_id,number,issue_date,storno_invoice_id,net,tax
 *   invoice,277,CRK-0094,2026-10-10,,,
 *   storno,141,CRK-0096,2026-10-13,290,,
 *
 *   php modules/addons/efactura/cron/import-external.php FILE.csv [--apply]
 *
 * Without --apply it only checks the file and prints what it would import.
 * With --apply it imports all the rows, or none when one has an error.
 * Never reachable over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $argument): bool => $argument !== '--apply'));
$apply = in_array('--apply', $argv, true);
$file = $arguments[0] ?? '';
if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "Usage: php import-external.php FILE.csv [--apply]\n");
    exit(2);
}
$file = realpath($file);

require dirname(__DIR__, 4) . '/init.php';
require_once dirname(__DIR__) . '/bootstrap.php';

use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\ExternalDocuments;

Addon::migratePending();

$rows = [];
$handle = fopen($file, 'r');
$header = array_map(static fn ($name): string => strtolower(trim((string) $name, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($handle, null, ',', '"', '') ?: []);
$line = 1;
while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
    $line++;
    if ($values === [null] || implode('', $values) === '') {
        continue;
    }
    $rows[] = ['line' => (string) $line] + array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
}
fclose($handle);

$importer = new ExternalDocuments(Addon::bnrRates());
$result = $apply ? $importer->import($rows) : $importer->check($rows);
if (!$apply) {
    foreach ($result['rows'] as $row) {
        printf("line %d: %s %s of WHMCS invoice #%d, %s%s\n", $row['line'], $row['kind'], $row['number'], (int) $row['invoice']->id, $row['date']->format('Y-m-d'),
            $row['kind'] === 'storno' ? sprintf(' (net %.2f, VAT %.2f)', $row['net'] / 100, $row['tax'] / 100) : '');
    }
}
foreach ($result['warnings'] as $warning) {
    echo "warning: {$warning}\n";
}
foreach ($result['errors'] as $error) {
    echo "error: {$error}\n";
}
$count = $apply ? $result['imported'] : count($result['rows']);
printf("%d rows%s, %d errors, %d warnings.%s\n", $count, $apply ? ' imported' : ' to import', count($result['errors']), count($result['warnings']),
    $apply || $result['errors'] !== [] ? '' : ' Run again with --apply to import them.');
exit($result['errors'] === [] ? 0 : 1);
