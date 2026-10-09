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

namespace WHMCS\Module\Addon\Efactura\Numbering;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Fiscal\ReportingPolicy;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Audit;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Keeps the fiscal series consistent when WHMCS marks an invoice paid.
 *
 * In proforma mode WHMCS gives every invoice that becomes Paid the next
 * number of the fiscal series and the payment date, between the
 * AddInvoicePayment and InvoicePaidPreEmail hooks (verified in 9.0.5; the
 * credit and zero-total paths skip AddInvoicePayment). The numbering lock is
 * taken at AddInvoicePayment, or at InvoicePaidPreEmail when that was
 * skipped, and in InvoicePaidPreEmail the addon:
 * - restores the number and date of an invoice that already had a fiscal
 *   number (issued early, or paid before) and gives the new one back;
 * - withdraws the number from invoices that are not fiscal (Mass Pay
 *   container, excluded Add Funds and zero-total invoices) and gives it back;
 * - otherwise records the fiscal invoice, replacing a duplicate number.
 *
 * Payments never wait more than NumberingLock::PAYMENT_TIMEOUT seconds:
 * without the lock the invoice is still recorded, but held for a manual
 * check, and the admins are alerted.
 */
final class PaymentNumbering
{
    /** @var array<int, string> invoice number before the payment, by invoice ID */
    private static array $previousNumbers = [];

    /** @var array<int, true> invoices whose payment ran without the lock */
    private static array $lockMissed = [];

    public function __construct(
        private readonly FiscalNumbering $numbering,
        private readonly DocumentRepository $documents,
    ) {
    }

