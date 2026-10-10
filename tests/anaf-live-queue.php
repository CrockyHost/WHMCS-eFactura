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
 * The queue against the ANAF TEST environment, with fictive clients only:
 *
 *   php tests/anaf-live-queue.php --yes [--minutes=90]
 *   php tests/anaf-live-queue.php --cleanup   after a run that was killed
 *
 * Pays one test invoice for a company and one for a fictive individual and
 * runs the worker on them until ANAF validates them (upload and uploadb2c,
 * stareMesaj, descarcare). Then refunds part of the company invoice and
 * cancels the other one, and runs the worker until ANAF validates the two
 * stornos. Checks the archived answers and the paged message list, deletes
 * everything it created and puts the WHMCS series and counters back. It
 * refuses to run unless the addon is set to the ANAF test environment.
 *
 * ANAF checks that a buyer CUI exists, also on the test environment, so the
 * test company is the seller itself (the CUI and name in the settings, the
 * name followed by " (TEST)"); every line says it is a test, for 1 RON. An upload answered with
 * "A aparut o eroare tehnica" is sent again after an hour, hence the time.
 *
 * The invoices get a fiscal series unique to the run (LIVEyymmddhhmm-), so
 * that the test environment never receives numbers of the real series.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/init.php';
require_once dirname(__DIR__) . '/modules/addons/efactura/bootstrap.php';
require __DIR__ . '/SuiteLock.php';
require __DIR__ . '/TestData.php';
// WHMCS sets a time limit when it boots; ANAF may take minutes.
set_time_limit(0);

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\Outcome;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseParser;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;

require_once ROOTDIR . '/includes/invoicefunctions.php';

/*
 * What the run changed, kept in mod_efactura_state while it runs, so that
 * --cleanup can undo it after the process was killed.
 */
const LIVE_STATE = 'live_test';
$state = new RuntimeState();

/**
 * Deletes the test data and restores the WHMCS series and counters.
 *
 * @param array{original: array<string, mixed>, clients: list<int>, invoices: list<int>} $run
 */
function live_cleanup(array $run, RuntimeState $state): void
{
    echo "Cleanup\n";
    $original = $run['original'];
    $documentIds = Capsule::table(Document::TABLE)->whereIn('invoice_id', $run['invoices'])->pluck('id')->all();
    Capsule::table('mod_efactura_archive')->whereIn('document_id', $documentIds)->delete();
    Capsule::table(Document::TABLE)->whereIn('id', $documentIds)->delete();
    Capsule::table('mod_efactura_audit')->whereIn('invoice_id', $run['invoices'])->delete();
    foreach ($run['clients'] as $clientId) {
        localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true, 'deletetransactions' => true]);
    }
    $notes = Capsule::table('tblaccounts')->whereIn('invoiceid', $run['invoices'])->where('billingnoteid', '>', 0)->pluck('billingnoteid')->all();
    Capsule::table('tblbillingnoteitems')->whereIn('billingnote_id', $notes)->delete();
    Capsule::table('tblbillingnotes')->whereIn('id', $notes)->delete();
    Capsule::table('tblinvoiceitems')->whereIn('invoiceid', $run['invoices'])->delete();
    Capsule::table('tblinvoices')->whereIn('id', $run['invoices'])->delete();
    Capsule::table('tblaccounts')->whereIn('invoiceid', $run['invoices'])->delete();
    foreach (['SequentialInvoiceNumberValue', 'SequentialInvoiceNumberFormat', 'TaxNextCustomInvoiceNumber'] as $name) {
        Setting::setValue($name, $original[$name]);
    }
    Settings::save(['enabled' => (bool) $original['enabled'], 'send_delay_days' => (int) $original['send_delay_days']]);
    $state->forget(LIVE_STATE);
    $left = Capsule::table('tblinvoices')->whereIn('id', $run['invoices'])->count() + Capsule::table('tblclients')->whereIn('id', $run['clients'])->count()
        + Capsule::table(Document::TABLE)->whereIn('id', $documentIds)->count();
    echo "        series and counters restored to {$original['SequentialInvoiceNumberFormat']} {$original['SequentialInvoiceNumberValue']} / {$original['TaxNextCustomInvoiceNumber']}, rows left: {$left}\n";
}

