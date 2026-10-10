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

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;

/*
 * Stornos from real WHMCS cancellations and refunds (fictive seller and
 * clients, rolled back). The invoices have two taxed lines, 60 and 40 RON:
 * 100 RON net, 21 RON VAT, 121 RON in total.
 */

require_once ROOTDIR . '/includes/invoicefunctions.php';

$setup = static function (array $settings = []): void {
    Clock::freeze(null);
    Settings::save($settings + [
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 0,
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_cui' => '12345674', 'company_vat_payer' => true,
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B',
        'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890', 'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    NumberingLock::overridePaymentTimeout(1);
    Capsule::table(RuntimeState::TABLE)->where('name', 'like', 'storno%')->delete();
};

$client = static fn (array $data = []): int => (int) localAPI('AddClient', $data + [
    'firstname' => 'Test', 'lastname' => 'Storno', 'companyname' => 'CLIENT STORNO SRL', 'tax_id' => 'RO87654329',
    'email' => 'efactura-storno-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
    'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2,
    'noemail' => true, 'skipvalidation' => true,
])['clientid'];

/**
 * A paid invoice of 121 RON and its fiscal document.
 *
 * @return array{0: int, 1: object, 2: int} invoice ID, document, payment transaction ID
 */
$paid = static function (array $clientData = [], bool $taxed = true) use ($client): array {
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $client($clientData), 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 60, 'itemtaxed1' => $taxed, 'itemdescription2' => 'Domeniu', 'itemamount2' => 40, 'itemtaxed2' => $taxed])['invoiceid'];
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'S-' . uniqid(), 'gateway' => 'banktransfer',
        'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);
    $document = Addon::documents()->forInvoice($invoiceId) ?? throw new RuntimeException('no fiscal document');

    return [$invoiceId, $document, (int) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->where('amountin', '>', 0)->value('id')];
};

/**
 * Two invoices (121 and 12.10 RON) paid through a Mass Pay invoice.
 *
 * @return array{0: int, 1: list<object>, 2: int} Mass Pay invoice ID, the fiscal documents, payment transaction ID
 */
