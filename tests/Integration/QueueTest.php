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
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Queue\DeadlineMonitor;
use WHMCS\Module\Addon\Efactura\Queue\DocumentActions;
use WHMCS\Module\Addon\Efactura\Queue\Reconciler;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Crypto;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/*
 * The queue worker against the ANAF simulator, with documents created by
 * real WHMCS payments (fictive seller and clients, rolled back).
 */

$setup = static function (array $settings = []): void {
    Clock::freeze(null);
    Settings::save($settings + [
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 0,
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_cui' => '12345674', 'company_vat_payer' => true,
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B',
        'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890',
        'client_field_cui' => 'tax_id', 'client_field_cnp' => '', 'client_field_county' => 'state', 'payment_means' => [],
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

    return new Worker(new ApiClient($anaf, $connection, 'test'), $connection, Addon::documentBuilder(), Addon::documents(), new RuntimeState(), new Reconciler(new ApiClient($anaf, $connection, 'test'), Addon::documents(), new RuntimeState()), null, static function (): void {
    });
};

/**
 * Pays an invoice for a fictive client and returns its document.
 */
$document = static function (array $client = [], float $amount = 100.0): object {
    $clientId = (int) localAPI('AddClient', $client + [
        'firstname' => 'Test', 'lastname' => 'Coada', 'companyname' => 'CLIENT COADA SRL', 'tax_id' => 'RO87654329',
        'email' => 'efactura-queue-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => efactura_ron_currency(),
        'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
    $invoiceId = (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => $amount, 'itemtaxed1' => true])['invoiceid'];
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'Q-' . uniqid(), 'gateway' => 'banktransfer',
        'amount' => (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total')]);

    return Addon::documents()->forInvoice($invoiceId) ?? throw new RuntimeException('no document');
};

$reload = static fn (object $document): object => Addon::documents()->find((int) $document->id);
$travel = static function (string $modify): void {
    Clock::freeze(Clock::now()->modify($modify));
};
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
    'a document goes through upload, processing, validation and archiving' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $doc = $document();
        Assert::same(Document::STATE_SCHEDULED, $doc->state);

        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        Assert::same(['upload'], $anaf->calls());
        $request = $anaf->requests[0];
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        Assert::same(['standard' => 'UBL', 'cif' => '12345674'], $query);
        Assert::same('text/plain', $request->headers['Content-Type']);
        Assert::same('Bearer test-access-token', $request->headers['Authorization']);
        Assert::same((string) $doc->xml, $request->body, 'the frozen XML is what was sent');
        Assert::same(hash('sha256', (string) $doc->xml), $doc->xml_sha256);
        Assert::true(str_starts_with((string) parse_url($request->url, PHP_URL_PATH), '/test/FCTEL/rest/'), 'test environment');
        Assert::same(1, Capsule::table('mod_efactura_archive')->where('document_id', $doc->id)->where('kind', 'xml_sent')->count());

        // Nothing to do before the first status check is due.
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(1, count($anaf->requests));

        $travel('+2 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_PROCESSING, $reload($doc)->state);
        Assert::same('in prelucrare', $reload($doc)->anaf_state);

        $travel('+10 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_VALIDATED, $doc->state);
        Assert::same(['upload', 'stareMesaj', 'stareMesaj', 'descarcare'], $anaf->calls());
        $archive = Capsule::table('mod_efactura_archive')->where('id', $doc->archive_id)->first();
        Assert::same('anaf_zip', $archive->kind);
        Assert::same(hash('sha256', $archive->content), $archive->sha256);
        Assert::true(ResponseZip::read($archive->content)->isInvoice());
    }),
    'individuals go to uploadb2c and clients abroad get extern=DA' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup(['exclude_non_eu' => false]);
        $anaf = new AnafSimulator();
        $b2c = $document(['companyname' => '', 'tax_id' => '']);
        $abroad = $document(['companyname' => 'Example Corp', 'tax_id' => '12-3456789', 'country' => 'US', 'state' => 'NY', 'city' => 'New York']);
        $worker($anaf)->run(30, [(int) $b2c->id, (int) $abroad->id]);
        $queries = [];
        foreach ($anaf->requests as $request) {
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
            $queries[basename((string) parse_url($request->url, PHP_URL_PATH))] = $query;
        }
        Assert::same(['standard' => 'UBL', 'cif' => '12345674'], $queries['uploadb2c']);
        Assert::same(['standard' => 'UBL', 'cif' => '12345674', 'extern' => 'DA'], $queries['upload']);
        Assert::same('b2c', $reload($b2c)->buyer_type);
        Assert::same(1, (int) $reload($abroad)->upload_extern);
    }),
    'a rejected document keeps the ANAF errors and is sent again after a correction' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['nok'], ['ok']];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        $firstIndex = (string) $reload($doc)->upload_index;
        $anaf->errors[$firstIndex] = ['[BR-RO-110]-Subdiviziunea tarii cumparatorului (BT-54) trebuie codificata.'];
        $travel('+2 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_REJECTED, $doc->state);
        Assert::true(str_contains((string) $doc->errors, 'BR-RO-110'));
        Assert::same('anaf_errors_zip', Capsule::table('mod_efactura_archive')->where('id', $doc->archive_id)->value('kind'));
        Assert::same(Document::STATE_REJECTED, $doc->alerted_state);

        Assert::true((new DocumentActions(Addon::documents()))->sendNow((int) $doc->id, 1));
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        Assert::true($doc->upload_index !== $firstIndex, 'a new upload');
        Assert::same(2, (int) $doc->attempts);
    }),
    'a duplicate rejection follows the original upload' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['ok'], ['nok']];
        // An earlier upload of the same document that ANAF accepted.
        $anaf->uploadModes = ['accept'];
        $doc = $document();
        $original = $anaf->send(new Request('POST', ApiClient::baseUrl('test') . 'upload?standard=UBL&cif=12345674', [], (string) file_get_contents(dirname(__DIR__) . '/fixtures/ubl/b2b-ron-paid.xml')));
        preg_match('/index_incarcare="(\d+)"/', $original->body, $match);
        $worker($anaf)->run(30, [(int) $doc->id]);
        $anaf->errors[(string) $reload($doc)->upload_index] = ['Factura a mai fost transmisa anterior cu index=' . $match[1] . ' si data incarcare=2026-10-09'];
        $travel('+2 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same($match[1], $doc->upload_index);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_VALIDATED, $doc->state, 'the verdict of the original upload');
        Assert::same('anaf_zip', Capsule::table('mod_efactura_archive')->where('id', $doc->archive_id)->value('kind'));
    }),
    'upload refusals, transient errors and authorization problems' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['refuse', 'not_sent', 'auth'];
        $refused = $document();
        $transient = $document();
        $auth = $document();
        $later = $document();
        $worker($anaf)->run(30, [(int) $refused->id, (int) $transient->id, (int) $auth->id, (int) $later->id]);
        Assert::same(Document::STATE_REJECTED, $reload($refused)->state);
        Assert::true(str_contains((string) $reload($refused)->errors, 'SAXParseException'));
        Assert::same(Document::STATE_RETRY, $reload($transient)->state);
        Assert::same(Document::STATE_RETRY, $reload($auth)->state);
        Assert::same(Document::STATE_SCHEDULED, $reload($later)->state, 'uploads pause after an authorization error');
        Assert::same(3, count($anaf->requests));

        // After the pause the queue goes on with the same frozen XML.
        $xml = (string) $reload($transient)->xml;
        $travel('+61 minutes');
        $worker($anaf)->run(30, [(int) $transient->id, (int) $auth->id, (int) $later->id]);
        Assert::same(Document::STATE_PROCESSING, $reload($transient)->state);
        Assert::same($xml, $reload($transient)->xml);
        Assert::same(Document::STATE_PROCESSING, $reload($later)->state);
    }),
    'an upload whose answer is lost is not repeated blindly' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['lost'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_UNKNOWN, $reload($doc)->state);
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(['upload'], $anaf->calls(), 'no second upload');
    }),
    'invalid client data stops the document until it is corrected' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $doc = $document(['state' => 'Romania']);
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_INVALID, $doc->state);
        Assert::true(str_contains((string) $doc->errors, 'MAP-COUNTY'));
        Assert::same([], $anaf->calls());
        Capsule::table('tblclients')->where('id', $doc->client_id)->update(['state' => 'Dolj']);
        $travel('+16 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_PROCESSING, $reload($doc)->state);
    }),
    'daily ANAF limits are respected per message' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['in prelucrare']];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        Addon::documents()->update((int) $doc->id, ['status_checks' => 90, 'counters_date' => Clock::today()->format('Y-m-d')]);
        $travel('+2 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(['upload'], $anaf->calls());
        Assert::true(str_ends_with((string) $reload($doc)->next_attempt_at, '00:15:00'), 'tomorrow');
    }),
    'an expired answer is reported and not downloaded again' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->stateScripts = [['ok']];
        $anaf->downloadModes = ['expired'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        $travel('+2 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_VALIDATED, $doc->state);
        Assert::same(0, (int) $doc->archive_id);
        Assert::true(str_contains((string) $doc->last_error, '60 de zile'));
        $travel('+1 day');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(['upload', 'stareMesaj', 'descarcare'], $anaf->calls());
    }),
    'repeated transient failures pause the queue' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = array_fill(0, 6, 'technical');
        $docs = [];
        for ($i = 0; $i < 6; $i++) {
            $docs[] = (int) $document()->id;
        }
        $worker($anaf)->run(60, $docs);
        Assert::same(5, count($anaf->requests), 'the breaker opens after 5 failures');
        Assert::true((new RuntimeState())->get('breaker_until') !== null);
    }),
    'a lost upload is found among the sent invoices and not uploaded again' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        // An invoice sent by other software for the same seller.
        $anaf->send(new Request('POST', ApiClient::baseUrl('test') . 'upload?standard=UBL&cif=12345674', [], (string) file_get_contents(dirname(__DIR__) . '/fixtures/ubl/b2b-ron-paid.xml')));
        $anaf->uploadModes = ['lost'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_UNKNOWN, $reload($doc)->state);

        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_VALIDATED, $doc->state);
        Assert::same('5000000002', $doc->upload_index);
        Assert::same(AnafSimulator::downloadId('5000000002'), $doc->download_id);
        Assert::same('anaf_zip', Capsule::table('mod_efactura_archive')->where('id', $doc->archive_id)->value('kind'));
        Assert::same(['upload', 'upload', 'listaMesajePaginatieFactura', 'descarcare', 'descarcare'], $anaf->calls());
        parse_str((string) parse_url($anaf->requests[2]->url, PHP_URL_QUERY), $query);
        Assert::same(['T', '12345674', '1'], [$query['filtru'], $query['cif'], $query['pagina']]);

        // The other invoice is not downloaded again for the next document.
        $anaf->uploadModes = ['lost'];
        $second = $document();
        $worker($anaf)->run(30, [(int) $second->id]);
        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $second->id]);
        Assert::same(Document::STATE_VALIDATED, $reload($second)->state);
        Assert::same(3, count(array_keys($anaf->calls(), 'descarcare', true)));
    }),
    'an upload that never arrived is sent again with the same bytes after some hours' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['dropped'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        $xml = (string) $reload($doc)->xml;
        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_UNKNOWN, $reload($doc)->state, 'not resent before the waiting time');
        Assert::same(['upload', 'listaMesajePaginatieFactura'], $anaf->calls());

        $travel('+6 hours');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_PROCESSING, $doc->state);
        Assert::same(['upload', 'listaMesajePaginatieFactura', 'listaMesajePaginatieFactura', 'upload'], $anaf->calls());
        Assert::same($xml, $anaf->uploads[(string) $doc->upload_index]['xml'], 'the same bytes');
        Assert::same(2, (int) $doc->attempts);
    }),
    'an invoice of other software with the same number is not taken as ours' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['dropped'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        // Same number, date and seller, another total.
        $other = preg_replace('#<cbc:TaxInclusiveAmount currencyID="RON">[^<]*<#', '<cbc:TaxInclusiveAmount currencyID="RON">999.99<', (string) $reload($doc)->xml);
        $anaf->send(new Request('POST', ApiClient::baseUrl('test') . 'upload?standard=UBL&cif=12345674', [], (string) $other));
        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_UNKNOWN, $reload($doc)->state);
        Assert::same(['upload', 'upload', 'listaMesajePaginatieFactura', 'descarcare'], $anaf->calls());
    }),
    'a technical error at upload is resent after an hour and reported when it repeats' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['technical', 'technical'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_UNKNOWN, $reload($doc)->state);
        Assert::true(str_contains((string) $reload($doc)->last_error, 'Cod: 1814'));
        Assert::same(null, $reload($doc)->alerted_state, 'one technical error is not reported');
        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(['upload', 'listaMesajePaginatieFactura'], $anaf->calls(), 'reconciled first');
        $travel('+40 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(['upload', 'listaMesajePaginatieFactura', 'listaMesajePaginatieFactura', 'upload'], $anaf->calls(), 'sent again after an hour');
        Assert::same(Document::STATE_UNKNOWN, $doc->state);
        Assert::same(Document::STATE_UNKNOWN, $doc->alerted_state);
        Assert::same(1, Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': ' . Lang::get('alert_technical_subject', (string) $doc->number) . '%')->count());

        $travel('+21 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        $travel('+40 minutes');
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(Document::STATE_PROCESSING, $reload($doc)->state, 'accepted at the third upload');
        Assert::same(3, (int) $reload($doc)->attempts);
    }),
    'a document left in sending by a stopped worker is reconciled, not resent' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup();
        $anaf = new AnafSimulator();
        $anaf->uploadModes = ['lost'];
        $doc = $document();
        $worker($anaf)->run(30, [(int) $doc->id]);
        $started = Clock::now()->modify('-20 minutes')->format('Y-m-d H:i:s');
        Capsule::table(Document::TABLE)->where('id', $doc->id)->update(['state' => Document::STATE_SENDING, 'upload_started_at' => $started, 'lock_token' => 'crashed', 'locked_at' => $started]);

        $worker($anaf)->run(30, [(int) $doc->id]);
        $doc = $reload($doc);
        Assert::same(Document::STATE_VALIDATED, $doc->state);
        Assert::same(null, $doc->lock_token);
        Assert::same(['upload', 'listaMesajePaginatieFactura', 'descarcare'], $anaf->calls());
    }),
    'deadline alerts grow more insistent and are sent once per level' => static fn () => $clean(static function () use ($setup, $document, $reload): void {
        $setup(['send_delay_days' => 3]);
        $doc = $document();
        Assert::true((new DocumentActions(Addon::documents()))->hold((int) $doc->id, 1));
        $validated = $document();
        $today = Clock::today();
        Capsule::table(Document::TABLE)->whereIn('id', [$doc->id, $validated->id])->update(['deadline_date' => WorkingDays::add($today, 2)->format('Y-m-d')]);
        Capsule::table(Document::TABLE)->where('id', $validated->id)->update(['state' => Document::STATE_VALIDATED]);
        $monitor = new DeadlineMonitor();
        $alerts = static fn (): array => Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ':%' . $doc->number . '%')->orderBy('id')->pluck('description')->all();

        Assert::same(1, $monitor->run([(int) $doc->id, (int) $validated->id]));
        Assert::same(1, (int) $reload($doc)->deadline_alert);
        Assert::same(0, $monitor->run([(int) $doc->id, (int) $validated->id]), 'once per level');
        Assert::same(null, $reload($validated)->deadline_alert);

        Clock::freeze(WorkingDays::add($today, 2)->setTime(10, 0));
        Assert::same(1, $monitor->run([(int) $doc->id]));
        Clock::freeze(WorkingDays::add($today, 3)->setTime(10, 0));
        Assert::same(1, $monitor->run([(int) $doc->id]));
        Assert::same(4, (int) $reload($doc)->deadline_alert);
        $sent = $alerts();
        Assert::same(3, count($sent));
        Assert::true(str_contains($sent[0], Lang::get('alert_deadline_soon_subject', 1)));
        Assert::true(str_contains($sent[1], Lang::get('alert_deadline_today_subject', 1)));
        Assert::true(str_contains($sent[2], Lang::get('alert_deadline_overdue_subject', 1)));
        Assert::true(str_contains($sent[2], Lang::get('alert_deadline_late', 1)));
    }),
    'uploads processing for over a day are reported' => static fn () => $clean(static function () use ($setup, $document, $reload): void {
        $setup();
        $doc = $document();
        $update = static fn (string $uploaded) => Capsule::table(Document::TABLE)->where('id', $doc->id)->update(['state' => Document::STATE_PROCESSING, 'upload_index' => '5000000123', 'uploaded_at' => Clock::now()->modify($uploaded)->format('Y-m-d H:i:s')]);
        $monitor = new DeadlineMonitor();
        $update('-23 hours');
        Assert::same(0, $monitor->run([(int) $doc->id]));
        $update('-25 hours');
        Assert::same(1, $monitor->run([(int) $doc->id]));
        Assert::same(0, $monitor->run([(int) $doc->id]));
        $update('-49 hours');
        Assert::same(1, $monitor->run([(int) $doc->id]));
        Assert::same(2, (int) $reload($doc)->processing_alert);
    }),
    'a run stops when it reaches its memory allowance' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup();
        $anaf = new AnafSimulator();
        $first = $document();
        $second = $document();
        Worker::overrideMemoryAllowance(0);
        try {
            $report = $worker($anaf)->run(30, [(int) $first->id, (int) $second->id]);
        } finally {
            Worker::overrideMemoryAllowance(null);
        }
        Assert::same(1, $report['memory_stop'] ?? 0);
        Assert::same([], $anaf->calls(), 'nothing is started over the allowance');
        Assert::same(Document::STATE_SCHEDULED, $reload($first)->state);
        $worker($anaf)->run(30, [(int) $first->id, (int) $second->id]);
        Assert::same(['upload', 'upload'], $anaf->calls(), 'the next run goes on');
    }),
    'the worker does not grow in memory over a day of runs, technical errors included' => static fn () => $clean(static function () use ($setup, $document, $reload, $travel): void {
        $setup();
        $anaf = new AnafSimulator();
        // A third of the uploads get the intermittent technical error.
        $anaf->uploadModes = ['technical', 'accept', 'accept', 'technical', 'accept', 'accept', 'technical', 'technical'];
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = (int) $document()->id;
        }
        $connection = new Connection(new OAuthClient(new FakeTransport()));
        $api = new ApiClient($anaf, $connection, 'test');
        $state = new RuntimeState();
        $worker = new Worker($api, $connection, Addon::documentBuilder(), Addon::documents(), $state, new Reconciler($api, Addon::documents(), $state), new DeadlineMonitor(), static function (): void {
        });
        $baseline = 0;
        // 24 hours, one run every 10 minutes.
        for ($run = 1; $run <= 144; $run++) {
            $worker->run(30, $ids);
            $anaf->requests = [];
            if ($run === 12) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            $travel('+10 minutes');
        }
        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;
        Assert::true($growth < 1024 * 1024, sprintf('memory grew by %.2f MB over 132 runs', $growth / 1048576));
        foreach ($ids as $id) {
            Assert::same(Document::STATE_VALIDATED, Addon::documents()->find($id)->state, 'document ' . $id);
            Assert::true(Addon::documents()->find($id)->archive_id > 0, 'archived ' . $id);
        }
    }),
    'admin actions: hold, release, send now' => static fn () => $clean(static function () use ($setup, $worker, $document, $reload): void {
        $setup(['send_delay_days' => 3]);
        $anaf = new AnafSimulator();
        $doc = $document();
        $actions = new DocumentActions(Addon::documents());
        Assert::true($actions->hold((int) $doc->id, 1));
        Assert::same(Document::STATE_HELD, $reload($doc)->state);
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same([], $anaf->calls(), 'a held document is not sent');
        Assert::true($actions->release((int) $doc->id, 1));
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same([], $anaf->calls(), 'still waiting for its send time');
        Assert::true($actions->sendNow((int) $doc->id, 1));
        $worker($anaf)->run(30, [(int) $doc->id]);
        Assert::same(['upload'], $anaf->calls());
        Assert::false($actions->hold((int) $doc->id, 1), 'an uploaded document cannot be held');
    }),
];
