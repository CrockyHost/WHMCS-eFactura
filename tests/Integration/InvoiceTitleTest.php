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
use WHMCS\Module\Addon\Efactura\Admin\HealthChecks;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoiceTitle;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/*
 * WHMCS titles every invoice that is not Paid as a proforma; one with a
 * fiscal invoice gets the title of a paid one, in the PDF and on the invoice
 * page (fictive seller and clients, rolled back).
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
};

/**
 * A proforma of a fictive individual who reads invoices in $language.
 */
$proforma = static function (string $language): int {
    $clientId = (int) localAPI('AddClient', [
        'firstname' => 'Ana', 'lastname' => 'Titlu', 'companyname' => '', 'tax_id' => '', 'language' => $language,
        'email' => 'efactura-title-' . uniqid() . '@example.invalid', 'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj',
        'postcode' => '200000', 'country' => 'RO', 'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)),
        'currency' => efactura_ron_currency(), 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];

    return (int) localAPI('CreateInvoice', ['userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'itemdescription1' => 'Găzduire', 'itemamount1' => 100, 'itemtaxed1' => true])['invoiceid'];
};

$number = static fn (int $invoiceId): string => (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('invoicenum');

$titleCheck = static function (): array {
    foreach ((new HealthChecks(new InvoicingConfig()))->all() as $check) {
        if ($check['label'] === Lang::get('check_invoice_title')) {
            return $check;
        }
    }
    throw new RuntimeException('invoice title check missing');
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
    'in Romanian: "Factura nr." for an invoice issued before payment, the proforma keeps its title' => static fn () => $clean(static function () use ($setup, $proforma, $number): void {
        $setup();
        $early = $proforma('romanian');
        $kept = $proforma('romanian');
        Addon::earlyIssue()->issue($early, 1, 1);
        Assert::same('Unpaid', (string) Capsule::table('tblinvoices')->where('id', $early)->value('status'));

        $pdf = efactura_pdf_text($early);
        Assert::true(str_contains($pdf, 'Factura nr. ' . $number($early)), 'the title of a paid invoice: ' . strtok($pdf, "\n"));
        $pdf = efactura_pdf_text($kept);
        Assert::true(str_starts_with($number($kept), 'CRP-') && str_contains($pdf, $number($kept)), 'the proforma number');
        Assert::false(str_contains($pdf, 'Factura nr.'), 'the proforma title: ' . strtok($pdf, "\n"));
    }),
    'an invoice issued before payment has the title of a paid invoice; a proforma keeps its own' => static fn () => $clean(static function () use ($setup, $proforma, $number): void {
        $setup();
        $early = $proforma('english');
        $kept = $proforma('english');
        Assert::true(str_contains(efactura_pdf_text($early), 'Proforma Invoice #' . $number($early)), 'a proforma before the issue');
        Addon::earlyIssue()->issue($early, 1, 1);
        Assert::true(str_starts_with($number($early), 'CRK-'), 'the fiscal number');

        $pdf = efactura_pdf_text($early);
        Assert::true(str_contains($pdf, 'Invoice #' . $number($early)) && !str_contains($pdf, 'Proforma'), 'the PDF: ' . strtok($pdf, "\n"));
        Assert::true(str_contains(efactura_pdf_text($kept), 'Proforma Invoice #' . $number($kept)), 'the other proforma');

        // The invoice page of the client area (lib/Hooks registers the same title).
        Assert::true(in_array(['pagetitle' => 'Invoice #' . $number($early)], run_hook('ClientAreaPageViewInvoice', ['invoiceid' => $early]), true), 'the invoice page');
        Assert::false(in_array(['pagetitle' => 'Invoice #' . $number($kept)], run_hook('ClientAreaPageViewInvoice', ['invoiceid' => $kept]), true), 'the proforma page');

        // The fiscal invoices stay when the processing is turned off.
        Settings::save(['enabled' => false]);
        $pdf = efactura_pdf_text($early);
        Assert::true(str_contains($pdf, 'Invoice #' . $number($early)) && !str_contains($pdf, 'Proforma'), 'processing off');
    }),
    'a fiscal invoice cancelled or refunded keeps the title of an invoice' => static fn () => $clean(static function () use ($setup, $proforma, $number): void {
        $setup();
        $paid = $proforma('english');
        localAPI('AddInvoicePayment', ['invoiceid' => $paid, 'transid' => 'TT-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => 121.00]);
        Assert::true(str_starts_with($number($paid), 'CRK-'), 'paid: fiscal number');
        // Only the status the title depends on (a real refund issues a storno).
        foreach (['Refunded', 'Cancelled'] as $status) {
            Capsule::table('tblinvoices')->where('id', $paid)->update(['status' => $status]);
            $pdf = efactura_pdf_text($paid);
            Assert::true(str_contains($pdf, 'Invoice #' . $number($paid)) && !str_contains($pdf, 'Proforma'), $status . ': ' . strtok($pdf, "\n"));
        }
        // A number edited by hand is not the fiscal number: WHMCS decides.
        Capsule::table('tblinvoices')->where('id', $paid)->update(['invoicenum' => 'ALT-1']);
        Assert::same(null, InvoiceTitle::fiscal($paid));
    }),
    'the dashboard confirms the PDF title on each WHMCS version, or asks for the line in the theme' => static fn () => $clean(static function () use ($setup, $proforma, $number, $titleCheck): void {
        $setup();
        $state = new RuntimeState();
        $state->forget('pdf_title_whmcs');
        if (InvoiceTitle::templateHasLine()) {
            throw new SkipTest('the theme already has the line: ' . InvoiceTitle::templateFile());
        }
        $check = $titleCheck();
        Assert::same('warning', $check['status']);
        Assert::same(InvoiceTitle::TEMPLATE_LINE, $check['steps'][0]['code'] ?? null);
        Assert::true(str_contains($check['steps'][0]['text'], InvoiceTitle::templateFile()), 'the theme file');

        efactura_pdf_text($proforma('english'));
        Assert::same('ok', $titleCheck()['status'], 'any invoice PDF confirms it');
        $state->set('pdf_title_whmcs', '8.13.1-release.1');
        Assert::same('warning', $titleCheck()['status'], 'after a WHMCS update');

        $file = tempnam(sys_get_temp_dir(), 'eft');
        file_put_contents($file, "<?php\n" . InvoiceTitle::TEMPLATE_LINE . "\n\n# Logo\n");
        Assert::true(InvoiceTitle::templateHasLine($file), 'the line');
        file_put_contents($file, "<?php\n\n# Logo\n");
        Assert::false(InvoiceTitle::templateHasLine($file), 'without the line');
        @unlink($file);
        Assert::false(InvoiceTitle::templateHasLine($file), 'no file');
    }),
    'the line for invoicepdf.tpl sets the same title and leaves a proforma alone' => static fn () => $clean(static function () use ($setup, $proforma, $number): void {
        $setup();
        $early = $proforma('english');
        $kept = $proforma('english');
        Addon::earlyIssue()->issue($early, 1, 1);
        // The template is PHP that WHMCS runs with the variables of the PDF.
        $template = static function (int $invoiceid, string $pagetitle): string {
            eval(InvoiceTitle::TEMPLATE_LINE);

            return $pagetitle;
        };
        Assert::same(\Lang::trans('invoicenumber') . $number($early), $template($early, 'Proforma Invoice #' . $number($early)));
        Assert::same('Proforma Invoice #' . $number($kept), $template($kept, 'Proforma Invoice #' . $number($kept)));
        Assert::same('Proforma Invoice #X', efactura_invoice_title('not a number', 'Proforma Invoice #X'));
    }),
];
