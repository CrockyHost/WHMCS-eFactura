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
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\ExternalDocuments;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Whmcs\ExchangeMention;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoiceTitle;

/*
 * Fiscal invoices and stornos issued and reported before the addon, by
 * another billing system: registered, never sent, kept at payment, and
 * reversed later as the addon's own (fictive clients, rolled back).
 */

require_once ROOTDIR . '/includes/invoicefunctions.php';

$setup = static function (): void {
    Clock::freeze(null);
    Settings::save([
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 0,
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_cui' => '12345674', 'company_vat_payer' => true,
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B',
        'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890', 'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    NumberingLock::overridePaymentTimeout(1);
    Capsule::table(RuntimeState::TABLE)->where('name', 'like', 'storno%')->delete();
};

$client = static fn (int $currency = 0): int => (int) localAPI('AddClient', [
    'firstname' => 'Test', 'lastname' => 'Vechi', 'companyname' => 'CLIENT VECHI SRL', 'tax_id' => 'RO87654329',
    'email' => 'efactura-external-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
    'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
    'currency' => $currency > 0 ? $currency : efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
])['clientid'];

/**
 * An invoice of 121 RON as the other system left it: its number, its date and
 * its status, without the addon (no hook runs).
 */
$old = static function (int $clientId, string $number, string $status, string $date = '2026-09-15'): int {
    $id = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];
    Capsule::table('tblinvoices')->where('id', $id)->update(['invoicenum' => $number, 'status' => $status, 'date' => $date, 'duedate' => $date]);

    return $id;
};

/**
 * The storno the other system issued as a WHMCS invoice of its own.
 */
$oldStorno = static function (int $clientId, string $number, string $date = '2026-09-20'): int {
    $id = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Stornare', 'itemamount1' => -100, 'itemtaxed1' => true])['invoiceid'];
    Capsule::table('tblinvoices')->where('id', $id)->update(['invoicenum' => $number, 'status' => 'Storno', 'date' => $date, 'duedate' => $date,
        'subtotal' => -100, 'tax' => -21, 'total' => -121]);

    return $id;
};

$row = static fn (string $kind, int $invoiceId, string $number, string $date, array $more = []): array => $more + [
    'line' => '0', 'kind' => $kind, 'invoice_id' => (string) $invoiceId, 'number' => $number, 'issue_date' => $date,
];
$importer = static fn (): ExternalDocuments => new ExternalDocuments(Addon::bnrRates());
$document = static fn (string $number): ?object => Capsule::table(Document::TABLE)->where('number', $number)->first();
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
    'invoices and stornos issued before the addon are registered and never sent' => static fn () => $clean(static function () use ($setup, $client, $old, $oldStorno, $row, $importer, $document): void {
        $setup();
        $clientId = $client();
        $unpaid = $old($clientId, 'VECHI-0001', 'Unpaid');
        $cancelled = $old($clientId, 'VECHI-0002', 'Cancelled');
        $storno = $oldStorno($clientId, 'VECHI-0003');
        $result = $importer()->import([
            // The storno first: the file order does not matter.
            $row('storno', $cancelled, 'VECHI-0003', '2026-09-20', ['storno_invoice_id' => (string) $storno]),
            $row('invoice', $unpaid, 'VECHI-0001', '2026-09-15'),
            $row('invoice', $cancelled, 'VECHI-0002', '2026-09-15'),
        ]);
        Assert::same([3, [], []], [$result['imported'], $result['errors'], $result['warnings']]);

        foreach (['VECHI-0001', 'VECHI-0002', 'VECHI-0003'] as $number) {
            $registered = $document($number) ?? throw new RuntimeException("{$number} not registered");
            Assert::same([Document::STATE_EXTERNAL, Document::SOURCE_IMPORT, null, null], [$registered->state, $registered->source, $registered->send_after, $registered->deadline_date], $number);
            Assert::true(Document::reported((string) $registered->state), 'counts as reported');
        }
        $registered = $document('VECHI-0003');
        Assert::same([Document::KIND_STORNO, (int) $document('VECHI-0002')->id, $storno, '-121.00', '-100.00', '-21.00'],
            [$registered->kind, (int) $registered->original_document_id, (int) $registered->storno_invoice_id, $registered->total, $registered->amount_net, $registered->amount_tax]);

        // The title of an invoice, also on the WHMCS invoice of the storno.
        Assert::same(Lang::trans('invoicenumber') . 'VECHI-0001', InvoiceTitle::fiscal($unpaid));
        Assert::same(Lang::trans('invoicenumber') . 'VECHI-0003', InvoiceTitle::fiscal($storno));
        // Reversed in full already: cancelling it again issues nothing.
        Addon::stornos()->requestCancel($cancelled);
        Assert::same(0, Addon::stornos()->process(1));
        // Registered once.
        $again = $importer()->check([$row('invoice', $unpaid, 'VECHI-0009', '2026-09-15'), $row('invoice', $cancelled, 'VECHI-0001', '2026-09-15')]);
        Assert::same(2, count($again['errors']), json_encode($again['errors']));
    }),
    'an unpaid invoice issued before the addon keeps its number and date when it is paid' => static fn () => $clean(static function () use ($setup, $client, $old, $row, $importer): void {
        $setup();
        $unpaid = $old($client(), 'VECHI-0011', 'Unpaid', '2026-09-25');
        Assert::same(1, $importer()->import([$row('invoice', $unpaid, 'VECHI-0011', '2026-09-25')])['imported']);
        $counter = static fn (): string => (string) Capsule::table('tblconfiguration')->where('setting', 'SequentialInvoiceNumberValue')->value('value');
        $before = $counter();

        localAPI('AddInvoicePayment', ['invoiceid' => $unpaid, 'transid' => 'V-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
        $invoice = Capsule::table('tblinvoices')->where('id', $unpaid)->first(['status', 'invoicenum', 'date']);
        Assert::same(['Paid', 'VECHI-0011', '2026-09-25'], [$invoice->status, $invoice->invoicenum, (string) $invoice->date]);
        Assert::same($before, $counter(), 'no number of the fiscal series used');
        Assert::same(1, Capsule::table(Document::TABLE)->where('invoice_id', $unpaid)->count(), 'no second fiscal invoice');
    }),
    'a later cancellation reverses an invoice issued before the addon, and the storno is sent' => static fn () => $clean(static function () use ($setup, $client, $old, $row, $importer, $document): void {
        $setup();
        $paid = $old($client(), 'VECHI-0021', 'Paid');
        Assert::same(1, $importer()->import([$row('invoice', $paid, 'VECHI-0021', '2026-09-15')])['imported']);

        localAPI('UpdateInvoice', ['invoiceid' => $paid, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        $stornos = Addon::documents()->stornosOf((int) $document('VECHI-0021')->id);
        Assert::same(1, count($stornos));
        Assert::same([Document::STATE_SCHEDULED, null, '-121.00'], [$stornos[0]->state, $stornos[0]->exclusion_reason, $stornos[0]->total], 'reported to ANAF');
        $result = Addon::documentBuilder()->build(Addon::documents()->find((int) $stornos[0]->id));
        Assert::true($result->ok(), json_encode($result->issues, JSON_UNESCAPED_UNICODE));
        Assert::true(str_contains((string) $result->xml, '<cbc:ID>VECHI-0021</cbc:ID>') && str_contains((string) $result->xml, '<cbc:IssueDate>2026-09-15</cbc:IssueDate>'), 'BT-25 and BT-26');
    }),
    'an invoice in EUR keeps the BNR rate of its date' => static fn () => $clean(static function () use ($setup, $client, $old, $row, $importer, $document): void {
        $setup();
        efactura_bnr_import(efactura_recent_eur_rates());
        $date = Clock::today()->format('Y-m-d');
        $invoice = $old($client(efactura_eur_currency()), 'VECHI-0031', 'Unpaid', $date);
        $result = $importer()->import([$row('invoice', $invoice, 'VECHI-0031', $date)]);
        Assert::same([1, []], [$result['imported'], $result['warnings']]);
        $rate = Addon::bnrRates()->downloaded('EUR', Clock::today()) ?? throw new RuntimeException('no rate');
        Assert::same([$rate->rate, $rate->date->format('Y-m-d')], [Money::trimDecimal((string) $document('VECHI-0031')->exchange_rate), (string) $document('VECHI-0031')->exchange_rate_date]);
        Assert::true(str_contains((string) ExchangeMention::forInvoice($invoice, 'romanian', false), 'Curs BNR 1 EUR = ' . str_replace('.', ',', $rate->rate)), 'the PDF mention');
    }),
    'rows with errors are reported and nothing is imported' => static fn () => $clean(static function () use ($setup, $client, $old, $row, $importer): void {
        $setup();
        $clientId = $client();
        $first = $old($clientId, 'VECHI-0041', 'Paid');
        $second = $old($clientId, 'VECHI-0042', 'Paid');
        $other = $old($client(), 'VECHI-0043', 'Paid');
        $result = $importer()->import([
            $row('invoice', $first, 'VECHI-0041', '2026-09-15', ['line' => '2']),
            $row('invoice', 999999999, 'VECHI-0049', '2026-09-15', ['line' => '3']),
            $row('invoice', $second, 'VECHI-0041', '2026-09-15', ['line' => '4']),
            $row('invoice', $second, 'VECHI-0042', '15.09.2026', ['line' => '5']),
            $row('storno', $first, 'VECHI-0044', '2026-09-16', ['line' => '6', 'net' => '-150', 'tax' => '-31.50']),
            $row('storno', $first, 'VECHI-0045', '2026-09-16', ['line' => '7', 'storno_invoice_id' => (string) $other]),
            $row('storno', $second, 'VECHI-0046', '2026-09-16', ['line' => '8', 'net' => '-100', 'tax' => '-21']),
            $row('credit', $first, 'VECHI-0047', '2026-09-16', ['line' => '9']),
        ]);
        Assert::same(0, $result['imported']);
        Assert::same(['line 3', 'line 4', 'line 5', 'line 6', 'line 7', 'line 8', 'line 9'], array_map(static fn (string $error): string => explode(':', $error)[0], $result['errors']));
        Assert::same(0, Capsule::table(Document::TABLE)->whereIn('invoice_id', [$first, $second, $other])->count(), 'nothing written');
    }),
];
