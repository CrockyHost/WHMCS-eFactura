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
use WHMCS\Module\Addon\Efactura\Admin\InvoicePanel;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\ClientData\ClientFields;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\References;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Queue\DocumentActions;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/*
 * The references of an invoice (BT-11, BT-12, BT-13, BT-15, BT-19, BT-46):
 * typed on the invoice, defaults in the client profile, frozen when the
 * document is sent, carried by the storno (fictive seller and clients,
 * rolled back).
 */

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
    $now = date('Y-m-d H:i:s');
    Capsule::table(Connection::TABLE)->delete();
    Capsule::table(Connection::TABLE)->insert(['id' => 1, 'client_id' => 'test-client', 'client_secret' => Crypto::encrypt('secret'),
        'access_token' => Crypto::encrypt('test-access-token'), 'refresh_token' => Crypto::encrypt('test-refresh-token'),
        'access_expires_at' => date('Y-m-d H:i:s', strtotime('+60 days')), 'refresh_expires_at' => date('Y-m-d H:i:s', strtotime('+300 days')),
        'authorized_at' => $now, 'needs_reauthorization' => 0, 'created_at' => $now, 'updated_at' => $now]);
    Capsule::table(RuntimeState::TABLE)->delete();
};

$worker = static function (AnafSimulator $anaf): Worker {
    $connection = new Connection(new OAuthClient(new FakeTransport()));

    return new Worker(new ApiClient($anaf, $connection, 'test'), $connection, Addon::documentBuilder(), Addon::documents(), new RuntimeState(), null, null, static function (): void {
    });
};

/**
 * Sets the client defaults of the references, as an admin does in the
 * client profile (HTML-escaped, as WHMCS stores them).
 *
 * @param array<string, string> $values business term => value
 */
$defaults = static function (int $clientId, array $values): void {
    foreach ($values as $term => $value) {
        $field = ClientFields::id(strtolower(str_replace('-', '', $term)));
        Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field)->where('relid', $clientId)->delete();
        Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $field, 'relid' => $clientId, 'value' => htmlspecialchars($value, ENT_QUOTES)]);
    }
};

/**
 * A public institution with a proforma, and its client defaults.
 *
 * @param array<string, string> $clientDefaults
 * @return array{0: int, 1: int} client and invoice IDs
 */
