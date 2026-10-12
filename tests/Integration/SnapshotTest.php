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
use WHMCS\Module\Addon\Efactura\ClientData\ClientFields;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoiceSnapshot;

/*
 * The client details WHMCS keeps for the invoice PDF (Store Client Data
 * Snapshot) follow the profile until the invoice is sent, so the PDF and
 * the XML show the same buyer (fictive seller and clients, rolled back).
 */

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
    Setting::setValue(InvoiceSnapshot::SETTING, 'on');
    // The CUI of the addon client fields is printed on invoices.
    Capsule::table('tblcustomfields')->where('id', ClientFields::id('cui'))->update(['showinvoice' => 'on']);
};

/**
 * A proforma for an individual, who then becomes a company with a CUI.
 *
 * @return array{0: int, 1: int} client and invoice IDs
 */
$proformaThenCompany = static function (): array {
    $clientId = (int) localAPI('AddClient', [
        'firstname' => 'Maria', 'lastname' => 'Copie', 'companyname' => '', 'tax_id' => '',
        'email' => 'efactura-snapshot-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];
    Assert::same('', (string) (json_decode((string) Capsule::table('mod_invoicedata')->where('invoiceid', $invoiceId)->value('clientsdetails'), true)['companyname'] ?? ''), 'the copy taken at creation');

    Capsule::table('tblclients')->where('id', $clientId)->update(['companyname' => 'FIRMA NOUA SRL']);
    Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => ClientFields::id('cui'), 'relid' => $clientId, 'value' => 'RO87654329']);

    return [$clientId, $invoiceId];
};

/**
 * A proforma for a company with the addon CUI field and the WHMCS VAT
 * number given, as WHMCS copies it when it creates the invoice.
 *
 * @return array{0: int, 1: int} client and invoice IDs
 */
$companyProforma = static function (string $taxId, string $cui): array {
    $clientId = (int) localAPI('AddClient', [
        'firstname' => 'Ion', 'lastname' => 'Firma', 'companyname' => 'FIRMA PDF SRL', 'tax_id' => $taxId,
        'email' => 'efactura-snapshot-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
    Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => ClientFields::id('cui'), 'relid' => $clientId, 'value' => $cui]);
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];

    return [$clientId, $invoiceId];
};

$snapshot = static function (int $invoiceId): array {
    $row = Capsule::table('mod_invoicedata')->where('invoiceid', $invoiceId)->first();

    return ['details' => json_decode((string) $row->clientsdetails, true), 'fields' => json_decode((string) $row->customfields, true)];
};

