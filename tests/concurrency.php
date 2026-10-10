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
 * Concurrent payments, in separate PHP processes, against the local WHMCS
 * development installation:
 *
 *   php tests/concurrency.php --yes
 *
 * 1. Two payments overlap within the lock timeout: the second one waits and
 *    both get unique, consecutive fiscal numbers.
 * 2. The first payment holds the lock longer than the timeout: the second
 *    one goes through after waiting, and its document is held for a manual
 *    check with an alert.
 *
 * The data must be committed to be visible between processes, so the script
 * creates a fictive client and deletes everything it created at the end,
 * putting the WHMCS counters back. Never run it on a production database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/init.php';
require_once dirname(__DIR__) . '/modules/addons/efactura/bootstrap.php';
require __DIR__ . '/SuiteLock.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Settings\Settings;

$php = PHP_BINARY;

// Child process: pay one invoice at a given moment, optionally keeping the
// numbering lock busy for a while (sleeping in InvoicePaidPreEmail before the
// addon hook, i.e. while the lock taken at AddInvoicePayment is held).
if (($argv[1] ?? '') === 'child') {
    [, , $invoiceId, $startAt, $sleep] = $argv;
    if ((int) $sleep > 0) {
        add_hook('InvoicePaidPreEmail', -200, static function () use ($sleep): void {
            sleep((int) $sleep);
        });
    }
    time_sleep_until((float) $startAt);
    $started = microtime(true);
    $total = (float) Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('total');
    $result = localAPI('AddInvoicePayment', ['invoiceid' => (int) $invoiceId, 'transid' => 'C-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => $total]);
    echo json_encode(['invoice' => (int) $invoiceId, 'result' => $result['result'] ?? '', 'seconds' => round(microtime(true) - $started, 2)]), "\n";
    exit;
}

if (!in_array('--yes', $argv, true)) {
    exit("Creates and deletes test data in the local WHMCS database. Run with --yes.\n");
}
efactura_suite_lock();
if (Settings::environment() !== Settings::ENV_TEST) {
    exit("Refusing: the addon is not in the ANAF test environment.\n");
}

$setting = static fn (string $name): string => (string) Capsule::table('tblconfiguration')->where('setting', $name)->value('value');
$original = [
    'SequentialInvoiceNumberValue' => $setting('SequentialInvoiceNumberValue'),
    'TaxNextCustomInvoiceNumber' => $setting('TaxNextCustomInvoiceNumber'),
    'enabled' => Settings::bool('enabled'),
];
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    if (!$ok) {
        $failures[] = $what;
    }
};

Settings::save(['enabled' => true]);
$clientId = (int) localAPI('AddClient', [
    'firstname' => 'Concurrency', 'lastname' => 'eFactura', 'email' => 'efactura-concurrency-' . uniqid() . '@example.invalid',
    'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj', 'postcode' => '200000', 'country' => 'RO',
    'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2, 'noemail' => true, 'skipvalidation' => true,
])['clientid'];
$invoices = [];
for ($i = 0; $i < 4; $i++) {
    $invoices[] = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false,
        'paymentmethod' => 'banktransfer', 'itemdescription1' => 'Concurrency test ' . $i, 'itemamount1' => 10 + $i, 'itemtaxed1' => true])['invoiceid'];
}
$start = $original['SequentialInvoiceNumberValue'];
$format = (string) $setting('SequentialInvoiceNumberFormat');
$expected = static fn (int $offset): string => str_replace('{NUMBER}', str_pad((string) ((int) $start + $offset), strlen($start), '0', STR_PAD_LEFT), $format);

/**
 * Starts two children paying at almost the same time and waits for both.
 *
 * @return list<array<string, mixed>>
 */
