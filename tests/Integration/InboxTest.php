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
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Admin\AdminActions;
use WHMCS\Module\Addon\Efactura\Admin\InboxPage;
use WHMCS\Module\Addon\Efactura\Admin\InvoicePanel;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Inbox\InboxSync;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/*
 * The SPV inbox against the ANAF simulator: invoices from suppliers,
 * messages from buyers, the answers to the addon's own uploads (fictive
 * seller and clients, rolled back).
 */

$setup = static function (): void {
    Clock::freeze(null);
    Settings::save([
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 0, 'ui_language' => 'english',
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_cui' => '12345674', 'company_vat_payer' => true,
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B',
        'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890', 'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    Lang::boot('english');
    NumberingLock::overridePaymentTimeout(1);
    $now = date('Y-m-d H:i:s');
    Capsule::table(Connection::TABLE)->delete();
    Capsule::table(Connection::TABLE)->insert(['id' => 1, 'client_id' => 'test-client', 'client_secret' => Crypto::encrypt('secret'),
        'access_token' => Crypto::encrypt('test-access-token'), 'refresh_token' => Crypto::encrypt('test-refresh-token'),
        'access_expires_at' => date('Y-m-d H:i:s', strtotime('+60 days')), 'refresh_expires_at' => date('Y-m-d H:i:s', strtotime('+300 days')),
        'authorized_at' => $now, 'needs_reauthorization' => 0, 'created_at' => $now, 'updated_at' => $now]);
    Capsule::table(RuntimeState::TABLE)->delete();
    Capsule::table(InboxSync::TABLE)->where('environment', 'test')->delete();
};

$inbox = static function (AnafSimulator $anaf): InboxSync {
    return new InboxSync(new ApiClient($anaf, new Connection(new OAuthClient(new FakeTransport())), 'test'), new RuntimeState());
};

/**
 * A paid invoice uploaded to the simulator; returns its document.
 */
$uploaded = static function (AnafSimulator $anaf): object {
    $clientId = (int) localAPI('AddClient', [
        'firstname' => 'Test', 'lastname' => 'Inbox', 'companyname' => 'CLIENT INBOX SRL', 'tax_id' => 'RO87654329',
        'email' => 'efactura-inbox-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'IN-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
    $document = Addon::documents()->forInvoice($invoiceId);
    $connection = new Connection(new OAuthClient(new FakeTransport()));
    (new Worker(new ApiClient($anaf, $connection, 'test'), $connection, Addon::documentBuilder(), Addon::documents(), new RuntimeState(), null, null, static function (): void {
    }))->run(30, [(int) $document->id]);

    return Addon::documents()->find((int) $document->id);
};

$supplierInvoice = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/fixtures/ubl/b2b-ron-paid.xml');

$clean = static function (callable $body): void {
    try {
        $body();
    } finally {
        Clock::freeze(null);
        NumberingLock::release();
        NumberingLock::overridePaymentTimeout(null);
        unset($_SESSION['adminid']);
    }
};

return [
    'an invoice from a supplier is recorded once, archived and read' => static fn () => $clean(static function () use ($setup, $inbox, $supplierInvoice): void {
        $setup();
        $anaf = new AnafSimulator();
        $id = $anaf->addReceivedInvoice($supplierInvoice(), '12345674');
        $report = $inbox($anaf)->run();
        Assert::same(['listed' => 1, 'new' => 1, 'downloaded' => 1, 'error' => ''], $report);
        $message = Capsule::table(InboxSync::TABLE)->where('anaf_id', $id)->first();
        Assert::same(InboxSync::RECEIVED, $message->kind);
        Assert::same(['EXEMPLU HOSTING S.R.L.', '12345674', 'FX-0001', '2026-10-05', 'RON', '119.67'],
            [$message->issuer_name, $message->issuer_cif, $message->invoice_number, $message->invoice_date, $message->currency, $message->total]);
        $archive = Capsule::table('mod_efactura_archive')->where('id', $message->archive_id)->first();
        Assert::same(['inbox_zip', (int) $message->id], [$archive->kind, (int) $archive->message_id]);

        Assert::same(['listed' => 1, 'new' => 0, 'downloaded' => 0, 'error' => ''], $inbox($anaf)->run(), 'once');
        Assert::same(1, count(array_keys($anaf->calls(), 'descarcare', true)), 'downloaded once');
    }),
    'a message from a buyer is linked to its invoice and e-mailed' => static fn () => $clean(static function () use ($setup, $inbox, $uploaded): void {
        $setup();
        $anaf = new AnafSimulator();
        $document = $uploaded($anaf);
        $anaf->addBuyerMessage((string) $document->upload_index, 'Refuzăm factura: adresa este greșită & incompletă');
        $inbox($anaf)->run();
        $message = Capsule::table(InboxSync::TABLE)->where('kind', InboxSync::BUYER)->first();
        Assert::same((int) $document->id, (int) $message->document_id);
        Assert::same('Refuzăm factura: adresa este greșită & incompletă', $message->message_text);
        Assert::true($message->alerted_at !== null);
        Assert::same(1, Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': ' . Lang::get('alert_buyer_message_subject', (string) $document->number) . '%')->count());
        $panel = (new InvoicePanel())->vars((int) $document->invoice_id);
        Assert::same(['Refuzăm factura: adresa este greșită & incompletă'], array_column($panel['buyerMessages'], 'text'));
        Assert::true(str_contains((new InvoicePanel())->render((int) $document->invoice_id), 'adresa este greșită &amp; incompletă'), 'escaped once');
    }),
    'the answers to the addon uploads are linked to their documents' => static fn () => $clean(static function () use ($setup, $inbox, $uploaded): void {
        $setup();
        $anaf = new AnafSimulator();
        $document = $uploaded($anaf);
        $inbox($anaf)->run();
        $message = Capsule::table(InboxSync::TABLE)->where('request_id', $document->upload_index)->first();
        Assert::same(InboxSync::SENT, $message->kind);
        Assert::same((int) $document->id, (int) $message->document_id);
        Assert::same(null, $message->archive_id, 'not downloaded again: the queue archived it');
    }),
    'a file ANAF no longer keeps is marked lost' => static fn () => $clean(static function () use ($setup, $inbox, $supplierInvoice): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->addReceivedInvoice($supplierInvoice(), '12345674');
        $anaf->downloadModes = ['expired'];
        $inbox($anaf)->run();
        $message = Capsule::table(InboxSync::TABLE)->where('kind', InboxSync::RECEIVED)->first();
        Assert::same(0, (int) $message->archive_id);
        Assert::true(str_contains((string) $message->last_error, '60 de zile'));
        $inbox($anaf)->run();
        Assert::same(1, count(array_keys($anaf->calls(), 'descarcare', true)), 'not tried again');
    }),
    'the inbox pages: unseen, opened, processed' => static fn () => $clean(static function () use ($setup, $inbox, $supplierInvoice): void {
        $setup();
        $_SESSION['adminid'] = 1;
        $anaf = new AnafSimulator();
        $anaf->addReceivedInvoice($supplierInvoice(), '12345674');
        $inbox($anaf)->run();
        Assert::same(1, InboxPage::unseen());
        $page = new InboxPage('addonmodules.php?module=efactura');
        $list = $page->list([]);
        Assert::same(['FX-0001'], array_column($list['messages'], 'number'));
        Assert::true($list['messages'][0]['unseen']);
        Assert::same(['FX-0001'], array_column($page->list(['q' => 'EXEMPLU'])['messages'], 'number'));
        Assert::same([], $page->list(['kind' => InboxSync::BUYER])['messages']);

        $id = (int) $list['messages'][0]['id'];
        $detail = $page->detail($id, 1);
        Assert::same(0, InboxPage::unseen(), 'opening marks it seen');
        Assert::same('FX-0001', $detail['invoice']['number']);
        Assert::same(3, count($detail['invoice']['lines']));
        Assert::same('success', (new AdminActions())->run('message_processed', ['message' => $id], 1)['type']);
        Assert::true($page->detail($id, 1)['isProcessed']);
        Assert::same([], $page->list(['show' => 'unprocessed'])['messages']);
        Assert::same('success', (new AdminActions())->run('message_unprocessed', ['message' => $id], 1)['type']);
        Assert::false($page->detail($id, 1)['isProcessed']);
        Assert::same('success', (new AdminActions())->run('message_unprocessed', ['message' => $id], 1)['type'], 'twice is not an error');
        Assert::same('danger', (new AdminActions())->run('message_processed', ['message' => 999999], 1)['type']);
    }),
    'the inbox pages render what ANAF sent, escaped once' => static fn () => $clean(static function () use ($setup, $inbox, $supplierInvoice, $uploaded): void {
        $setup();
        $_SESSION['adminid'] = 1;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $anaf = new AnafSimulator();
        $anaf->addReceivedInvoice(str_replace('EXEMPLU HOSTING S.R.L.', 'FURNIZOR &amp; &lt;FIU&gt; S.R.L.', $supplierInvoice()), '12345674');
        $document = $uploaded($anaf);
        $anaf->addBuyerMessage((string) $document->upload_index, 'Prețul <b>greșit</b>');
        $inbox($anaf)->run();
        $render = static function (array $get): string {
            $_GET = ['module' => 'efactura'] + $get;
            ob_start();
            (new \WHMCS\Module\Addon\Efactura\Admin\AdminController(['modulelink' => 'addonmodules.php?module=efactura']))->handle();

            return (string) ob_get_clean();
        };

        $list = $render(['view' => 'inbox']);
        file_put_contents('C:/Users/jpnsefu/.claude/jobs/81eef17e/tmp/list.html', $list);
        Assert::true(str_contains($list, 'FURNIZOR &amp; &lt;FIU&gt; S.R.L.'), 'supplier in the list');
        Assert::true(str_contains($list, 'Prețul &lt;b&gt;greșit&lt;/b&gt;'), 'buyer message in the list');
        Assert::false(str_contains($list, '&amp;amp;') || str_contains($list, '<b>greșit'), 'escaped once');
        Assert::false(str_contains($list, '<td>Invoice sent</td>'), 'the answers to the uploads only on demand');
        Assert::true(str_contains($render(['view' => 'inbox', 'kind' => 'all']), '<td>Invoice sent</td>'), 'and there with all');
        Assert::same(3, count((new InboxPage('addonmodules.php?module=efactura'))->list(['kind' => 'all'])['messages']));
        Assert::same(1, count((new InboxPage('addonmodules.php?module=efactura'))->list(['kind' => InboxSync::SENT])['messages']));
        Assert::true(str_contains($render(['view' => 'dashboard']), Lang::get('inbox_unseen')), 'dashboard');

        $received = (int) Capsule::table(InboxSync::TABLE)->where('kind', InboxSync::RECEIVED)->value('id');
        $page = $render(['view' => 'message', 'id' => (string) $received]);
        Assert::true(str_contains($page, 'FURNIZOR &amp; &lt;FIU&gt; S.R.L.'));
        Assert::true(str_contains($page, 'value="message_processed"'), 'the processed button');
        $buyer = (int) Capsule::table(InboxSync::TABLE)->where('kind', InboxSync::BUYER)->value('id');
        $page = $render(['view' => 'message', 'id' => (string) $buyer]);
        Assert::true(str_contains($page, 'Prețul &lt;b&gt;greșit&lt;/b&gt;'));
        Assert::true(str_contains($page, htmlspecialchars((string) $document->number)), 'the document it is about');
        Assert::true(str_contains($render(['view' => 'message', 'id' => '999999']), Lang::get('inbox_message_missing')));
    }),
    'the cron reads the inbox at most every half hour' =>static fn () => $clean(static function () use ($setup, $inbox): void {
        $setup();
        $anaf = new AnafSimulator();
        $sync = $inbox($anaf);
        Assert::true($sync->due());
        $sync->run();
        Assert::false($sync->due());
        Clock::freeze(Clock::now()->modify('+31 minutes'));
        Assert::true($sync->due());
    }),
];