if (in_array('--cleanup', $argv, true)) {
    $run = $state->get(LIVE_STATE);
    if (!is_array($run)) {
        exit("Nothing to clean up.\n");
    }
    live_cleanup($run, $state);
    exit(0);
}
if (!in_array('--yes', $argv, true)) {
    exit("Sends two test invoices and their stornos to the ANAF test environment and deletes the local test data afterwards. Run with --yes.\n");
}
if ($state->get(LIVE_STATE) !== null) {
    exit("A previous run did not finish: run with --cleanup first.\n");
}
efactura_suite_lock();
if (Settings::environment() !== Settings::ENV_TEST || !str_contains(ApiClient::baseUrl(Settings::environment()), '/test/')) {
    exit("Refusing: the addon is not in the ANAF test environment.\n");
}
if (!Addon::connection()->status()['connected']) {
    exit("Refusing: the addon is not connected to ANAF.\n");
}
Lang::boot(Settings::string('ui_language'));
$minutes = max(5, (int) (getopt('', ['minutes:'])['minutes'] ?? 90));

$setting = static fn (string $name): string => (string) Capsule::table('tblconfiguration')->where('setting', $name)->value('value');
$run = [
    'original' => [
        'SequentialInvoiceNumberValue' => $setting('SequentialInvoiceNumberValue'),
        'SequentialInvoiceNumberFormat' => $setting('SequentialInvoiceNumberFormat'),
        'TaxNextCustomInvoiceNumber' => $setting('TaxNextCustomInvoiceNumber'),
        'enabled' => Settings::bool('enabled'),
        'send_delay_days' => Settings::int('send_delay_days'),
    ],
    'clients' => [],
    'invoices' => [],
];
$state->set(LIVE_STATE, $run);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    if (!$ok) {
        $failures[] = $what;
    }
};

