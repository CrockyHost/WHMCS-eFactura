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
 * Fictive data for trying the admin pages in the development installation:
 *
 *   php tests/admin-demo.php --create    a client "TEST PANOU SRL" with a paid
 *                                        invoice refunded in part (so it has a
 *                                        storno and a WHMCS credit note) and a
 *                                        proforma; prints the IDs
 *   php tests/admin-demo.php --cleanup   deletes all of it and puts the WHMCS
 *                                        counters and the settings back
 *
 * Nothing is sent to ANAF: processing is turned back off after the data is
 * created. Never run it on a production database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/init.php';
require_once dirname(__DIR__) . '/modules/addons/efactura/bootstrap.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';
set_time_limit(0);

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

const DEMO_STATE = 'admin_demo';
$state = new RuntimeState();
$setting = static fn (string $name): string => (string) Capsule::table('tblconfiguration')->where('setting', $name)->value('value');

if (in_array('--cleanup', $argv, true)) {
    $run = $state->get(DEMO_STATE);
    if (!is_array($run)) {
        exit("Nothing to clean up.\n");
    }
    $documents = Capsule::table(Document::TABLE)->whereIn('invoice_id', $run['invoices'])->pluck('id')->all();
    Capsule::table('mod_efactura_archive')->whereIn('document_id', $documents)->delete();
    Capsule::table(Document::TABLE)->whereIn('id', $documents)->delete();
    Capsule::table('mod_efactura_audit')->whereIn('invoice_id', $run['invoices'])->delete();
    $notes = Capsule::table('tblaccounts')->whereIn('invoiceid', $run['invoices'])->where('billingnoteid', '>', 0)->pluck('billingnoteid')->all();
    Capsule::table('tblbillingnoteitems')->whereIn('billingnote_id', $notes)->delete();
    Capsule::table('tblbillingnotes')->whereIn('id', $notes)->delete();
    if ($run['client'] > 0) {
        localAPI('DeleteClient', ['clientid' => $run['client'], 'deleteusers' => true, 'deletetransactions' => true]);
    }
    Capsule::table('tblinvoiceitems')->whereIn('invoiceid', $run['invoices'])->delete();
    Capsule::table('tblinvoices')->whereIn('id', $run['invoices'])->delete();
    Capsule::table('tblaccounts')->whereIn('invoiceid', $run['invoices'])->delete();
    foreach (['SequentialInvoiceNumberValue', 'TaxNextCustomInvoiceNumber'] as $name) {
        Setting::setValue($name, $run['original'][$name]);
    }
    Settings::save(['enabled' => (bool) $run['original']['enabled'], 'send_delay_days' => (int) $run['original']['send_delay_days']]);
    $state->forget(DEMO_STATE);
    echo 'Deleted. Counters back to ' . $setting('SequentialInvoiceNumberValue') . ' / ' . $setting('TaxNextCustomInvoiceNumber') . ".\n";
    exit;
}
if (!in_array('--create', $argv, true)) {
    exit("Run with --create or --cleanup.\n");
}
if ($state->get(DEMO_STATE) !== null) {
    exit("The demo data exists already: run with --cleanup first.\n");
}
if (Settings::environment() !== Settings::ENV_TEST) {
    exit("Refusing: the addon is not in the ANAF test environment.\n");
}

$run = ['original' => [
    'SequentialInvoiceNumberValue' => $setting('SequentialInvoiceNumberValue'),
    'TaxNextCustomInvoiceNumber' => $setting('TaxNextCustomInvoiceNumber'),
    'enabled' => Settings::bool('enabled'),
    'send_delay_days' => Settings::int('send_delay_days'),
], 'client' => 0, 'invoices' => []];
$state->set(DEMO_STATE, $run);
try {
    Settings::save(['enabled' => true, 'send_delay_days' => 1]);
    $run['client'] = (int) localAPI('AddClient', ['firstname' => 'Test', 'lastname' => 'Panou', 'companyname' => 'TEST PANOU SRL', 'tax_id' => 'RO87654329',
        'email' => 'efactura-demo-' . uniqid() . '@example.invalid', 'address1' => 'Strada Exemplului nr. 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2,
        'noemail' => true, 'skipvalidation' => true])['clientid'];
    $state->set(DEMO_STATE, $run);
    $paid = (int) localAPI('CreateInvoice', ['userid' => $run['client'], 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'TEST Găzduire (date fictive)', 'itemamount1' => 60, 'itemtaxed1' => true,
        'itemdescription2' => 'TEST Domeniu exemplu.ro (date fictive)', 'itemamount2' => 40, 'itemtaxed2' => true])['invoiceid'];
    $run['invoices'][] = $paid;
    $state->set(DEMO_STATE, $run);
    localAPI('AddInvoicePayment', ['invoiceid' => $paid, 'transid' => 'DEMO-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
    $payment = (int) Capsule::table('tblaccounts')->where('invoiceid', $paid)->where('amountin', '>', 0)->value('id');
    refundInvoicePayment($payment, 30.00, false, false, false, 'DEMO-R-' . uniqid());
    Addon::stornos()->process(5);
    $proforma = (int) localAPI('CreateInvoice', ['userid' => $run['client'], 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'TEST Proformă (date fictive)', 'itemamount1' => 10, 'itemtaxed1' => true])['invoiceid'];
    $run['invoices'][] = $proforma;
    $state->set(DEMO_STATE, $run);
} finally {
    Settings::save(['enabled' => (bool) $run['original']['enabled'], 'send_delay_days' => (int) $run['original']['send_delay_days']]);
}

$note = (int) Capsule::table('tblaccounts')->where('invoiceid', $paid)->where('billingnoteid', '>', 0)->value('billingnoteid');
echo "Paid invoice:  invoices.php?action=edit&id={$paid}\n";
echo "Proforma:      invoices.php?action=edit&id={$proforma}\n";
echo "Credit note:   billing/billingnote/credit/{$note}\n";
echo "Documents:     addonmodules.php?module=efactura&view=documents\n";
