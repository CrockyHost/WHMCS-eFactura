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
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Numbering\EarlyIssueException;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Numbering\SeriesCounter;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/*
 * Payments through the real WHMCS functions, with the addon hooks active,
 * in the rolled-back test transaction. Requires the WHMCS proforma mode
 * (CRP proformas, CRK fiscal series) configured in the development install.
 */

require_once ROOTDIR . '/includes/invoicefunctions.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';

$api = static function (string $command, array $params): array {
    $result = localAPI($command, $params);
    if (($result['result'] ?? '') !== 'success') {
        throw new RuntimeException("{$command}: " . ($result['message'] ?? json_encode($result)));
    }

    return $result;
};

/**
 * Enables processing and returns a fictive client in Romania.
 */
$client = static function (array $overrides = []) use ($api): int {
    Settings::save(['enabled' => true, 'exclude_add_funds' => true, 'exclude_zero_total' => true,
        'exclude_eu_reverse_charge' => true, 'exclude_non_eu' => true, 'send_delay_days' => 1,
        'early_issue_groups' => [], 'early_issue_clients' => []]);
    NumberingLock::overridePaymentTimeout(1);

    return (int) $api('AddClient', $overrides + [
        'firstname' => 'Test', 'lastname' => 'eFactura', 'email' => 'efactura-test-' . uniqid() . '@example.invalid',
        'address1' => 'Strada Test 1', 'city' => 'Craiova', 'state' => 'Dolj', 'postcode' => '200000', 'country' => 'RO',
        'phonenumber' => '0700000000', 'password2' => bin2hex(random_bytes(8)), 'currency' => 2, 'noemail' => true, 'skipvalidation' => true,
    ])['clientid'];
};

$invoice = static function (int $clientId, float $amount, bool $taxed = true, string $description = 'Test hosting') use ($api): int {
    return (int) $api('CreateInvoice', [
        'userid' => $clientId, 'status' => 'Unpaid', 'sendinvoice' => false, 'paymentmethod' => 'banktransfer',
        'date' => date('Y-m-d', strtotime('-3 days')), 'duedate' => date('Y-m-d'),
        'itemdescription1' => $description, 'itemamount1' => $amount, 'itemtaxed1' => $taxed,
    ])['invoiceid'];
};

$pay = static function (int $invoiceId, ?float $amount = null) use ($api): void {
    $total = (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
    $api('AddInvoicePayment', ['invoiceid' => $invoiceId, 'transid' => 'T-' . uniqid(), 'gateway' => 'banktransfer', 'amount' => $amount ?? $total]);
};

$row = static fn (int $invoiceId): object => Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['invoicenum', 'date', 'status']);
$doc = static fn (int $invoiceId): ?object => Addon::documents()->forInvoice($invoiceId);
$counter = static fn (): string => (new SeriesCounter(new InvoicingConfig()))->value();
$number = static fn (string $counter): string => InvoicingConfig::format((new InvoicingConfig())->fiscalFormat(), $counter);
$plus = static fn (string $counter, int $n): string => str_pad((string) ((int) $counter + $n), strlen($counter), '0', STR_PAD_LEFT);

/**
 * Runs a test body and always leaves the lock released.
 */
$clean = static function (callable $body): void {
    try {
        $body();
    } finally {
        NumberingLock::release();
        NumberingLock::overridePaymentTimeout(null);
        Clock::freeze(null);
    }
};