$documents = [];
$started = Clock::now();
$cleaned = false;
// Runs once: at the end, or at shutdown after a fatal error. A killed
// process leaves the state behind for --cleanup.
$cleanup = static function () use (&$cleaned, &$run, $state): void {
    if (!$cleaned) {
        $cleaned = true;
        live_cleanup($run, $state);
    }
};
register_shutdown_function($cleanup);
try {
    Settings::save(['enabled' => true, 'send_delay_days' => 0]);
    Setting::setValue('SequentialInvoiceNumberFormat', 'LIVE' . date('ymdHi') . '-{NUMBER}');
    $clients = [
        'company' => [
            'companyname' => Settings::string('company_legal_name') . ' (TEST)',
            'tax_id' => (Settings::bool('company_vat_payer') ? 'RO' : '') . Cui::normalize(Settings::string('company_cui')),
            'firstname' => 'Test', 'lastname' => 'eFactura',
        ],
        'individual' => ['companyname' => '', 'tax_id' => '', 'firstname' => 'Maria', 'lastname' => 'Fictiva'],
    ];
    $lines = [
        'company' => ['TEST mediul de test ANAF, linia 1 - fara valoare, nu se plateste', 'TEST mediul de test ANAF, linia 2 - fara valoare, nu se plateste'],
        'individual' => ['TEST mediul de test ANAF - fara valoare, nu se plateste'],
    ];
    $payments = [];
    foreach ($clients as $kind => $data) {
        $clientId = (int) localAPI('AddClient', $data + [
            'email' => 'efactura-live-' . uniqid() . '@example.invalid', 'address1' => 'Strada Exemplului nr. 1', 'city' => 'Craiova',
            'state' => 'Dolj', 'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000',
            'password2' => bin2hex(random_bytes(8)), 'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
        ])['clientid'];
        $run['clients'][] = $clientId;
        $state->set(LIVE_STATE, $run);
        $items = [];
        foreach ($lines[$kind] as $i => $text) {
            $items += ['itemdescription' . ($i + 1) => $text, 'itemamount' . ($i + 1) => 1, 'itemtaxed' . ($i + 1) => true];
        }
        $invoiceId = (int) localAPI('CreateInvoice', $items + ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer'])['invoiceid'];
        $run['invoices'][] = $invoiceId;
        $state->set(LIVE_STATE, $run);
        localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'LIVE-' . uniqid(), 'gateway' => 'banktransfer',
            'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);
        $payments[$kind] = (int) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->where('amountin', '>', 0)->value('id');
        $document = Addon::documents()->forInvoice($invoiceId);
        $check($document !== null && $document->state === Document::STATE_SCHEDULED, "{$kind}: document {$document?->number} scheduled");
        if ($document !== null) {
            $documents[$kind] = (int) $document->id;
        }
    }

    // A verdict with its ZIP archived, or data the generator refused.
    $final = static fn (object $row): bool => $row->state === Document::STATE_INVALID
        || (in_array($row->state, [Document::STATE_VALIDATED, Document::STATE_REJECTED], true) && $row->archive_id !== null);
    $deadline = time() + $minutes * 60;
    $work = static function (string $phase) use (&$documents, $final, $deadline): void {
        echo "Worker runs: {$phase}\n";
        do {
            $report = Addon::worker()->run(50, array_values($documents));
            $rows = Capsule::table(Document::TABLE)->whereIn('id', $documents)->get()->keyBy('id');
            echo '        ' . date('H:i:s') . ' ' . json_encode(array_filter($report)) . ' ' . implode(', ', $rows->map(static fn ($row): string => $row->number . '=' . $row->state . ($row->anaf_state ? '/' . $row->anaf_state : ''))->all()) . "\n";
            $done = $rows->every($final);
            if (!$done) {
                sleep(30);
            }
        } while (!$done && time() < $deadline);
    };
    $work('the invoices');

    // Stornos of the validated invoices: a partial refund and a cancellation.
    $originals = ['company' => Addon::documents()->find($documents['company']), 'individual' => Addon::documents()->find($documents['individual'])];
    if ($originals['company']->state === Document::STATE_VALIDATED) {
        refundInvoicePayment($payments['company'], 1.21, false, false, false, 'LIVE-R-' . uniqid());
    }
    if ($originals['individual']->state === Document::STATE_VALIDATED) {
        localAPI('UpdateInvoice', ['invoiceid' => (int) $originals['individual']->invoice_id, 'status' => 'Cancelled']);
    }
    // A request issues them when it ends; here, at once.
    Addon::stornos()->process(5);
    foreach ($originals as $kind => $original) {
        $storno = Addon::documents()->stornosOf((int) $original->id)[0] ?? null;
        $check($storno !== null, "{$kind}: storno {$storno?->number} issued ({$storno?->reason})");
        if ($storno !== null) {
            $documents[$kind . ' storno'] = (int) $storno->id;
        }
    }
    $work('the stornos');

    echo "Results\n";
    $expected = [
        'company' => [null, 'upload'],
        'individual' => [null, 'uploadb2c'],
        'company storno' => [DocumentBuilder::REASON_REFUND_PARTIAL, 'upload'],
        'individual storno' => [DocumentBuilder::REASON_CANCEL, 'uploadb2c'],
    ];
    foreach ($documents as $kind => $id) {
        $row = Addon::documents()->find($id);
        echo sprintf("        %s: %s %s, endpoint %s, index %s, download %s, attempts %d, total %s, errors %s\n", $kind, $row->number, $row->state, $row->upload_endpoint, $row->upload_index, $row->download_id, $row->attempts, $row->total, $row->errors ?? '-');
        $check($row->state === Document::STATE_VALIDATED, "{$kind}: validated by ANAF");
        $check($row->reason === $expected[$kind][0] && $row->upload_endpoint === $expected[$kind][1], "{$kind}: " . ($row->reason ?? 'invoice') . ", sent to {$row->upload_endpoint}");
        $check(Capsule::table('mod_efactura_archive')->where('document_id', $id)->where('kind', 'xml_sent')->value('sha256') === $row->xml_sha256, "{$kind}: the sent XML is archived");
        $archive = $row->archive_id ? Capsule::table('mod_efactura_archive')->where('id', $row->archive_id)->first() : null;
        $zip = $archive !== null && $archive->kind === 'anaf_zip' ? ResponseZip::read($archive->content) : null;
        $key = $zip?->invoiceKey();
        $check($zip !== null && hash('sha256', $archive->content) === $archive->sha256 && $zip->signatureXml !== null, "{$kind}: signed ZIP archived (" . ($archive->size ?? 0) . ' bytes)');
        $check($key !== null && $key['number'] === $row->number && $key['sha256'] === $row->xml_sha256, "{$kind}: the ZIP holds the exact bytes sent");
        if ($row->kind === Document::KIND_STORNO) {
            $original = Addon::documents()->find((int) $row->original_document_id);
            $check(str_contains((string) $zip?->invoiceXml, '<cbc:ID>' . $original->number . '</cbc:ID>'), "{$kind}: points to {$original->number}");
        }
    }

    // The lists end a minute before now (ANAF refuses an end in its future),
    // so the newest messages show up a little later.
    $cui = Cui::normalize(Settings::string('company_cui'));
    $indexes = Capsule::table(Document::TABLE)->whereIn('id', $documents)->pluck('upload_index')->map(static fn ($index): string => (string) $index)->all();
    $until = time() + 180;
    do {
        $outcome = ResponseParser::messages(Addon::api()->listMessagesPaged($cui, ($started->getTimestamp() - 300) * 1000, (time() - 60) * 1000, 1, 'T'));
        $shown = $outcome->kind === Outcome::MESSAGES && array_diff($indexes, array_column($outcome->messages, 'id_solicitare')) === [];
        if (!$shown) {
            sleep(20);
        }
    } while (!$shown && time() < $until);
    $check($shown, 'the paged message list (T) shows the four uploads');
} finally {
    $cleanup();
}

echo $failures === [] ? "All checks passed.\n" : count($failures) . " check(s) failed.\n";
exit($failures === [] ? 0 : 1);