$proforma = static function (array $clientDefaults = []) use ($defaults): array {
    $clientId = (int) localAPI('AddClient', [
        'firstname' => 'Ana', 'lastname' => 'Achizitii', 'companyname' => 'PRIMARIA COMUNEI EXEMPLU', 'tax_id' => '87654329',
        'email' => 'efactura-refs-' . uniqid() . '@example.invalid', 'address1' => 'Strada Principala 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
    $defaults($clientId, $clientDefaults);
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];

    return [$clientId, $invoiceId];
};

$pay = static function (int $invoiceId): object {
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'REF-' . uniqid(), 'gateway' => 'banktransfer',
        'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);

    return Addon::documents()->forInvoice($invoiceId) ?? throw new RuntimeException('no document');
};

/**
 * Saves references from the e-Factura panel of the invoice.
 *
 * @param array<string, string> $values
 * @return array{type: string, text: string, details: list<string>}
 */
$type = static fn (int $invoiceId, array $values): array => (new AdminActions())->run('references', ['invoice' => (string) $invoiceId, 'references' => $values], 1);

/**
 * The references in an XML.
 *
 * @return array<string, string>
 */
$inXml = static function (string $xml): array {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $paths = [
        'BT-11' => '/*/cac:ProjectReference/cbc:ID', 'BT-12' => '/*/cac:ContractDocumentReference/cbc:ID', 'BT-13' => '/*/cac:OrderReference/cbc:ID',
        'BT-15' => '/*/cac:ReceiptDocumentReference/cbc:ID', 'BT-19' => '/*/cbc:AccountingCost',
        'BT-46' => '/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID',
    ];
    $values = [];
    foreach ($paths as $term => $path) {
        $value = (string) $xpath->evaluate('string(' . $path . ')');
        if ($value !== '') {
            $values[$term] = $value;
        }
    }

    return $values;
};

$reload = static fn (object $document): object => Addon::documents()->find((int) $document->id);
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
    'a value only on the client goes into the XML' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $inXml, $reload): void {
        $setup();
        [, $invoiceId] = $proforma(['BT-12' => 'Contract 77 & 78/2026', 'BT-46' => 'CLIENT-0042']);
        $doc = $pay($invoiceId);
        $worker(new AnafSimulator())->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        Assert::same(['BT-12' => 'Contract 77 & 78/2026', 'BT-46' => 'CLIENT-0042'], $inXml((string) $doc->xml));
        Assert::same(['BT-12' => 'Contract 77 & 78/2026', 'BT-46' => 'CLIENT-0042'], References::frozen($doc), 'kept on the document');
    }),
    'a value only on the invoice goes into the XML' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $type, $inXml, $reload): void {
        $setup();
        [, $invoiceId] = $proforma();
        $result = $type($invoiceId, ['BT-11' => 'PRJ-7', 'BT-13' => " CMD\n4521 ", 'BT-15' => 'NIR 318', 'BT-19' => '20.01.30']);
        Assert::same('success', $result['type'], $result['text']);
        Assert::same(['BT-11' => 'PRJ-7', 'BT-13' => 'CMD 4521', 'BT-15' => 'NIR 318', 'BT-19' => '20.01.30'], References::ofInvoice($invoiceId));
        Assert::same('BT-11: PRJ-7; BT-13: CMD 4521; BT-15: NIR 318; BT-19: 20.01.30', Capsule::table('mod_efactura_audit')->where('invoice_id', $invoiceId)->where('event', 'references_saved')->value('message'));

        $doc = $pay($invoiceId);
        $worker(new AnafSimulator())->run(30, [(int) $doc->id]);
        Assert::same(['BT-11' => 'PRJ-7', 'BT-13' => 'CMD 4521', 'BT-15' => 'NIR 318', 'BT-19' => '20.01.30'], $inXml((string) $reload($doc)->xml));
    }),
    'the invoice value wins; an empty field takes the client default, shown as placeholder' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $type, $inXml, $reload, $defaults): void {
        $setup();
        [$clientId, $invoiceId] = $proforma(['BT-12' => 'C-CLIENT', 'BT-13' => 'PO-CLIENT']);
        Assert::same('success', $type($invoiceId, ['BT-12' => 'C-FACTURA', 'BT-13' => ''])['type']);
        $html = (new InvoicePanel())->render($invoiceId);
        Assert::true(str_contains($html, 'name="references[BT-12]" value="C-FACTURA" placeholder="C-CLIENT"'), 'the invoice value, the default as placeholder');
        Assert::true(str_contains($html, 'name="references[BT-13]" value="" placeholder="PO-CLIENT"'));

        $doc = $pay($invoiceId);
        // The default is read when the XML is built, like the rest of the client data.
        $defaults($clientId, ['BT-13' => 'PO-CLIENT-2']);
        $worker(new AnafSimulator())->run(30, [(int) $doc->id]);
        Assert::same(['BT-12' => 'C-FACTURA', 'BT-13' => 'PO-CLIENT-2'], $inXml((string) $reload($doc)->xml));
    }),
    'the references freeze when the document is sent; a rejected one can be corrected' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $type, $inXml, $reload, $defaults): void {
        $setup();
        [$clientId, $invoiceId] = $proforma(['BT-13' => 'PO-1']);
        Assert::same('success', $type($invoiceId, ['BT-12' => 'C-1'])['type']);
        $doc = $pay($invoiceId);
        Assert::same('success', $type($invoiceId, ['BT-12' => 'C-2'])['type'], 'paid, not sent yet: it can still change');

        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['nok'], ['ok']];
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(['BT-12' => 'C-2', 'BT-13' => 'PO-1'], $inXml((string) $doc->xml));

        $result = $type($invoiceId, ['BT-12' => 'C-3']);
        Assert::same(['warning', Lang::get('references_error_frozen')], [$result['type'], $result['text']]);
        Assert::same(['BT-12' => 'C-2'], References::ofInvoice($invoiceId), 'not saved');
        $defaults($clientId, ['BT-13' => 'PO-2']);
        $html = (new InvoicePanel())->render($invoiceId);
        Assert::false(str_contains($html, 'name="references['), 'no form once sent');
        Assert::true(str_contains($html, Lang::get('panel_references_frozen')) && str_contains($html, '<td>PO-1</td>') && !str_contains($html, 'PO-2'), 'the values sent');

        // Rejected by ANAF: corrected and sent again, the XML is built again.
        Clock::freeze(Clock::now()->modify('+2 minutes'));
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_REJECTED, $reload($doc)->state);
        Assert::same('success', $type($invoiceId, ['BT-12' => 'C-3'])['type']);
        Assert::true((new DocumentActions(Addon::documents()))->sendNow((int) $doc->id, 1));
        Assert::same([], References::frozen($reload($doc)));
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        Assert::same(['BT-12' => 'C-3', 'BT-13' => 'PO-2'], $inXml((string) $doc->xml));
        Assert::same(['BT-12' => 'C-3', 'BT-13' => 'PO-2'], References::frozen($doc));
    }),
    'a storno carries the references of the invoice it corrects' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $type, $inXml, $reload, $defaults): void {
        $setup();
        [$clientId, $invoiceId] = $proforma(['BT-13' => 'PO-1', 'BT-46' => 'CLIENT-0042']);
        $type($invoiceId, ['BT-12' => 'C-1']);
        $doc = $pay($invoiceId);
        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['ok'], ['ok']];
        $worker($anaf)->run(30, [(int) $doc->id]);
        Clock::freeze(Clock::now()->modify('+2 minutes'));
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_VALIDATED, $reload($doc)->state);

        // Changed after the invoice was sent: not for its storno.
        $defaults($clientId, ['BT-13' => 'PO-2', 'BT-19' => 'CONT-NOU']);
        Capsule::table(References::TABLE)->where('invoice_id', $invoiceId)->update(['refs' => References::encode(['BT-12' => 'C-2'])]);

        localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
        Addon::stornos()->process(1);
        $storno = Addon::documents()->stornosOf((int) $doc->id)[0] ?? throw new RuntimeException('no storno');
        $worker($anaf)->run(30, [(int) $storno->id]);
        $storno = $reload($storno);
        Assert::same(Document::STATE_PROCESSING, $storno->state);
        Assert::same(['BT-12' => 'C-1', 'BT-13' => 'PO-1', 'BT-46' => 'CLIENT-0042'], $inXml((string) $storno->xml));
        Assert::same(['BT-12' => 'C-1', 'BT-13' => 'PO-1', 'BT-46' => 'CLIENT-0042'], References::frozen($storno));
        Assert::false(References::editable($invoiceId, $reload($doc)), 'a cancelled invoice keeps them');
    }),
    'too long: refused on the invoice, and a client default stops the document with the rule' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $worker, $type, $inXml, $reload): void {
        $setup();
        [, $invoiceId] = $proforma(['BT-19' => str_repeat('x', 101)]);
        $result = $type($invoiceId, ['BT-12' => str_repeat('c', 201), 'BT-46' => 'CLIENT-0042']);
        Assert::same(['warning', Lang::get('references_error_length')], [$result['type'], $result['text']]);
        Assert::same([Lang::get('references_too_long', Lang::get('ref_bt12'), 200)], $result['details']);
        Assert::same([], References::ofInvoice($invoiceId), 'nothing saved');

        $doc = $pay($invoiceId);
        $worker(new AnafSimulator())->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_INVALID, $doc->state, 'the client default is too long');
        Assert::true(str_contains((string) $doc->errors, 'BR-RO-L100'));

        // Typed on the invoice, it takes the place of the default.
        $saved = $type($invoiceId, ['BT-19' => '20.01.30']);
        Assert::same('success', $saved['type'], $saved['text']);
        Clock::freeze(Clock::now()->modify('+16 minutes'));
        $worker(new AnafSimulator())->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_PROCESSING, $reload($doc)->state);
        Assert::same(['BT-19' => '20.01.30'], $inXml((string) $reload($doc)->xml));
    }),
    'nothing is saved for an invoice that is not reported, or while it is being sent' => static fn () => $clean(static function () use ($setup, $proforma, $pay, $type): void {
        $setup();
        [, $invoiceId] = $proforma();
        $doc = $pay($invoiceId);
        Assert::true(Addon::documents()->claim((int) $doc->id, [Document::STATE_SCHEDULED]) !== null, 'the worker holds it');
        $result = $type($invoiceId, ['BT-12' => 'C-1']);
        Assert::same(['warning', Lang::get('references_error_busy')], [$result['type'], $result['text']]);
        Addon::documents()->releaseClaim((int) $doc->id);
        Assert::same(null, References::save($invoiceId, ['BT-12' => 'C-1'], 1));

        // A Mass Pay invoice is not a fiscal invoice.
        [$clientId] = $proforma();
        $massPay = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
            'itemdescription1' => 'Plata facturilor', 'itemamount1' => 100])['invoiceid'];
        Capsule::table('tblinvoiceitems')->where('invoiceid', $massPay)->update(['type' => 'Invoice']);
        Assert::same(References::ERROR_NOT_REPORTED, References::save($massPay, ['BT-12' => 'C-1'], 1));
        Assert::false(str_contains((new InvoicePanel())->render($massPay), 'efactura-references'), 'no section');
    }),
];
