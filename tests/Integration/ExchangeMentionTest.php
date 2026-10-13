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
use WHMCS\Module\Addon\Efactura\Admin\HealthChecks;
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Exchange\ExchangeRate;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Whmcs\ExchangeMention;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/*
 * The BNR exchange rate and the VAT in RON in the PDF of a fiscal invoice in
 * another currency than RON: the mention of its XML, in the language of the
 * client, from the rates the cron downloads every day (fictive seller,
 * clients and rates, rolled back).
 */

$setup = static function (): void {
    Clock::freeze(null);
    Settings::save([
        'enabled' => true, 'environment' => 'test', 'send_delay_days' => 1,
        'company_legal_name' => 'EXEMPLU HOSTING S.R.L.', 'company_trade_name' => 'Exemplu Hosting', 'company_cui' => '12345674',
        'company_vat_payer' => true, 'company_vat_on_collection' => false, 'company_reg_com' => 'J40/1234/2020', 'company_share_capital' => '200 RON',
        'company_street' => 'Strada Exemplului nr. 10', 'company_city' => 'SECTOR3', 'company_county' => 'RO-B', 'company_postcode' => '030000',
        'company_email' => 'facturare@exemplu-hosting.invalid', 'iban_ron' => 'RO49AAAA1B31007593840000', 'iban_eur' => 'RO66BACX0000001234567890',
        'payment_means' => [],
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'early_issue_groups' => [], 'early_issue_clients' => [],
    ]);
    NumberingLock::overridePaymentTimeout(1);
    // Nothing downloaded: each test brings its rates.
    Capsule::table(BnrRates::TABLE)->where('rate_date', '>=', Clock::today()->modify('-40 days')->format('Y-m-d'))->delete();
    Capsule::table(BnrRates::TABLE)->whereBetween('rate_date', ['2026-09-01', '2026-10-31'])->delete();
    $state = new RuntimeState();
    foreach (['bnr_coverage', 'bnr_download', 'exchange_mention_missing'] as $name) {
        $state->forget($name);
    }
};

/**
 * A fictive company client who reads invoices in $language.
 */
$client = static function (string $language, int $currency, array $fields = []): int {
    $result = localAPI('AddClient', $fields + [
        'firstname' => 'Test', 'lastname' => 'Curs', 'companyname' => 'CLIENT VALUTA SRL', 'tax_id' => '87654329', 'language' => $language,
        'email' => 'efactura-rate-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test nr. 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => $currency, 'noemail' => true, 'skipvalidation' => true,
    ]);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException('AddClient: ' . ($result['message'] ?? ''));
    }

    return (int) $result['clientid'];
};

$invoice = static function (int $clientId, string $notes = '', bool $taxed = true): int {
    return (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'date' => date('Y-m-d'), 'duedate' => date('Y-m-d', strtotime('+14 days')), 'notes' => $notes,
        'itemdescription1' => 'Server dedicat', 'itemamount1' => 100, 'itemtaxed1' => $taxed])['invoiceid'];
};

$pay = static function (int $invoiceId): void {
    $total = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
    localAPI('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'TR-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => $total]);
};

