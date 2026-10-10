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
use WHMCS\Module\Addon\Efactura\Admin\AdminController;
use WHMCS\Module\Addon\Efactura\Admin\CreditNotePanel;
use WHMCS\Module\Addon\Efactura\Admin\DocumentsPage;
use WHMCS\Module\Addon\Efactura\Admin\Flash;
use WHMCS\Module\Addon\Efactura\Admin\InvoicePanel;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/*
 * The admin side: the actions, the invoice and credit note panels and the
 * documents pages, on real WHMCS invoices (fictive seller and clients,
 * rolled back).
 */

require_once ROOTDIR . '/includes/invoicefunctions.php';

$setup = static function (array $settings = []): void {
    Clock::freeze(null);
    Settings::save($settings + [
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 1, 'ui_language' => 'english',
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_cui' => '12345674', 'company_vat_payer' => true,
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B',
        'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890', 'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    Lang::boot('english');
    NumberingLock::overridePaymentTimeout(1);
    $_SESSION['adminid'] = 1;
    unset($_SESSION['efactura_flash']);
};

$client = static fn (array $data = []): int => (int) localAPI('AddClient', $data + [
    'firstname' => 'Test', 'lastname' => 'Admin', 'companyname' => 'CLIENT ADMIN SRL', 'tax_id' => 'RO87654329',
    'email' => 'efactura-admin-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
    'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2,
    'noemail' => true, 'skipvalidation' => true,
])['clientid'];
$unpaid = static fn (int $clientId): int => (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false,
    'paymentmethod' => 'banktransfer', 'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];
/**
 * @return array{0: int, 1: object} invoice ID and its fiscal document
 */
$paid = static function (array $clientData = []) use ($client, $unpaid): array {
    $invoiceId = $unpaid($client($clientData));
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'A-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);

    return [$invoiceId, Addon::documents()->forInvoice($invoiceId) ?? throw new RuntimeException('no document')];
};
$clean = static function (callable $body): void {
    try {
        $body();
    } finally {
        Clock::freeze(null);
        NumberingLock::release();
        NumberingLock::overridePaymentTimeout(null);
        // The other tests run without an admin session.
        unset($_SESSION['efactura_flash'], $_SESSION['adminid']);
    }
};

return [
    'queue actions: hold, release, send now' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [, $document] = $paid();
        $actions = new AdminActions();
        $result = $actions->run('hold', ['document' => $document->id], 1);
        Assert::same('success', $result['type']);
        Assert::same(Document::STATE_HELD, Addon::documents()->find((int) $document->id)->state);
        Assert::same((int) 1, (int) Addon::documents()->find((int) $document->id)->held_by);
        Assert::same('success', $actions->run('release', ['document' => $document->id], 1)['type']);
        Assert::same(Document::STATE_SCHEDULED, Addon::documents()->find((int) $document->id)->state);
        Assert::same('success', $actions->run('send_now', ['document' => $document->id], 1)['type']);
        Assert::true(Clock::parse((string) Addon::documents()->find((int) $document->id)->send_after) <= Clock::now());
        Assert::same('warning', $actions->run('release', ['document' => $document->id], 1)['type'], 'not held');
        Assert::same('danger', $actions->run('hold', ['document' => 0], 1)['type']);
        Assert::same('danger', $actions->run('delete_everything', [], 1)['type']);
    }),
    'issuing a proforma early from the panel' => static fn () => $clean(static function () use ($setup, $client, $unpaid): void {
        $setup();
        $invoiceId = $unpaid($client());
        $vars = (new InvoicePanel())->vars($invoiceId);
        Assert::true($vars['proforma'] && $vars['canIssueEarly']);
        $result = (new AdminActions())->run('issue_early', ['invoice' => $invoiceId], 1);
        Assert::same('success', $result['type']);
        $document = Addon::documents()->forInvoice($invoiceId);
        Assert::same(Document::SOURCE_EARLY, $document->source);
        Assert::true(str_contains($result['text'], (string) $document->number));
        Assert::same('warning', (new AdminActions())->run('issue_early', ['invoice' => $invoiceId], 1)['type'], 'only once');
    }),
    'a manual storno from the panel form' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [$invoiceId, $document] = $paid();
        $actions = new AdminActions();
        Assert::same('warning', $actions->run('storno', ['invoice' => $invoiceId, 'net' => '-5', 'tax' => '0'], 1)['type']);
        Assert::same('warning', $actions->run('storno', ['invoice' => $invoiceId, 'net' => '200', 'tax' => '0'], 1)['type'], 'more than the invoice');
        $result = $actions->run('storno', ['invoice' => $invoiceId, 'net' => '10,00', 'tax' => '2.10'], 1);
        Assert::same('success', $result['type']);
        $storno = Addon::documents()->stornosOf((int) $document->id)[0];
        Assert::same(['-12.10', 'manual'], [$storno->total, $storno->reason]);
        $vars = (new InvoicePanel())->vars($invoiceId);
        Assert::same(['90.00', '18.90'], [$vars['storno']['net'], $vars['storno']['tax']], 'the form offers what is left');
    }),
    'checking the data before sending' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [, $good] = $paid();
        Assert::same('success', (new AdminActions())->run('check', ['document' => $good->id], 1)['type']);
        [, $bad] = $paid(['state' => 'kkkk']);
        $result = (new AdminActions())->run('check', ['document' => $bad->id], 1);
        Assert::same('danger', $result['type']);
        Assert::true($result['details'] !== [], 'the problems are listed');
        Assert::same(Document::STATE_SCHEDULED, Addon::documents()->find((int) $bad->id)->state, 'nothing is changed');
    }),
    'the invoice panel shows the document, its stornos, files and actions' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [$invoiceId, $document] = $paid();
        Addon::documents()->archive($document, 'xml_sent', $document->number . '.xml', 'application/xml', '<Invoice/>', '5000000001');
        Addon::stornos()->issueManual($invoiceId, 1000, 210, 1, 1);
        Flash::set('success', 'Done & dusted');
        $html = (new InvoicePanel())->render($invoiceId);
        Assert::true(str_contains($html, (string) $document->number));
        Assert::true(str_contains($html, Lang::get('btn_send_now')));
        Assert::true(str_contains($html, Lang::get('panel_stornos')));
        Assert::true(str_contains($html, Lang::get('panel_waits_for_original')));
        Assert::true(str_contains($html, '&amp;view=download&amp;archive='), 'download link');
        Assert::true(str_contains($html, 'Done &amp; dusted'), 'the flash, escaped');
        Assert::true(str_contains($html, 'name="token"'));
        Assert::same(null, Flash::pull(), 'shown once');
    }),
    'the invoice panel of a Mass Pay invoice says it is not fiscal' => static fn () => $clean(static function () use ($setup, $client, $unpaid): void {
        $setup();
        $clientId = $client();
        $child = $unpaid($clientId);
        $container = $unpaid($clientId);
        Capsule::table('tblinvoiceitems')->where('invoiceid', $container)->update(['type' => 'Invoice', 'relid' => $child]);
        $vars = (new InvoicePanel())->vars($container);
        Assert::same(Lang::get('notfiscal_mass_pay'), $vars['notFiscal']);
        Assert::same(null, $vars['document']);
    }),
    'the credit note panel shows the storno issued from it' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [$invoiceId, $document] = $paid();
        $payment = (int) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->where('amountin', '>', 0)->value('id');
        refundInvoicePayment($payment, 30.00, false, false, false, 'R-' . uniqid());
        Addon::stornos()->process(1);
        $noteId = (int) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->where('billingnoteid', '>', 0)->value('billingnoteid');
        Assert::same($noteId, CreditNotePanel::noteFromRequest('/admin/billing/billingnote/credit/' . $noteId));
        Assert::same(null, CreditNotePanel::noteFromRequest('/admin/invoices.php?action=edit&id=1'));
        $vars = (new CreditNotePanel('addonmodules.php?module=efactura'))->vars($noteId);
        Assert::same(1, count($vars['stornos']));
        Assert::same((string) $document->number, $vars['original']['number']);
        Assert::true(str_contains((new CreditNotePanel('addonmodules.php?module=efactura'))->render($noteId), 'data-efactura-place="credit-note"'));
        Assert::same(null, (new CreditNotePanel())->vars(999999999), 'nothing for an unrelated note');
    }),
    'the documents list filters, the detail page and the queue' => static fn () => $clean(static function () use ($setup, $paid): void {
        $setup();
        [, $first] = $paid();
        [, $second] = $paid();
        (new AdminActions())->run('hold', ['document' => $second->id], 1);
        $page = new DocumentsPage('addonmodules.php?module=efactura');
        $numbers = static fn (array $list): array => array_column($list['documents'], 'number');
        Assert::true(in_array((string) $second->number, $numbers($page->list(['state' => 'attention'])), true));
        Assert::false(in_array((string) $first->number, $numbers($page->list(['state' => 'attention'])), true));
        Assert::same([(string) $first->number], $numbers($page->list(['q' => (string) $first->number])));
        Assert::same([(string) $first->number], $numbers($page->list(['q' => (string) $first->invoice_id, 'kind' => 'invoice'])));
        Assert::same([], $numbers($page->list(['q' => (string) $first->number, 'kind' => 'storno'])));

        $detail = $page->detail((int) $second->id);
        Assert::same((string) $second->number, $detail['doc']['number']);
        Assert::same([Lang::get('event_held'), Lang::get('event_document_created')], array_column($detail['history'], 'event'));
        Assert::same(null, $page->detail(999999999));

        (new RuntimeState())->set('storno:cancel:1', ['type' => 'cancel', 'invoice' => 1, 'since' => date('Y-m-d H:i:s')]);
        $queue = $page->queue();
        Assert::true($queue['attention'] >= 1);
        Assert::same(1, $queue['pendingStornos']);
    }),
    'the return address and the download name are safe' => static function (): void {
        $fallback = 'addonmodules.php?module=efactura';
        Assert::same('invoices.php?action=edit&id=12', AdminController::returnUrl('invoices.php?action=edit&id=12', $fallback));
        Assert::same('billing/billingnote/credit/7', AdminController::returnUrl('billing/billingnote/credit/7', $fallback));
        Assert::same('addonmodules.php?module=efactura&view=document&id=3', AdminController::returnUrl('addonmodules.php?module=efactura&view=document&id=3', $fallback));
        foreach (['https://evil.example/', '//evil.example/x', 'invoices.php?action=edit&id=1&x=<script>', 'javascript:alert(1)', 'clients.php', ''] as $bad) {
            Assert::same($fallback, AdminController::returnUrl($bad, $fallback), $bad);
        }
        Assert::same('CRK-0001_semnat_ANAF.zip', AdminController::downloadName('CRK-0001', 'anaf_zip', '3001.zip'));
        Assert::same('CRK-0001.xml', AdminController::downloadName('CRK-0001', 'xml_sent', 'x.xml'));
        Assert::same('A_B_erori_ANAF.zip', AdminController::downloadName('A"B', 'anaf_errors_zip', 'y.zip'));
    },
];