return [
    'an API payment records the fiscal invoice WHMCS numbered' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number, $plus): void {
        $id = $invoice($client(), 100);
        $proforma = $row($id)->invoicenum;
        $start = $counter();
        $pay($id);

        $invoiceRow = $row($id);
        Assert::same($number($start), $invoiceRow->invoicenum);
        Assert::same($plus($start, 1), $counter());
        $document = $doc($id);
        Assert::same($number($start), $document->number);
        Assert::same($proforma, $document->proforma_number);
        Assert::same(Document::SOURCE_PAYMENT, $document->source);
        Assert::same(Document::STATE_SCHEDULED, $document->state);
        Assert::same($invoiceRow->date, $document->issue_date);
        Assert::same(WorkingDays::legalDeadline(Clock::parse($invoiceRow->date))->format('Y-m-d'), $document->deadline_date);
        Assert::same('RON', $document->currency);
        Assert::false(NumberingLock::held(), 'the lock must be released after the payment');
    }),
    'a gateway callback payment is handled the same way' => static fn () => $clean(static function () use ($client, $invoice, $row, $doc, $counter, $number): void {
        $id = $invoice($client(), 100);
        $start = $counter();
        $id = checkCbInvoiceID($id, 'banktransfer');
        checkCbTransID('CB-' . uniqid());
        addInvoicePayment($id, 'CB-' . uniqid(), 121.00, 0, 'banktransfer');
        Assert::same($number($start), $row($id)->invoicenum);
        Assert::same($number($start), $doc($id)->number);
    }),
    'a partial payment does not number the invoice, the final one does' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number): void {
        $id = $invoice($client(), 100);
        $proforma = $row($id)->invoicenum;
        $start = $counter();
        $pay($id, 50);
        Assert::same($proforma, $row($id)->invoicenum);
        Assert::same(null, $doc($id));
        Assert::false(NumberingLock::held(), 'a partial payment must not keep the lock');
        $pay($id, 71);
        Assert::same($number($start), $doc($id)->number);
        Assert::same($proforma, $doc($id)->proforma_number);
    }),
    'a payment with credit (no AddInvoicePayment hook) is recorded' => static fn () => $clean(static function () use ($api, $client, $invoice, $row, $doc, $counter, $number): void {
        $clientId = $client();
        $id = $invoice($clientId, 100);
        $start = $counter();
        $api('AddCredit', ['clientid' => $clientId, 'description' => 'test credit', 'amount' => 500]);
        $api('ApplyCredit', ['invoiceid' => $id, 'amount' => 121.00]);
        Assert::same('Paid', $row($id)->status);
        Assert::same($number($start), $doc($id)->number);
        Assert::false(NumberingLock::held());
    }),
    'a Mass Pay container gets no fiscal number, the invoices it pays get consecutive ones' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number, $plus): void {
        $clientId = $client();
        $a = $invoice($clientId, 100, true, 'Child A');
        $b = $invoice($clientId, 10, true, 'Child B');
        $container = $invoice($clientId, 1, false, 'Mass pay');
        $containerProforma = $row($container)->invoicenum;
        Capsule::table('tblinvoiceitems')->where('invoiceid', $container)->delete();
        foreach ([$a => 121.00, $b => 12.10] as $child => $amount) {
            Capsule::table('tblinvoiceitems')->insert(['invoiceid' => $container, 'userid' => $clientId, 'type' => 'Invoice', 'relid' => $child,
                'description' => 'Invoice #' . $child, 'amount' => $amount, 'taxed' => 0, 'duedate' => date('Y-m-d'), 'paymentmethod' => 'banktransfer']);
        }
        Capsule::table('tblinvoices')->where('id', $container)->update(['subtotal' => 133.10, 'tax' => 0, 'total' => 133.10]);
        $start = $counter();

        $pay($container);

        Assert::same($containerProforma, $row($container)->invoicenum);
        Assert::same(null, $doc($container));
        Assert::same($number($start), $row($a)->invoicenum);
        Assert::same($number($plus($start, 1)), $row($b)->invoicenum);
        Assert::same($number($start), $doc($a)->number);
        Assert::same($number($plus($start, 1)), $doc($b)->number);
        Assert::same($plus($start, 2), $counter());
        Assert::false(NumberingLock::held());
    }),
    'an excluded Add Funds invoice gives its number back' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter): void {
        $id = $invoice($client(), 50, false, 'Add Funds');
        Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->update(['type' => 'AddFunds']);
        $proforma = $row($id)->invoicenum;
        $start = $counter();
        $pay($id);
        Assert::same('Paid', $row($id)->status);
        Assert::same($proforma, $row($id)->invoicenum);
        Assert::same(null, $doc($id));
        Assert::same($start, $counter());
    }),
    'an Add Funds invoice is a fiscal invoice when the setting includes it' => static fn () => $clean(static function () use ($client, $invoice, $pay, $doc, $counter, $number): void {
        $clientId = $client();
        Settings::save(['exclude_add_funds' => false]);
        $id = $invoice($clientId, 50, false, 'Add Funds');
        Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->update(['type' => 'AddFunds']);
        $start = $counter();
        $pay($id);
        Assert::same($number($start), $doc($id)->number);
    }),
    'a zero-total order invoice marked paid by WHMCS gives its number back' => static fn () => $clean(static function () use ($client, $row, $doc, $counter): void {
        $clientId = $client();
        $start = $counter();
        // Product 67 has no server module and no automatic setup. WHMCS 9.0.5
        // reports an error after marking the free invoice paid; it is ignored.
        localAPI('AddOrder', ['clientid' => $clientId, 'pid' => [67], 'billingcycle' => ['onetime'], 'priceoverride' => [0],
            'paymentmethod' => 'banktransfer', 'noemail' => true]);
        $id = (int) Capsule::table('tblinvoices')->where('userid', $clientId)->value('id');
        Assert::same('Paid', $row($id)->status);
        Assert::same(null, (new SeriesCounter(new InvoicingConfig()))->counterOf($row($id)->invoicenum));
        Assert::same(null, $doc($id));
        Assert::same($start, $counter());
    }),
    'an early-issued invoice keeps its number and date when paid' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number, $plus): void {
        $id = $invoice($client(), 100);
        $proforma = $row($id)->invoicenum;
        $start = $counter();
        Clock::freeze(new DateTimeImmutable('-2 days'));
        $issued = Addon::earlyIssue()->issue($id, 1, NumberingLock::ADMIN_TIMEOUT);
        $issueDate = Clock::today()->format('Y-m-d');
        Clock::freeze(null);

        Assert::same($number($start), $issued);
        Assert::same($issued, $row($id)->invoicenum);
        Assert::same($issueDate, $row($id)->date);
        Assert::same($plus($start, 1), $counter());
        Assert::same(Document::SOURCE_EARLY, $doc($id)->source);
        Assert::same($proforma, $doc($id)->proforma_number);
        Assert::same(1, (int) $doc($id)->issued_by);

        $pay($id);

        Assert::same('Paid', $row($id)->status);
        Assert::same($issued, $row($id)->invoicenum);
        Assert::same($issueDate, $row($id)->date);
        Assert::same($plus($start, 1), $counter(), 'the number WHMCS assigned at payment must go back to the counter');
        Assert::same(null, $doc($id)->review_reason);
    }),
    'early issue applies to the client groups chosen in the settings' => static fn () => $clean(static function () use ($client, $invoice, $row, $doc, $counter, $number): void {
        $groupId = (int) Capsule::table('tblclientgroups')->insertGetId(['groupname' => 'eFactura test group', 'groupcolour' => '', 'discountpercent' => 0, 'susptermexempt' => '', 'separateinvoices' => '']);
        $clientId = $client(['groupid' => $groupId]);
        Settings::save(['early_issue_groups' => [$groupId]]);
        $start = $counter();
        $id = $invoice($clientId, 100);
        Assert::same('Unpaid', $row($id)->status);
        Assert::same($number($start), $row($id)->invoicenum);
        Assert::same(Document::SOURCE_EARLY, $doc($id)->source);
    }),
    'early issue refuses invoices that cannot be issued' => static fn () => $clean(static function () use ($client, $invoice, $pay): void {
        $clientId = $client();
        $paid = $invoice($clientId, 100);
        $pay($paid);
        $issue = static function (int $id): string {
            try {
                Addon::earlyIssue()->issue($id, 1, 1);
            } catch (EarlyIssueException $e) {
                return $e->reason;
            }

            return 'issued';
        };
        Assert::same(EarlyIssueException::NOT_UNPAID, $issue($paid));
        $unpaid = $invoice($clientId, 100);
        Assert::same('issued', $issue($unpaid));
        Assert::same(EarlyIssueException::ALREADY_FISCAL, $issue($unpaid));
        $funds = $invoice($clientId, 50, false);
        Capsule::table('tblinvoiceitems')->where('invoiceid', $funds)->update(['type' => 'AddFunds']);
        Assert::same(EarlyIssueException::NOT_FISCAL, $issue($funds));
        Settings::save(['enabled' => false]);
        Assert::same(EarlyIssueException::DISABLED, $issue($invoice($clientId, 100)));
    }),
    'invoices to excluded clients keep their fiscal number but are not scheduled' => static fn () => $clean(static function () use ($client, $invoice, $pay, $doc, $number, $counter, $plus): void {
        $start = $counter();
        $eu = $invoice($client(['country' => 'DE', 'state' => 'Berlin', 'city' => 'Berlin', 'tax_id' => 'DE123456789']), 100, false);
        $pay($eu);
        $nonEu = $invoice($client(['country' => 'US', 'state' => 'NY', 'city' => 'New York']), 100, false);
        $pay($nonEu);
        Assert::same($number($start), $doc($eu)->number);
        Assert::same(Document::STATE_EXCLUDED, $doc($eu)->state);
        Assert::same(Document::EXCLUDED_EU_REVERSE_CHARGE, $doc($eu)->exclusion_reason);
        Assert::same($number($plus($start, 1)), $doc($nonEu)->number);
        Assert::same(Document::EXCLUDED_NON_EU, $doc($nonEu)->exclusion_reason);
        Assert::same(null, $doc($nonEu)->send_after);
    }),
    'a payment that cannot get the lock goes through and is held for a manual check' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number): void {
        $clientId = $client();
        // A normal payment first, as a baseline for the duration.
        $started = microtime(true);
        $pay($invoice($clientId, 100));
        $baseline = microtime(true) - $started;

        $id = $invoice($clientId, 100);
        $start = $counter();
        // Another connection holds the numbering lock for the whole payment.
        $config = Capsule::connection()->getConfig();
        $other = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'] ?? 3306, $config['database']), $config['username'], $config['password']);
        $other->query("SELECT GET_LOCK('" . Lock::scoped('numbering') . "', 0)")->fetchAll();
        try {
            $started = microtime(true);
            $pay($id);
            $extra = (microtime(true) - $started) - $baseline;
            // One lock timeout (1 s here), plus the alert e-mail.
            Assert::true($extra < NumberingLock::paymentTimeout() + 3, sprintf('the payment waited %.1f s longer than usual', $extra));
        } finally {
            $other = null;
        }
        Assert::same('Paid', $row($id)->status);
        Assert::same($number($start), $row($id)->invoicenum);
        Assert::same(Document::STATE_HELD, $doc($id)->state);
        Assert::same(Document::REVIEW_LOCK_TIMEOUT, $doc($id)->review_reason);
        Assert::true(Capsule::table('tblactivitylog')->where('description', 'like', Addon::NAME . ': %' . $number($start) . '%')->exists(), 'an alert must be logged');
    }),
    'a duplicate number given by WHMCS is replaced with the next free one' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number, $plus): void {
        $clientId = $client();
        $a = $invoice($clientId, 100);
        $b = $invoice($clientId, 100);
        $start = $counter();
        $pay($a);
        // Simulates a race: the counter goes back to a number already used.
        (new SeriesCounter(new InvoicingConfig()))->set($start);
        $pay($b);
        Assert::same($number($start), $row($a)->invoicenum);
        Assert::same($number($plus($start, 1)), $row($b)->invoicenum);
        Assert::same($number($plus($start, 1)), $doc($b)->number);
        Assert::same($plus($start, 2), $counter());
    }),
    'an invoice paid again after being set back to unpaid keeps its fiscal number' => static fn () => $clean(static function () use ($api, $client, $invoice, $pay, $row, $counter, $number, $plus): void {
        $id = $invoice($client(), 100);
        $start = $counter();
        $pay($id);
        $api('UpdateInvoice', ['invoiceid' => $id, 'status' => 'Unpaid']);
        $pay($id);
        Assert::same($number($start), $row($id)->invoicenum);
        Assert::same($plus($start, 1), $counter());
    }),
    'nothing happens while processing is disabled' => static fn () => $clean(static function () use ($client, $invoice, $pay, $row, $doc, $counter, $number): void {
        $clientId = $client();
        Settings::save(['enabled' => false]);
        $id = $invoice($clientId, 50, false);
        Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->update(['type' => 'AddFunds']);
        $start = $counter();
        $pay($id);
        Assert::same($number($start), $row($id)->invoicenum, 'WHMCS numbering is left as it is');
        Assert::same(null, $doc($id));
    }),
];