$number = static fn (int $invoiceId): string => (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('invoicenum');
$tax = static fn (int $invoiceId): int => Money::cents((string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('tax'));
// pdftotext breaks lines where the PDF does.
$pdf = static fn (int $invoiceId): string => (string) preg_replace('/\s+/u', ' ', efactura_pdf_text($invoiceId));

/**
 * The mention as it must read, from the rate and VAT given here.
 */
$expected = static function (string $language, ExchangeRate $rate, int $taxCents): string {
    $ron = Money::format(Money::multiply($taxCents, $rate->rate));

    return $language === 'romanian'
        ? sprintf('Curs BNR 1 EUR = %s RON din %s; TVA: %s RON', str_replace('.', ',', $rate->rate), $rate->date->format('d.m.Y'), str_replace('.', ',', $ron))
        : sprintf('BNR exchange rate of %s: 1 EUR = %s RON; VAT: %s RON', $rate->date->format('d.m.Y'), $rate->rate, $ron);
};

$ratesCheck = static function (): array {
    foreach ((new HealthChecks(new InvoicingConfig()))->all() as $check) {
        if ($check['label'] === Lang::get('check_rates')) {
            return $check;
        }
    }
    throw new RuntimeException('BNR rates check missing');
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

Lang::boot('english');

// The Romanian PDFs first: WHMCS leaves the language of the last PDF active.
return [
    'in Romanian: the PDF of an EUR fiscal invoice shows the BNR rate and the VAT in RON of its XML, a proforma does not' => static fn () => $clean(static function () use ($setup, $client, $invoice, $tax, $pdf, $expected): void {
        $setup();
        efactura_bnr_import(efactura_recent_eur_rates());
        $eur = efactura_eur_currency();
        $early = $invoice($client('romanian', $eur), 'Comanda 123');
        $proforma = $invoice($client('romanian', $eur));
        Addon::earlyIssue()->issue($early, 1, 1);

        $rate = Addon::bnrRates()->downloaded('EUR', Clock::today());
        Assert::true($rate !== null, 'the rate published before today');
        $latest = array_key_last(efactura_recent_eur_rates());
        Assert::same($latest, $rate->date->format('Y-m-d'), 'the latest rate before today');
        $mention = $expected('romanian', $rate, $tax($early));
        $text = $pdf($early);
        Assert::true(str_contains($text, $mention), 'the PDF: ' . $text);
        Assert::true(str_contains($text, 'Comanda 123'), 'the notes of the invoice stay');

        // The XML of the same document carries exactly the same mention.
        $result = Addon::documentBuilder()->build(Addon::documents()->forInvoice($early));
        Assert::true($result->ok(), 'not generated: ' . json_encode($result->issues, JSON_UNESCAPED_UNICODE));
        Assert::true(str_contains((string) $result->xml, '<cbc:Note>' . $mention . '</cbc:Note>'), 'the XML note');

        Assert::false(str_contains($pdf($proforma), 'Curs BNR'), 'the proforma');
    }),
    'in English: the PDF and the invoice page of a paid EUR invoice; nothing for RON' => static fn () => $clean(static function () use ($setup, $client, $invoice, $pay, $number, $tax, $pdf, $expected): void {
        $setup();
        efactura_bnr_import(efactura_recent_eur_rates());
        $paid = $invoice($client('english', efactura_eur_currency()), 'Order 123');
        $proforma = $invoice($client('english', efactura_eur_currency()));
        $ron = $invoice($client('english', efactura_ron_currency()));
        $pay($paid);
        $pay($ron);
        Assert::true(str_starts_with($number($paid), 'CRK-') && str_starts_with($number($ron), 'CRK-'), 'fiscal numbers');

        $rate = Addon::bnrRates()->downloaded('EUR', Clock::today()) ?? throw new RuntimeException('no rate');
        $mention = $expected('english', $rate, $tax($paid));
        $text = $pdf($paid);
        Assert::true(str_contains($text, $mention), 'the PDF: ' . $text);
        Assert::true(str_contains($text, 'Order 123'), 'the notes of the invoice stay');
        Assert::false(str_contains($pdf($proforma), 'exchange rate'), 'the proforma');
        Assert::false(str_contains($pdf($ron), 'exchange rate'), 'an invoice in RON');

        // The invoice page of the client area.
        Assert::true(in_array(['pagetitle' => 'Invoice #' . $number($paid), 'notes' => "Order 123<br />\n" . $mention], run_hook('ClientAreaPageViewInvoice', ['invoiceid' => $paid, 'notes' => 'Order 123']), true), 'the invoice page');
        Assert::true(in_array(['pagetitle' => 'Invoice #' . $number($ron)], run_hook('ClientAreaPageViewInvoice', ['invoiceid' => $ron, 'notes' => '']), true), 'the page of an invoice in RON');
        // The line for invoicepdf.tpl adds the same mention.
        Assert::same("Order 123\n" . $mention . "\n", efactura_invoice_notes($paid, 'Order 123'));
        Assert::same('Order 123', efactura_invoice_notes($proforma, 'Order 123'));
    }),
    'an EUR invoice without VAT gets no mention, while its XML still gives the VAT in RON' => static fn () => $clean(static function () use ($setup, $client, $invoice, $pay, $number, $tax, $pdf): void {
        $setup();
        // Reported under reverse charge, and excluded for a client outside the EU.
        Settings::save(['exclude_eu_reverse_charge' => false]);
        $eur = efactura_eur_currency();
        $eu = $invoice($client('english', $eur, ['companyname' => 'Beispiel GmbH', 'tax_id' => 'DE123456789', 'country' => 'DE', 'state' => 'Berlin', 'city' => 'Berlin', 'postcode' => '10115']), 'Order 123', false);
        $nonEu = $invoice($client('english', $eur, ['companyname' => 'Example Corp', 'tax_id' => '12-3456789', 'country' => 'US', 'state' => 'NY', 'city' => 'New York', 'postcode' => '10001']), 'Order 123', false);
        $pay($eu);
        $pay($nonEu);
        Assert::same([0, 0], [$tax($eu), $tax($nonEu)], 'no VAT');
        Assert::true(Addon::documents()->forInvoice($eu)->state !== Document::STATE_EXCLUDED, 'the reverse charge invoice is reported');
        Assert::same(Document::STATE_EXCLUDED, Addon::documents()->forInvoice($nonEu)->state);

        // Not even a missing rate before the rates are downloaded.
        foreach ([$eu, $nonEu] as $id) {
            Assert::false(str_contains($pdf($id), 'exchange rate'), 'the PDF of ' . $number($id));
        }
        Assert::same([], ExchangeMention::missing(), 'nothing missing');

        efactura_bnr_import(efactura_recent_eur_rates());
        foreach ([$eu, $nonEu] as $id) {
            Assert::false(str_contains($pdf($id), 'exchange rate'), 'the PDF of ' . $number($id) . ' with the rates');
            Assert::true(in_array(['pagetitle' => 'Invoice #' . $number($id)], run_hook('ClientAreaPageViewInvoice', ['invoiceid' => $id, 'notes' => 'Order 123']), true), 'the page of ' . $number($id));
            Assert::same('Order 123', efactura_invoice_notes($id, 'Order 123'));

            // BT-6 and BT-111 are required for every document in another currency than RON.
            $result = Addon::documentBuilder()->build(Addon::documents()->forInvoice($id));
            Assert::true($result->ok(), 'not generated: ' . json_encode($result->issues, JSON_UNESCAPED_UNICODE));
            Assert::true(str_contains((string) $result->xml, '<cbc:TaxCurrencyCode>RON</cbc:TaxCurrencyCode>'), 'BT-6 of ' . $number($id));
            Assert::true(str_contains((string) $result->xml, '<cbc:TaxAmount currencyID="RON">0.00</cbc:TaxAmount>'), 'BT-111 of ' . $number($id));
        }
    }),
    'a rate saved on the document is the one of the PDF and of the XML' => static fn () => $clean(static function () use ($setup, $client, $invoice, $pay, $tax, $pdf, $expected): void {
        $setup();
        efactura_bnr_import(efactura_recent_eur_rates());
        $paid = $invoice($client('english', efactura_eur_currency()));
        $pay($paid);
        $document = Addon::documents()->forInvoice($paid);
        $saved = new ExchangeRate('EUR', '4.9876', Clock::today()->modify('-3 days'), 'BNR');
        Capsule::table(Document::TABLE)->where('id', $document->id)->update(['exchange_rate' => '4.9876', 'exchange_rate_date' => $saved->date->format('Y-m-d'), 'exchange_rate_source' => 'BNR']);

        Assert::true(str_contains($pdf($paid), $expected('english', $saved, $tax($paid))), 'the PDF');
        $result = Addon::documentBuilder()->build(Addon::documents()->find((int) $document->id));
        Assert::same('4.9876', $result->exchangeRate?->rate, 'the XML');
        Assert::true(str_contains((string) $result->xml, $expected('romanian', $saved, $tax($paid))), 'the XML note');
    }),
    'a rate not downloaded yet: the PDF goes out without it, logged once and listed on the dashboard' => static fn () => $clean(static function () use ($setup, $client, $invoice, $pay, $number, $tax, $pdf, $expected, $ratesCheck): void {
        $setup();
        $paid = $invoice($client('english', efactura_eur_currency()));
        $pay($paid);
        $check = $ratesCheck();
        Assert::same('warning', $check['status']);
        Assert::true(str_contains($check['detail'], Lang::get('check_rates_never')), 'nothing downloaded: ' . $check['detail']);

        Assert::false(str_contains($pdf($paid), 'exchange rate'), 'no mention without the rate');
        $pdf($paid);
        $logged = Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': the PDF of invoice ' . $number($paid) . ' has no BNR exchange rate%')->count();
        Assert::same(1, $logged, 'logged once');
        Assert::same($number($paid), ExchangeMention::missing()[0]['number'] ?? null);

        // The cron downloads the rates: the next PDF has the mention, the dashboard keeps the list.
        efactura_bnr_import(efactura_recent_eur_rates());
        $rate = Addon::bnrRates()->downloaded('EUR', Clock::today()) ?? throw new RuntimeException('no rate');
        Assert::true(str_contains($pdf($paid), $expected('english', $rate, $tax($paid))), 'the mention once downloaded');
        $check = $ratesCheck();
        Assert::same('warning', $check['status']);
        Assert::true(str_contains($check['detail'], $number($paid)), 'the invoice listed: ' . $check['detail']);
        Assert::false(str_contains($check['detail'], Lang::get('check_rates_never')), 'downloaded now');

        (new RuntimeState())->forget('exchange_mention_missing');
        $check = $ratesCheck();
        Assert::same('ok', $check['status']);
        Assert::same(Lang::get('check_rates_ok', Clock::parse((string) Addon::bnrRates()->status()['through'])->format('d.m.Y')), $check['detail']);
    }),
    'the cron downloads the rates once a day after BNR publishes them, and retries' => static fn () => $clean(static function () use ($setup): void {
        $setup();
        $fake = new FakeTransport();
        $rates = new BnrRates($fake);
        $days = static function (string ...$dates): string {
            $cubes = [];
            foreach ($dates as $i => $date) {
                $cubes[$date] = ['EUR' => sprintf('5.10%02d', $i)];
            }

            return efactura_bnr_xml($cubes);
        };
        $at = static function (string $time) use ($rates): ?string {
            Clock::freeze(new DateTimeImmutable($time, new DateTimeZone('Europe/Bucharest')));
            $rates->refreshIfDue();

            return $rates->status()['through'];
        };
        $week = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12'];

        // Nothing downloaded: at once, whatever the hour. Tuesday's rates are not out at 10:00.
        $fake->queue(new Response(200, [], $days(...$week)));
        Assert::same('2026-10-12', $at('2026-10-13 10:00'));
        Assert::same('2026-10-12', $at('2026-10-13 11:00'));
        Assert::same(1, count($fake->requests), 'Monday is complete until BNR publishes Tuesday');
        // After 13:15, until BNR publishes, every half hour.
        $fake->queue(new Response(200, [], $days(...$week)));
        Assert::same('2026-10-12', $at('2026-10-13 13:20'));
        Assert::same('2026-10-12', $at('2026-10-13 13:40'));
        Assert::same(2, count($fake->requests), 'not again within half an hour');
        $fake->queue(new Response(200, [], $days(...[...$week, '2026-10-13'])));
        Assert::same('2026-10-13', $at('2026-10-13 13:51'));
        Assert::same('2026-10-13', $at('2026-10-13 18:00'));
        Assert::same(3, count($fake->requests), 'done for the day');
        // A cron stopped since Tuesday: at once on Saturday; no rates on a Saturday.
        $fake->queue(new Response(200, [], $days('2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16')));
        Assert::same('2026-10-17', $at('2026-10-17 09:00'));
        $fake->queue(new Response(200, [], $days('2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16')));
        Assert::same('2026-10-18', $at('2026-10-18 14:00'));
        Assert::same(5, count($fake->requests));

        // A failure is reported once, then only recorded until a download works.
        $fake->queue(new Response(503, [], ''));
        Assert::throws(RuntimeException::class, static fn () => $at('2026-10-19 13:20'));
        $fake->queue(new Response(503, [], ''));
        Assert::same('2026-10-18', $at('2026-10-19 13:55'));
        Assert::true(str_contains((string) $rates->status()['error'], 'HTTP 503'), 'the error for the dashboard');
        $fake->queue(new Response(200, [], $days('2026-10-15', '2026-10-16', '2026-10-19')));
        Assert::same('2026-10-19', $at('2026-10-19 14:30'));
        Assert::same(null, $rates->status()['error']);
    }),
    'a later rate missing from the cache is downloaded, never replaced by an older one' => static fn () => $clean(static function () use ($setup): void {
        $setup();
        // Downloaded on Friday morning: the rates up to Thursday.
        Clock::freeze(new DateTimeImmutable('2026-10-09 09:00', new DateTimeZone('Europe/Bucharest')));
        efactura_bnr_import(['2026-10-07' => ['EUR' => '5.0707'], '2026-10-08' => ['EUR' => '5.0808']]);
        Assert::same('5.0808', Addon::bnrRates()->downloaded('EUR', new DateTimeImmutable('2026-10-09'))?->rate, 'Friday: Thursday');

        // Monday: Friday's rate is not cached, so the cache cannot answer.
        Clock::freeze(new DateTimeImmutable('2026-10-12 10:00', new DateTimeZone('Europe/Bucharest')));
        Assert::same(null, Addon::bnrRates()->downloaded('EUR', new DateTimeImmutable('2026-10-12')));
        $fake = (new FakeTransport())->queue(new Response(200, [], efactura_bnr_xml(['2026-10-08' => ['EUR' => '5.0808'], '2026-10-09' => ['EUR' => '5.0909']])));
        Assert::same('5.0909', (new BnrRates($fake))->forDocumentDate('EUR', new DateTimeImmutable('2026-10-12'))->rate, 'the XML downloads it');
        Assert::same('5.0909', Addon::bnrRates()->downloaded('EUR', new DateTimeImmutable('2026-10-12'))?->rate, 'then the PDF has it');
        // Rates stored without a download that covers them are not trusted.
        Capsule::table(BnrRates::TABLE)->insertOrIgnore(['source' => 'BNR', 'currency' => 'USD', 'rate_date' => '2026-10-09', 'value' => '4.5123', 'multiplier' => 1, 'fetched_at' => date('Y-m-d H:i:s')]);
        Assert::same('4.5123', Addon::bnrRates()->downloaded('USD', new DateTimeImmutable('2026-10-12'))?->rate, 'covered by the download of Monday');
        Assert::same(null, Addon::bnrRates()->downloaded('USD', new DateTimeImmutable('2026-10-15')), 'after the covered days');
    }),
];