$massPay = static function () use ($client): array {
    $clientId = $client();
    $invoice = static fn (float $amount, string $text): int => (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false,
        'paymentmethod' => 'banktransfer', 'itemdescription1' => $text, 'itemamount1' => $amount, 'itemtaxed1' => true])['invoiceid'];
    $children = [$invoice(100, 'Găzduire'), $invoice(10, 'Domeniu')];
    // As WHMCS builds it: one "Invoice" line per invoice paid.
    $containerId = $invoice(1, 'Mass Pay');
    Capsule::table('tblinvoiceitems')->where('invoiceid', $containerId)->delete();
    foreach (array_combine($children, [121.00, 12.10]) as $child => $amount) {
        Capsule::table('tblinvoiceitems')->insert(['invoiceid' => $containerId, 'userid' => $clientId, 'type' => 'Invoice', 'relid' => $child,
            'description' => 'Invoice #' . $child, 'amount' => $amount, 'taxed' => 0, 'duedate' => date('Y-m-d'), 'paymentmethod' => 'banktransfer']);
    }
    Capsule::table('tblinvoices')->where('id', $containerId)->update(['subtotal' => 133.10, 'tax' => 0, 'total' => 133.10]);
    localAPI('AddInvoicePayment', ['invoiceid' => $containerId, 'transid' => 'MP-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 133.10]);

    return [
        $containerId,
        array_values(array_filter(array_map(static fn (int $id): ?object => Addon::documents()->forInvoice($id), $children))),
        (int) Capsule::table('tblaccounts')->where('invoiceid', $containerId)->where('amountin', '>', 0)->value('id'),
    ];
};

$stornos = static fn (object $original): array => Addon::documents()->stornosOf((int) $original->id);
$xml = static function (object $storno): string {
    $result = Addon::documentBuilder()->build(Addon::documents()->find((int) $storno->id));
    Assert::true($result->ok(), 'the storno builds: ' . json_encode($result->issues, JSON_UNESCAPED_UNICODE));
    if (DevValidators::available()) {
        Assert::same([], DevValidators::errors((string) $result->xml), 'official XSD and Schematron');
    }

    return (string) $result->xml;
};
// Read from the database: WHMCS caches its settings for the request.
$counter = static fn (): string => (string) Capsule::table('tblconfiguration')->where('setting', 'SequentialInvoiceNumberValue')->value('value');
$clean = static function (callable $body): void {
    try {
        $body();
    } finally {
        Clock::freeze(null);
        NumberingLock::release();
        NumberingLock::overridePaymentTimeout(null);
    }
};

return [
    'cancelling a fiscal invoice issues a full storno in the same series' => static fn () => $clean(static function () use ($setup, $paid, $stornos, $xml, $counter): void {
        $setup();
        [$invoiceId, $original] = $paid();
        $before = $counter();
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);

        $list = $stornos($original);
        Assert::same(1, count($list));
        $storno = $list[0];
        Assert::same(Document::KIND_STORNO, $storno->kind);
        Assert::same(DocumentBuilder::REASON_CANCEL, $storno->reason);
        Assert::same(Document::STATE_SCHEDULED, $storno->state);
        Assert::same(['-121.00', '-100.00', '-21.00'], [$storno->total, $storno->amount_net, $storno->amount_tax]);
        Assert::same((int) $before + 1, (int) $counter(), 'one number taken from the fiscal counter');
        Assert::same(str_replace('{NUMBER}', $before, (string) Setting::getValue('SequentialInvoiceNumberFormat')), $storno->number);
        Assert::same(Clock::today()->format('Y-m-d'), $storno->issue_date);

        $document = $xml($storno);
        Assert::same(2, substr_count($document, '<cbc:InvoicedQuantity unitCode="C62">-1</cbc:InvoicedQuantity>'), 'negative quantities');
        Assert::true(str_contains($document, '<cbc:PriceAmount currencyID="RON">60.00</cbc:PriceAmount>'), 'positive prices');
        Assert::true(str_contains($document, '<cbc:PayableAmount currencyID="RON">-121.00</cbc:PayableAmount>'));
        Assert::true(str_contains($document, '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>' . $original->number . '</cbc:ID><cbc:IssueDate>' . $original->issue_date . '</cbc:IssueDate>')
            || preg_match('#<cac:BillingReference>\s*<cac:InvoiceDocumentReference>\s*<cbc:ID>' . preg_quote((string) $original->number, '#') . '</cbc:ID>\s*<cbc:IssueDate>' . $original->issue_date . '</cbc:IssueDate>#', $document) === 1, 'BT-25 and BT-26');
        Assert::true(str_contains($document, 'Stornare totală a facturii ' . $original->number), 'BT-22');
    }),
    'cancelling a proforma does nothing' => static fn () => $clean(static function () use ($setup, $client, $counter): void {
        $setup();
        $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $client(), 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
            'itemdescription1' => 'Proformă', 'itemamount1' => 10, 'itemtaxed1' => true])['invoiceid'];
        $before = $counter();
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        Assert::same(0, Capsule::table(Document::TABLE)->where('invoice_id', $invoiceId)->count());
        Assert::same($before, $counter());
        Assert::same([], (new RuntimeState())->withPrefix('storno:'));
    }),
    'a full refund issues a storno with the invoice lines negated' => static fn () => $clean(static function () use ($setup, $paid, $stornos, $xml): void {
        $setup();
        [$invoiceId, $original, $payment] = $paid();
        refundInvoicePayment($payment, 121.00, false, false, false, 'R-' . uniqid());

        $list = $stornos($original);
        Assert::same(1, count($list));
        Assert::same(DocumentBuilder::REASON_REFUND_FULL, $list[0]->reason);
        Assert::same(Document::SOURCE_REFUND, $list[0]->source);
        Assert::true((int) $list[0]->billing_note_id > 0, 'linked to the WHMCS credit note');
        Assert::same(['-121.00', '-100.00', '-21.00'], [$list[0]->total, $list[0]->amount_net, $list[0]->amount_tax]);
        Assert::same(2, substr_count($xml($list[0]), '<cbc:InvoicedQuantity unitCode="C62">-1</cbc:InvoicedQuantity>'));
        Assert::true(str_contains($xml($list[0]), '(rambursare)'));
    }),
    'partial refunds issue one line from the credit note, and a later cancellation takes the rest' => static fn () => $clean(static function () use ($setup, $paid, $stornos, $xml): void {
        $setup();
        [$invoiceId, $original, $payment] = $paid();
        refundInvoicePayment($payment, 30.00, false, false, false, 'R-' . uniqid());
        // The credit note is created after the last hook: the request issues
        // the storno when it ends, the test does it now.
        Assert::same([], $stornos($original));
        Assert::same(1, Addon::stornos()->process(1));

        $list = $stornos($original);
        Assert::same(DocumentBuilder::REASON_REFUND_PARTIAL, $list[0]->reason);
        Assert::same(['-30.00', '-24.79', '-5.21'], [$list[0]->total, $list[0]->amount_net, $list[0]->amount_tax]);
        $partial = $xml($list[0]);
        Assert::true(str_contains($partial, '<cbc:Name>Stornare parțială factura ' . $original->number . '</cbc:Name>'));
        Assert::same(1, substr_count($partial, '<cac:InvoiceLine>'));

        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        $list = $stornos($original);
        Assert::same(2, count($list));
        Assert::same(DocumentBuilder::REASON_CANCEL_REST, $list[1]->reason);
        Assert::same(['-91.00', '-75.21', '-15.79'], [$list[1]->total, $list[1]->amount_net, $list[1]->amount_tax]);
        $rest = $xml($list[1]);
        Assert::true(str_contains($rest, '<cbc:Name>Stornare rest factura ' . $original->number . '</cbc:Name>'));
        Assert::true(str_contains($rest, '<cbc:PayableAmount currencyID="RON">-91.00</cbc:PayableAmount>'));
    }),
    'refunding the rest after a partial refund issues a second partial storno' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [, $original, $payment] = $paid();
        refundInvoicePayment($payment, 30.00, false, false, false, 'R-' . uniqid());
        Addon::stornos()->process(1);
        refundInvoicePayment($payment, 91.00, false, false, false, 'R-' . uniqid());
        $list = $stornos($original);
        Assert::same([DocumentBuilder::REASON_REFUND_PARTIAL, DocumentBuilder::REASON_REFUND_PARTIAL], array_column($list, 'reason'));
        Assert::same(['-30.00', '-91.00'], array_column($list, 'total'));
        Assert::same([], (new RuntimeState())->withPrefix('storno:'), 'nothing left pending');
    }),
    'a refund to the credit balance also issues a storno' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [, $original, $payment] = $paid();
        refundInvoicePayment($payment, 121.00, false, true, false, '');
        Assert::same([DocumentBuilder::REASON_REFUND_FULL], array_column($stornos($original), 'reason'));
    }),
    'credit notes that do not come from a refund are ignored' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [$invoiceId, $original] = $paid();
        // An adjustment credit note on the invoice without a refund (as for applied credit).
        $noteId = (int) Capsule::table('tblbillingnotes')->insertGetId(['note_type' => 'credit', 'custom_number' => '', 'client_id' => $original->client_id,
            'date_issued' => date('Y-m-d H:i:s'), 'subtotal' => 10, 'tax' => 0, 'tax2' => 0, 'total' => 10, 'taxrate' => 0, 'taxrate2' => 0, 'status' => 'closed', 'notes' => '']);
        Capsule::table('tblaccounts')->insert(['userid' => $original->client_id, 'currency' => 0, 'gateway' => '', 'date' => date('Y-m-d H:i:s'), 'description' => '',
            'amountin' => 10, 'fees' => 0, 'amountout' => 0, 'rate' => 1, 'transid' => '', 'invoiceid' => $invoiceId, 'refundid' => 0,
            'billingnoteid' => $noteId, 'type' => 'invoice_billing_adjustment_credit', 'relid' => 0]);
        Addon::stornos()->requestRefund($invoiceId);
        Assert::same(0, Addon::stornos()->process(1));
        Assert::same([], $stornos($original));
    }),
    'the storno of an invoice that is not reported is not reported either' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [$invoiceId, $original] = $paid(['companyname' => 'Beispiel GmbH', 'tax_id' => 'DE123456789', 'country' => 'DE', 'state' => 'Berlin', 'city' => 'Berlin', 'postcode' => '10115'], false);
        Assert::same(Document::STATE_EXCLUDED, $original->state);
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        $storno = $stornos($original)[0];
        Assert::same(Document::STATE_EXCLUDED, $storno->state);
        Assert::same($original->exclusion_reason, $storno->exclusion_reason);
        Assert::same(null, $storno->send_after);
    }),
    'a storno waits while the numbering is busy and is reported after 30 minutes' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [$invoiceId, $original] = $paid();
        $config = Capsule::connection()->getConfig();
        $other = new PDO('mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';dbname=' . $config['database'], $config['username'], $config['password']);
        $other->query("SELECT GET_LOCK('" . Lock::scoped('numbering') . "', 0)");
        try {
            localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
            Assert::same([], $stornos($original), 'not issued without the lock');
            Assert::same(1, count((new RuntimeState())->withPrefix('storno:cancel:')));
            Clock::freeze(Clock::now()->modify('+31 minutes'));
            Addon::stornos()->process(1);
            Assert::same(1, Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': ' . Lang::get('alert_storno_waiting_subject', (string) $original->number) . '%')->count());
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . Lock::scoped('numbering') . "')");
        }
        Assert::same(1, Addon::stornos()->process(1), 'the cron issues it');
        Assert::same(1, count($stornos($original)));
        Assert::same([], (new RuntimeState())->withPrefix('storno:'));
    }),
    'a storno is issued only once' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        [$invoiceId, $original, $payment] = $paid();
        refundInvoicePayment($payment, 121.00, false, false, false, 'R-' . uniqid());
        Addon::stornos()->requestRefund($invoiceId);
        Assert::same(0, Addon::stornos()->process(1));
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        Assert::same(1, count($stornos($original)), 'cancelling a refunded invoice reverses nothing more');
    }),
    'a full refund of a Mass Pay invoice reverses in full every invoice it paid' => static fn () => $clean(static function () use ($setup, $massPay, $stornos, $xml): void {
        $setup();
        [$containerId, $children, $payment] = $massPay();
        Assert::same(2, count($children), 'two fiscal invoices paid through it');
        refundInvoicePayment($payment, 133.10, false, false, false, 'R-' . uniqid());
        Addon::stornos()->process(1);
        $first = $stornos($children[0]);
        $second = $stornos($children[1]);
        Assert::same([DocumentBuilder::REASON_REFUND_FULL], array_column($first, 'reason'));
        Assert::same(['-121.00', '-100.00', '-21.00'], [$first[0]->total, $first[0]->amount_net, $first[0]->amount_tax]);
        Assert::same(['-12.10', '-10.00', '-2.10'], [$second[0]->total, $second[0]->amount_net, $second[0]->amount_tax]);
        Assert::true(str_contains($xml($second[0]), '<cbc:ID>' . $children[1]->number . '</cbc:ID>'));
        Assert::same(0, Capsule::table(Document::TABLE)->where('invoice_id', $containerId)->count(), 'nothing for the Mass Pay invoice itself');
        Assert::same(0, Addon::stornos()->process(1), 'issued once');
    }),
    'a partial refund of a Mass Pay invoice is left to the admin' => static fn () => $clean(static function () use ($setup, $massPay, $stornos): void {
        $setup();
        [$containerId, $children, $payment] = $massPay();
        refundInvoicePayment($payment, 50.00, false, false, false, 'R-' . uniqid());
        Addon::stornos()->process(1);
        Assert::same([], $stornos($children[0]));
        Assert::same([], $stornos($children[1]));
        Assert::same(1, Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': ' . Lang::get('alert_masspay_partial_subject', $containerId) . '%')->count());
        Assert::same([], (new RuntimeState())->withPrefix('storno:'));
    }),
    'the worker sends a storno only after its invoice is validated' => static fn () => $clean(static function () use ($setup, $paid, $stornos): void {
        $setup();
        $now = date('Y-m-d H:i:s');
        Capsule::table(Connection::TABLE)->delete();
        Capsule::table(Connection::TABLE)->insert(['id' => 1, 'client_id' => 'test-client', 'client_secret' => Crypto::encrypt('secret'),
            'access_token' => Crypto::encrypt('test-access-token'), 'refresh_token' => Crypto::encrypt('test-refresh-token'),
            'access_expires_at' => date('Y-m-d H:i:s', strtotime('+60 days')), 'refresh_expires_at' => date('Y-m-d H:i:s', strtotime('+300 days')),
            'authorized_at' => $now, 'needs_reauthorization' => 0, 'created_at' => $now, 'updated_at' => $now]);
        Capsule::table(RuntimeState::TABLE)->delete();
        [$invoiceId, $original] = $paid();
        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        $storno = $stornos($original)[0];

        $anaf = new AnafSimulator();
        $connection = new Connection(new OAuthClient(new FakeTransport()));
        $worker = new Worker(new ApiClient($anaf, $connection, 'test'), $connection, Addon::documentBuilder(), Addon::documents(), new RuntimeState(), null, null, static function (): void {
        });
        $only = [(int) $original->id, (int) $storno->id];
        $worker->run(30, $only);
        Assert::same(['upload'], $anaf->calls(), 'the invoice first');
        Clock::freeze(Clock::now()->modify('+3 minutes'));
        $worker->run(30, $only);
        $worker->run(30, $only);
        Clock::freeze(Clock::now()->modify('+10 minutes'));
        $worker->run(30, $only);
        Assert::same(Document::STATE_VALIDATED, Addon::documents()->find((int) $original->id)->state);
        Assert::same(1, count(array_keys($anaf->calls(), 'upload', true)));
        $worker->run(30, $only);
        Assert::same(2, count(array_keys($anaf->calls(), 'upload', true)), 'then the storno, at the next run');
        $sent = end($anaf->uploads)['xml'];
        Assert::true(str_contains($sent, '<cbc:ID>' . $storno->number . '</cbc:ID>') && str_contains($sent, '<cbc:ID>' . $original->number . '</cbc:ID>'));
    }),
];