$race = static function (int $first, int $second, int $sleep) use ($php): array {
    $at = microtime(true) + 2;
    $pipes = [];
    $processes = [];
    foreach ([[$first, $at, $sleep], [$second, $at + 0.3, 0]] as $i => [$invoice, $when, $hold]) {
        $processes[$i] = proc_open([$php, __FILE__, 'child', (string) $invoice, sprintf('%.3f', $when), (string) $hold], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $results = [];
    foreach ($processes as $i => $process) {
        $output = stream_get_contents($pipes[$i][1]);
        $errors = stream_get_contents($pipes[$i][2]);
        proc_close($process);
        $line = json_decode((string) strrchr("\n" . trim((string) $output), "\n"), true) ?? ['raw' => $output, 'errors' => $errors];
        $results[] = $line;
    }

    return $results;
};

try {
    echo "1. Two payments 0.3 s apart, the first one holds the lock for 2 s\n";
    foreach ($race($invoices[0], $invoices[1], 2) as $result) {
        echo '        ' . json_encode($result) . "\n";
    }
    echo "2. The first payment holds the lock for 8 s, longer than the 5 s timeout\n";
    foreach ($race($invoices[2], $invoices[3], 8) as $result) {
        echo '        ' . json_encode($result) . "\n";
    }

    echo "Results\n";
    $documents = Addon::documents();
    foreach ($invoices as $i => $id) {
        $row = Capsule::table('tblinvoices')->where('id', $id)->first(['invoicenum', 'status']);
        $doc = $documents->forInvoice($id);
        echo sprintf("        invoice %d: %s %s, document %s %s\n", $id, $row->status, $row->invoicenum, $doc->state ?? '-', $doc->review_reason ?? '');
        $check($row->status === 'Paid', "invoice {$id} is paid");
    }
    $numbers = array_map(static fn (int $id): string => (string) Capsule::table('tblinvoices')->where('id', $id)->value('invoicenum'), $invoices);
    $check(count(array_unique($numbers)) === 4, 'the four fiscal numbers are unique');
    $sorted = $numbers;
    sort($sorted);
    $check($sorted === [$expected(0), $expected(1), $expected(2), $expected(3)], 'the numbers are consecutive, from ' . $expected(0));
    $check($setting('SequentialInvoiceNumberValue') === str_pad((string) ((int) $start + 4), strlen($start), '0', STR_PAD_LEFT), 'the counter moved by exactly 4');
    $check($documents->forInvoice($invoices[1])->state === Document::STATE_SCHEDULED, 'race 1: the second payment waited for the lock and was not flagged');
    $check($documents->forInvoice($invoices[3])->review_reason === Document::REVIEW_LOCK_TIMEOUT, 'race 2: the second payment went through without the lock and is held for a check');
    $alert = json_decode((string) @file_get_contents('http://127.0.0.1:8025/api/v1/search?query=' . rawurlencode('subject:"' . $documents->forInvoice($invoices[3])->number . '"')), true);
    $check(($alert['messages_count'] ?? 0) > 0, 'race 2: the alert e-mail reached Mailpit');
} finally {
    echo "Cleanup\n";
    Capsule::table(Document::TABLE)->whereIn('invoice_id', $invoices)->delete();
    Capsule::table('mod_efactura_audit')->whereIn('invoice_id', $invoices)->delete();
    localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true, 'deletetransactions' => true]);
    Capsule::table('tblinvoiceitems')->whereIn('invoiceid', $invoices)->delete();
    Capsule::table('tblinvoices')->whereIn('id', $invoices)->delete();
    Capsule::table('tblaccounts')->whereIn('invoiceid', $invoices)->delete();
    foreach (['SequentialInvoiceNumberValue', 'TaxNextCustomInvoiceNumber'] as $name) {
        Capsule::table('tblconfiguration')->where('setting', $name)->update(['value' => $original[$name]]);
    }
    Settings::save(['enabled' => $original['enabled']]);
    $left = Capsule::table('tblinvoices')->whereIn('id', $invoices)->count() + Capsule::table('tblclients')->where('id', $clientId)->count();
    echo "        counters restored to {$original['SequentialInvoiceNumberValue']} / {$original['TaxNextCustomInvoiceNumber']}, rows left: {$left}\n";
}

echo $failures === [] ? "All checks passed.\n" : count($failures) . " check(s) failed.\n";
exit($failures === [] ? 0 : 1);