$pdfText = static fn (int $invoiceId): string => efactura_pdf_text($invoiceId);


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
    'paying a proforma refreshes the copy: the PDF and the XML show the same buyer' => static fn () => $clean(static function () use ($setup, $proformaThenCompany, $snapshot, $pdfText): void {
        $setup();
        [, $invoiceId] = $proformaThenCompany();
        localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'SN-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
        $copy = $snapshot($invoiceId);
        Assert::same('FIRMA NOUA SRL', $copy['details']['companyname']);
        Assert::same(['RO87654329'], array_column($copy['fields'], 'value'));

        $xml = (string) Addon::documentBuilder()->build(Addon::documents()->forInvoice($invoiceId))->xml;
        Assert::true(str_contains($xml, 'FIRMA NOUA SRL') && str_contains($xml, '87654329'), 'the XML');
        $pdf = $pdfText($invoiceId);
        Assert::true(str_contains($pdf, 'FIRMA NOUA SRL'), 'the company in the PDF');
        Assert::true(str_contains($pdf, 'RO87654329'), 'the CUI in the PDF');
    }),
    'issuing early refreshes the copy too' => static fn () => $clean(static function () use ($setup, $proformaThenCompany, $snapshot): void {
        $setup();
        [, $invoiceId] = $proformaThenCompany();
        Addon::earlyIssue()->issue($invoiceId, 1, 1);
        Assert::same('FIRMA NOUA SRL', $snapshot($invoiceId)['details']['companyname']);
    }),
    'the copy follows the profile until the invoice is sent, then stays' => static fn () => $clean(static function () use ($setup, $proformaThenCompany, $snapshot): void {
        $setup();
        [$clientId, $invoiceId] = $proformaThenCompany();
        localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'SN-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
        $document = Addon::documents()->forInvoice($invoiceId);
        // Corrected after the payment, before the sending.
        Capsule::table('tblclients')->where('id', $clientId)->update(['companyname' => 'FIRMA CORECTATA SRL']);

        $now = date('Y-m-d H:i:s');
        Capsule::table(Connection::TABLE)->delete();
        Capsule::table(Connection::TABLE)->insert(['id' => 1, 'client_id' => 'test-client', 'client_secret' => Crypto::encrypt('secret'),
            'access_token' => Crypto::encrypt('test-access-token'), 'refresh_token' => Crypto::encrypt('test-refresh-token'),
            'access_expires_at' => date('Y-m-d H:i:s', strtotime('+60 days')), 'refresh_expires_at' => date('Y-m-d H:i:s', strtotime('+300 days')),
            'authorized_at' => $now, 'needs_reauthorization' => 0, 'created_at' => $now, 'updated_at' => $now]);
        Capsule::table(RuntimeState::TABLE)->delete();
        $anaf = new AnafSimulator();
        $connection = new Connection(new OAuthClient(new FakeTransport()));
        $worker = new Worker(new ApiClient($anaf, $connection, 'test'), $connection, Addon::documentBuilder(), Addon::documents(), new RuntimeState(), null, null, static function (): void {
        });
        $worker->run(30, [(int) $document->id]);
        Assert::same(Document::STATE_PROCESSING, Addon::documents()->find((int) $document->id)->state);
        Assert::same('FIRMA CORECTATA SRL', $snapshot($invoiceId)['details']['companyname'], 'refreshed when sent');
        Assert::true(str_contains((string) Addon::documents()->find((int) $document->id)->xml, 'FIRMA CORECTATA SRL'));

        Capsule::table('tblclients')->where('id', $clientId)->update(['companyname' => 'ALT NUME DUPA TRIMITERE SRL']);
        Clock::freeze(Clock::now()->modify('+5 minutes'));
        $worker->run(30, [(int) $document->id]);
        Assert::same('FIRMA CORECTATA SRL', $snapshot($invoiceId)['details']['companyname'], 'not touched after sending');
    }),
    'a VAT payer shows the CUI once, as the VAT number; a company that is not one keeps its CUI' => static fn () => $clean(static function () use ($setup, $companyProforma, $snapshot, $pdfText): void {
        $setup();
        $cuiField = ClientFields::id('cui');
        [, $payer] = $companyProforma('RO87654329', '87654329');
        Assert::same([], array_values(array_filter($snapshot($payer)['fields'], static fn (array $field): bool => (int) $field['id'] === $cuiField)), 'the proforma copy, taken at creation');
        $pdf = $pdfText($payer);
        Assert::same(1, substr_count($pdf, '87654329'), 'once in the proforma PDF');
        Assert::true(str_contains($pdf, 'RO87654329'), 'as the VAT number');

        localAPI('AddInvoicePayment', ['invoiceid' => $payer, 'transid' => 'SN-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
        Assert::true(Addon::documents()->forInvoice($payer) !== null, 'fiscal');
        Assert::same(1, substr_count($pdfText($payer), '87654329'), 'once in the fiscal invoice PDF too');

        [, $other] = $companyProforma('', '87654329');
        Assert::same(['87654329'], array_column(array_values(array_filter($snapshot($other)['fields'], static fn (array $field): bool => (int) $field['id'] === $cuiField)), 'value'));
        $pdf = $pdfText($other);
        Assert::true(str_contains($pdf, '87654329') && !str_contains($pdf, 'RO87654329'), 'the CUI stays when there is no VAT number');

        $fields = [['id' => $cuiField, 'fieldname' => 'CUI', 'value' => '87654329'], ['id' => ClientFields::id('regcom'), 'fieldname' => 'Reg', 'value' => 'J16/1/2020']];
        Assert::same(1, count(InvoiceSnapshot::withoutRepeatedCui($fields, 'ro 87 654 329')), 'written another way');
        Assert::same(1, count(InvoiceSnapshot::withoutRepeatedCui([['id' => $cuiField, 'fieldname' => 'CUI', 'value' => 'RO87654329'], $fields[1]], 'RO87654329')), 'the CUI typed with RO');
        Assert::same(2, count(InvoiceSnapshot::withoutRepeatedCui($fields, 'RO12345674')), 'another code');
        Assert::same(2, count(InvoiceSnapshot::withoutRepeatedCui($fields, '87654329')), 'a VAT number without RO');
    }),
    'with the copy off, nothing changes' => static fn () => $clean(static function () use ($setup, $companyProforma, $pdfText): void {
        $setup();
        Setting::setValue(InvoiceSnapshot::SETTING, '');
        [, $invoiceId] = $companyProforma('RO87654329', '87654329');
        Assert::false(Capsule::table('mod_invoicedata')->where('invoiceid', $invoiceId)->exists(), 'WHMCS kept no copy');
        Assert::false(InvoiceSnapshot::tidy($invoiceId));
        Assert::same(2, substr_count($pdfText($invoiceId), '87654329'), 'the PDF of the profile: VAT number and CUI');
        Setting::setValue(InvoiceSnapshot::SETTING, 'on');
    }),
    'the dashboard warns when WHMCS keeps no copy' => static fn () => $clean(static function () use ($setup): void {
        $setup();
        $status = static function (): string {
            foreach ((new \WHMCS\Module\Addon\Efactura\Admin\HealthChecks(new \WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig()))->all() as $check) {
                if ($check['label'] === \WHMCS\Module\Addon\Efactura\Support\Lang::get('check_snapshot')) {
                    return $check['status'];
                }
            }

            return 'missing';
        };
        Assert::same('ok', $status());
        Setting::setValue(InvoiceSnapshot::SETTING, '');
        Assert::same('warning', $status());
        Assert::false(InvoiceSnapshot::refresh(1), 'nothing to refresh');
        Setting::setValue(InvoiceSnapshot::SETTING, 'on');
    }),
];
