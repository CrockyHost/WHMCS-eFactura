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
 * Creates a fictive company and a fictive individual, pays one invoice for
 * each, runs the worker on these two documents until ANAF gives a verdict
 * (upload and uploadb2c, stareMesaj, descarcare) and checks the archived
 * answers and the paged message list. Then deletes everything it created
 * and puts the WHMCS series and counters back. It refuses to run unless the
 * addon is set to the ANAF test environment.
 *
 * Expected verdicts: the individual is validated; the company is rejected,
 * because ANAF checks that the buyer CUI exists (also on the test
 * environment) and the fictive one does not. An upload answered with
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
    exit("Sends two fictive invoices to the ANAF test environment and deletes the local test data afterwards. Run with --yes.\n");
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
    $fictive = [
        'company' => ['companyname' => 'CLIENT FICTIV TEST SRL', 'tax_id' => 'RO87654329', 'firstname' => 'Ion', 'lastname' => 'Fictiv'],
        'individual' => ['companyname' => '', 'tax_id' => '', 'firstname' => 'Maria', 'lastname' => 'Fictiva'],
    ];
    foreach ($fictive as $kind => $data) {
        $clientId = (int) localAPI('AddClient', $data + [
            'email' => 'efactura-live-' . uniqid() . '@example.invalid', 'address1' => 'Strada Exemplului nr. 1', 'city' => 'Craiova',
            'state' => 'Dolj', 'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000',
            'password2' => bin2hex(random_bytes(8)), 'currency' => 2, 'noemail' => true, 'skipvalidation' => true,
        ])['clientid'];
        $run['clients'][] = $clientId;
        $state->set(LIVE_STATE, $run);
        $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
            'itemdescription1' => 'Serviciu de test e-Factura (date fictive)', 'itemamount1' => 10, 'itemtaxed1' => true])['invoiceid'];
        $run['invoices'][] = $invoiceId;
        $state->set(LIVE_STATE, $run);
        localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'LIVE-' . uniqid(), 'gateway' => 'banktransfer',
            'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);
        $document = Addon::documents()->forInvoice($invoiceId);
        $check($document !== null && $document->state === Document::STATE_SCHEDULED, "{$kind}: document {$document?->number} scheduled");
        if ($document !== null) {
            $documents[$kind] = (int) $document->id;
        }
    }

    echo "Worker runs (at most {$minutes} minutes)\n";
    // A verdict with its ZIP archived, or data the generator refused.
    $final = static fn (object $row): bool => $row->state === Document::STATE_INVALID
        || (in_array($row->state, [Document::STATE_VALIDATED, Document::STATE_REJECTED], true) && $row->archive_id !== null);
    $deadline = time() + $minutes * 60;
    do {
        $report = Addon::worker()->run(50, array_values($documents));
        $rows = Capsule::table(Document::TABLE)->whereIn('id', $documents)->get()->keyBy('id');
        echo '        ' . date('H:i:s') . ' ' . json_encode(array_filter($report)) . ' ' . implode(', ', $rows->map(static fn ($row): string => $row->number . '=' . $row->state . ($row->anaf_state ? '/' . $row->anaf_state : ''))->all()) . "\n";
        $done = $rows->every($final);
        if (!$done) {
            sleep(30);
        }
    } while (!$done && time() < $deadline);

    echo "Results\n";
    $expected = ['company' => Document::STATE_REJECTED, 'individual' => Document::STATE_VALIDATED];
    foreach ($documents as $kind => $id) {
        $row = Addon::documents()->find($id);
        echo sprintf("        %s: %s %s, endpoint %s, index %s, download %s, attempts %d, errors %s\n", $kind, $row->number, $row->state, $row->upload_endpoint, $row->upload_index, $row->download_id, $row->attempts, $row->errors ?? '-');
        $check($row->state === $expected[$kind], "{$kind}: {$expected[$kind]} by ANAF");
        $check($row->upload_endpoint === ($kind === 'company' ? 'upload' : 'uploadb2c'), "{$kind}: sent to {$row->upload_endpoint}");
        $check(Capsule::table('mod_efactura_archive')->where('document_id', $id)->where('kind', 'xml_sent')->value('sha256') === $row->xml_sha256, "{$kind}: the sent XML is archived");
        $archive = $row->archive_id ? Capsule::table('mod_efactura_archive')->where('id', $row->archive_id)->first() : null;
        $zip = $archive !== null ? ResponseZip::read($archive->content) : null;
        if ($kind === 'company') {
            $check($archive !== null && $archive->kind === 'anaf_errors_zip' && str_contains((string) $row->errors, 'nu exista'), "{$kind}: the ANAF errors are archived and stored (buyer CUI unknown to ANAF)");
            continue;
        }
        $key = $zip?->invoiceKey();
        $check($archive !== null && $archive->kind === 'anaf_zip' && hash('sha256', $archive->content) === $archive->sha256 && $zip->signatureXml !== null, "{$kind}: signed ZIP archived (" . ($archive->size ?? 0) . ' bytes)');
        $check($key !== null && $key['number'] === $row->number && $key['date'] === $row->issue_date && Cui::normalize($key['seller']) === Cui::normalize(Settings::string('company_cui')), "{$kind}: the ZIP holds this invoice");
        $check($key !== null && $key['sha256'] === $row->xml_sha256, "{$kind}: the ZIP holds the exact bytes sent");
    }

    // The lists end a minute before now (ANAF refuses an end in its future),
    // so the newest messages show up a little later.
    $cui = Cui::normalize(Settings::string('company_cui'));
    $index = static fn (string $kind): string => (string) Addon::documents()->find($documents[$kind])->upload_index;
    $until = time() + 180;
    do {
        $listed = [];
        foreach (['T', 'E'] as $filter) {
            $outcome = ResponseParser::messages(Addon::api()->listMessagesPaged($cui, ($started->getTimestamp() - 300) * 1000, (time() - 60) * 1000, 1, $filter));
            $listed[$filter] = array_column($outcome->messages, 'id_solicitare');
        }
        $shown = in_array($index('individual'), $listed['T'], true) && in_array($index('company'), $listed['E'], true);
        if (!$shown) {
            sleep(20);
        }
    } while (!$shown && time() < $until);
    $check($shown, 'the paged message lists show both uploads (T and E)');
} finally {
    $cleanup();
}

echo $failures === [] ? "All checks passed.\n" : count($failures) . " check(s) failed.\n";
exit($failures === [] ? 0 : 1);
