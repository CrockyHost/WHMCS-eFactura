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
 * The SPV inbox against the ANAF TEST environment:
 *
 *   php tests/anaf-live-inbox.php --yes
 *   php tests/anaf-live-inbox.php --cleanup   forgets what it read
 *
 * Reads the e-Factura messages of the CUI in the settings, as the cron does,
 * downloads the invoices received and the buyer messages, and prints what it
 * recorded. It only reads from ANAF. It refuses to run unless the addon is
 * set to the ANAF test environment.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/init.php';
require_once dirname(__DIR__) . '/modules/addons/efactura/bootstrap.php';
// WHMCS sets a time limit when it boots; ANAF may be slow.
set_time_limit(0);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Inbox\InboxSync;
use WHMCS\Module\Addon\Efactura\Inbox\InvoiceReader;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

$args = array_slice($argv, 1);
if (Settings::environment() !== 'test') {
    fwrite(STDERR, "The addon is not set to the ANAF test environment.\n");
    exit(1);
}

if (in_array('--cleanup', $args, true)) {
    $ids = Capsule::table(InboxSync::TABLE)->where('environment', 'test')->pluck('id')->all();
    Capsule::table('mod_efactura_archive')->where('kind', 'inbox_zip')->whereIn('message_id', $ids)->delete();
    Capsule::table(InboxSync::TABLE)->whereIn('id', $ids)->delete();
    Capsule::table(RuntimeState::TABLE)->whereIn('name', ['inbox_synced_until', 'inbox_last_run'])->delete();
    echo 'Forgot ' . count($ids) . " messages of the test environment.\n";
    exit(0);
}
if (!in_array('--yes', $args, true)) {
    fwrite(STDERR, "Reads the SPV inbox of the test environment. Run with --yes.\n");
    exit(1);
}

$started = microtime(true);
$report = Addon::inbox()->run();
printf("listed %d, new %d, downloaded %d%s in %.1f s\n", $report['listed'], $report['new'], $report['downloaded'],
    $report['error'] !== '' ? ', error: ' . $report['error'] : '', microtime(true) - $started);

$messages = Capsule::table(InboxSync::TABLE)->where('environment', 'test')->orderBy('anaf_created_at')->get();
$kinds = [];
foreach ($messages as $message) {
    $kinds[$message->kind] = ($kinds[$message->kind] ?? 0) + 1;
}
echo 'kinds: ' . json_encode($kinds) . "\n";

$failed = 0;
foreach ($messages as $message) {
    if (!in_array($message->kind, [InboxSync::RECEIVED, InboxSync::BUYER], true)) {
        continue;
    }
    $line = sprintf('%s %s %s', $message->anaf_created_at, $message->kind, $message->anaf_id);
    if ((int) $message->archive_id === 0) {
        echo $line . ' not downloaded: ' . ($message->last_error ?? 'pending') . "\n";
        continue;
    }
    if ($message->kind === InboxSync::BUYER) {
        echo $line . ' document ' . ($message->document_id ?? '-') . ': ' . $message->message_text . "\n";
        continue;
    }
    // The fields recorded must be those of the invoice in the signed ZIP.
    $zip = (string) Capsule::table('mod_efactura_archive')->where('id', $message->archive_id)->value('content');
    $invoice = InvoiceReader::read((string) ResponseZip::read($zip)->invoiceXml);
    $same = $invoice !== null && $invoice['number'] === $message->invoice_number && $invoice['date'] === $message->invoice_date
        && abs((float) $invoice['total'] - (float) $message->total) < 0.005;
    $failed += $same ? 0 : 1;
    printf("%s %s %s %s %s %s %d lines%s\n", $line, $message->issuer_cif, $message->invoice_number, $message->invoice_date,
        $message->total, $message->currency, $invoice !== null ? count($invoice['lines']) : 0, $same ? '' : ' MISMATCH');
}
exit($failed === 0 && $report['error'] === '' ? 0 : 1);