    /**
     * AddInvoicePayment: the payment is recorded, the invoice is not numbered yet.
     */
    public function beforePayment(int $invoiceId): void
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['invoicenum', 'status', 'total', 'credit']);
        if ($invoice === null || $invoice->status === 'Paid') {
            return;
        }
        self::$previousNumbers[$invoiceId] = (string) $invoice->invoicenum;
        if (self::balance($invoiceId, $invoice) > 0.0) {
            // Partial payment: WHMCS does not number the invoice now.
            return;
        }
        if (!NumberingLock::acquire($invoiceId, NumberingLock::paymentTimeout())) {
            self::$lockMissed[$invoiceId] = true;
        }
    }

    /**
     * InvoicePaidPreEmail: WHMCS has set the status, the number and the date.
     */
    public function afterNumbering(int $invoiceId): void
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'invoicenum', 'date', 'status']);
        if ($invoice === null || $invoice->status !== 'Paid') {
            return;
        }
        // Wait for the lock only when AddInvoicePayment did not already try
        // (payments with credit, zero-total invoices), so that a payment
        // waits at most one timeout.
        $locked = !isset(self::$lockMissed[$invoiceId]);
        if ($locked && !NumberingLock::held() && !NumberingLock::acquire($invoiceId, NumberingLock::paymentTimeout())) {
            $locked = false;
        }

        try {
            Capsule::connection()->transaction(function () use ($invoice, $locked): void {
                $this->settle($invoice, $locked);
            });
        } finally {
            unset(self::$lockMissed[$invoiceId], self::$previousNumbers[$invoiceId]);
            // A Mass Pay container keeps the lock until InvoicePaid: WHMCS
            // numbers the invoices it pays in between.
            if (NumberingLock::owner() === $invoiceId && ReportingPolicy::nonFiscalReason($invoiceId) !== ReportingPolicy::NOT_FISCAL_MASS_PAY) {
                NumberingLock::release();
            }
        }
    }

    /**
     * InvoicePaid: the end of the payment of $invoiceId.
     */
    public function afterPayment(int $invoiceId): void
    {
        NumberingLock::releaseIfOwner($invoiceId);
    }

    private function settle(object $invoice, bool $locked): void
    {
        $invoiceId = (int) $invoice->id;

        $document = $this->documents->forInvoice($invoiceId);
        if ($document !== null) {
            $this->restore($invoice, $document, $locked);

            return;
        }

        $nonFiscal = ReportingPolicy::nonFiscalReason($invoiceId);
        if ($nonFiscal !== null) {
            $this->withdraw($invoice, $nonFiscal, $locked);

            return;
        }

        $this->register($invoice, $locked);
    }

    /**
     * The invoice already has a fiscal number: put it and its issue date back.
     */
    private function restore(object $invoice, object $document, bool $locked): void
    {
        $invoiceId = (int) $invoice->id;
        $assigned = (string) $invoice->invoicenum;
        if ($assigned === (string) $document->number && (string) $invoice->date === (string) $document->issue_date) {
            return;
        }

        Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
            'invoicenum' => $document->number,
            'date' => $document->issue_date,
        ]);
        Audit::log('number_restored', $document->number, [
            'whmcs_number' => $assigned,
            'whmcs_date' => (string) $invoice->date,
            'issue_date' => (string) $document->issue_date,
        ], (int) $document->id, $invoiceId);

        if ($assigned !== '' && $assigned !== (string) $document->number && !$this->giveBack($assigned, $locked)) {
            $this->documents->flagForReview((int) $document->id, Document::REVIEW_COUNTER_GAP);
            $this->alertGap($invoiceId, $assigned);
        }
    }

    /**
     * The invoice is not a fiscal document: it must not keep a fiscal number.
     */
    private function withdraw(object $invoice, string $reason, bool $locked): void
    {
        $invoiceId = (int) $invoice->id;
        $assigned = (string) $invoice->invoicenum;
        if ($this->numbering->counter()->counterOf($assigned) === null) {
            return;
        }

        $previous = self::$previousNumbers[$invoiceId] ?? '';
        if ($this->numbering->counter()->counterOf($previous) !== null) {
            $previous = '';
        }
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['invoicenum' => $previous]);
        Audit::log('number_withdrawn', $assigned, ['reason' => $reason, 'restored_number' => $previous], null, $invoiceId);

        if (!$this->giveBack($assigned, $locked)) {
            $this->alertGap($invoiceId, $assigned);
        }
    }

    /**
     * A new fiscal invoice: record it, after making sure its number is unique.
     */
    private function register(object $invoice, bool $locked): void
    {
        $invoiceId = (int) $invoice->id;
        $number = (string) $invoice->invoicenum;
        $review = $locked ? null : Document::REVIEW_LOCK_TIMEOUT;

        $fromSeries = $this->numbering->counter()->counterOf($number) !== null;
        if (!$fromSeries || $this->numbering->inUse($number, $invoiceId)) {
            if ($locked) {
                $replacement = $this->numbering->allocate(Clock::parse((string) $invoice->date));
                Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['invoicenum' => $replacement]);
                Audit::log('number_replaced', $replacement, ['whmcs_number' => $number], null, $invoiceId);
                if ($fromSeries) {
                    AdminNotifier::send(
                        Lang::get('alert_duplicate_subject', $number),
                        Lang::get('alert_duplicate_body', $invoiceId, $number, $replacement, self::invoiceLink($invoiceId))
                    );
                }
                $number = $replacement;
            } else {
                $review = $fromSeries ? Document::REVIEW_DUPLICATE_NUMBER : Document::REVIEW_LOCK_TIMEOUT;
            }
        }

        $previous = self::$previousNumbers[$invoiceId] ?? null;
        $this->documents->createInvoiceDocument($invoiceId, [
            'number' => $number,
            'proforma_number' => $previous !== null && $previous !== $number ? $previous : null,
            'source' => Document::SOURCE_PAYMENT,
            'issue_date' => Clock::parse((string) $invoice->date),
            'review_reason' => $review,
        ]);

        if ($review !== null) {
            AdminNotifier::send(
                Lang::get('alert_review_subject', $number),
                Lang::get('alert_review_body', $invoiceId, $number, Lang::get('review_' . $review), self::invoiceLink($invoiceId))
            );
        }
    }

    private function giveBack(string $number, bool $locked): bool
    {
        return $locked && $this->numbering->giveBack($number);
    }

    private function alertGap(int $invoiceId, string $number): void
    {
        Audit::log('number_gap', $number, [], null, $invoiceId);
        AdminNotifier::send(
            Lang::get('alert_gap_subject', $number),
            Lang::get('alert_gap_body', $number, $invoiceId, self::invoiceLink($invoiceId))
        );
    }

    /**
     * What is left to pay on the invoice after the payments recorded so far.
     */
    private static function balance(int $invoiceId, object $invoice): float
    {
        $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)
            ->sum(Capsule::raw('amountin - amountout'));

        return round((float) $invoice->total - (float) $invoice->credit - $paid, 2);
    }

    private static function invoiceLink(int $invoiceId): string
    {
        return AdminContext::adminUrl('invoices.php?action=edit&id=' . $invoiceId);
    }
}
