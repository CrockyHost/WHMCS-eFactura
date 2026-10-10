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

namespace WHMCS\Module\Addon\Efactura\Fiscal;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Numbering\FiscalNumbering;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;

/**
 * Issues the stornos of fiscal invoices, in the fiscal series, as invoices
 * (380) with negative quantities that point to the original (BT-25/26).
 *
 * - Cancelling a fiscal invoice (InvoiceCancelled) issues a storno of the
 *   whole invoice, or of what is left after partial refunds. Cancelling a
 *   proforma does nothing.
 * - A refund issues a storno from the WHMCS credit note created with it:
 *   the invoice lines negated when the whole invoice is refunded at once,
 *   otherwise one line "Stornare parțială factura CRK-xxxx" with the net and
 *   VAT of the credit note. Credit notes not created by a refund (applied
 *   credit, remaining balance, cancelled proformas) are ignored.
 * - A Mass Pay invoice is not fiscal; the invoices it paid are. Refunding it
 *   in full at once reverses each of them in full, unless it paid only the
 *   rest of an invoice paid in part before; a partial refund is not split
 *   automatically: the admin is alerted and stornos each invoice by hand.
 *
 * The request is recorded first and the storno is issued under the
 * numbering lock: at once when possible, otherwise from the cron. WHMCS
 * creates the credit note of a partial refund after the last hook of the
 * refund, so that one is issued when the request ends.
 */
final class Stornos
{
    public const CANCEL = 'cancel';
    public const REFUND = 'refund';
    public const MASS_PAY = 'masspay';
    private const PENDING = 'storno:';
    /** A request still waiting after this is reported. */
    private const ALERT_AFTER_MINUTES = 30;
    /** A refund whose credit note never shows up is dropped after this. */
    private const GIVE_UP_DAYS = 7;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly FiscalNumbering $numbering,
        private readonly DocumentRepository $documents,
        private readonly RuntimeState $state,
        private readonly ?DocumentBuilder $builder = null,
    ) {
    }

    /**
     * InvoiceCancelled: a storno, when the invoice is a fiscal invoice.
     */
    public function requestCancel(int $invoiceId, ?int $adminId = null): bool
    {
        return $this->original($invoiceId) !== null && $this->record(self::CANCEL, $invoiceId, $adminId);
    }

    /**
     * A refund of a payment of the invoice: a storno, when the invoice is a
     * fiscal invoice, as soon as WHMCS has created its credit note.
     */
    public function requestRefund(int $invoiceId, ?int $adminId = null): bool
    {
        if ($this->original($invoiceId) !== null) {
            return $this->record(self::REFUND, $invoiceId, $adminId);
        }

        return $this->massPayChildren($invoiceId) !== [] && $this->record(self::MASS_PAY, $invoiceId, $adminId);
    }

    /**
     * A storno issued by an admin: $netCents and $taxCents (positive) of
     * what is left of the invoice. The whole rest gives the invoice lines
     * negated when nothing was reversed before; otherwise one line.
     *
     * The VAT must match the rate of the invoice (within the ANAF tolerance),
     * and the XML is built before the storno is kept: when it cannot be
     * built, nothing is recorded and no fiscal number is used.
     *
     * @return int the document ID
     * @throws StornoException
     */
    public function issueManual(int $invoiceId, int $netCents, int $taxCents, ?int $adminId, int $lockTimeout): int
    {
        $original = $this->original($invoiceId);
        if ($original === null) {
            throw new StornoException('Not a fiscal invoice.', StornoException::NOT_FISCAL);
        }
        $ownLock = !NumberingLock::held();
        if (!NumberingLock::acquire(0, $lockTimeout)) {
            throw new StornoException('The fiscal numbering is busy.', StornoException::BUSY);
        }
        try {
            [$restNet, $restTax] = $this->rest($original);
            if ($netCents < 0 || $taxCents < 0 || $netCents + $taxCents <= 0 || $netCents > $restNet || $taxCents > $restTax) {
                throw new StornoException('The amounts are not within what is left of the invoice.', StornoException::AMOUNTS);
            }
            $this->checkVat($original, $netCents, $taxCents);

            return Capsule::connection()->transaction(function () use ($original, $invoiceId, $netCents, $taxCents, $adminId): int {
                $id = $this->issue($original, [
                    'dedupe_key' => 'manual:' . $invoiceId . ':' . bin2hex(random_bytes(6)),
                    'source' => Document::SOURCE_MANUAL,
                    'reason' => DocumentBuilder::REASON_MANUAL,
                    'net_cents' => -$netCents,
                    'tax_cents' => -$taxCents,
                    'issued_by' => $adminId,
                ]);
                $result = ($this->builder ?? \WHMCS\Module\Addon\Efactura\Addon::documentBuilder())->build($this->documents->find($id));
                if (!$result->ok()) {
                    // Rolls back the document and the fiscal counter.
                    throw new StornoException('The storno cannot be built.', StornoException::INVALID, array_column($result->issues, 'message'));
                }

                return $id;
            });
        } finally {
            if ($ownLock) {
                NumberingLock::releaseIfOwner(0);
            }
        }
    }

    /**
     * The VAT of a part reversed by hand: the rate of the invoice applied to
     * the net, within the tolerance ANAF accepts (less than 1.00); none when
     * the invoice has no VAT.
     *
     * @throws StornoException
     */
    private function checkVat(object $original, int $netCents, int $taxCents): void
    {
        $rate = self::vatRate($original);
        if ($rate === '0') {
            if ($taxCents !== 0) {
                throw new StornoException('The invoice has no VAT.', StornoException::VAT_NONE);
            }

            return;
        }
        $expected = Money::percent($netCents, $rate);
        if ($netCents === 0 || abs($taxCents - $expected) >= 100) {
            throw new StornoException('The VAT does not match the rate of the invoice.', StornoException::VAT, [Money::format($taxCents), $rate, Money::format($netCents), Money::format($expected)]);
        }
    }

    /**
     * The VAT rate of a fiscal invoice, "0" when it has no VAT.
     */
    public static function vatRate(object $original): string
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $original->invoice_id)->first(['tax', 'taxrate']);

        return $invoice === null || Money::cents((string) $invoice->tax) === 0 ? '0' : Money::trimDecimal((string) $invoice->taxrate);
    }

    /**
     * What is left of a fiscal invoice after its stornos: net and VAT, in
     * minor units.
     *
     * @return array{0: int, 1: int}
     */
    public function rest(object $original): array
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $original->invoice_id)->first(['subtotal', 'tax']);
        $stornos = $this->documents->stornosOf((int) $original->id);

        return [
            Money::cents((string) $invoice->subtotal) + $this->sum($stornos, 'amount_net'),
            Money::cents((string) $invoice->tax) + $this->sum($stornos, 'amount_tax'),
        ];
    }

    /**
     * Processes the pending requests at the end of this PHP request, when
     * WHMCS has written everything (the credit note of a partial refund).
     */
    public function processAtShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            try {
                $this->process(NumberingLock::paymentTimeout());
            } catch (Throwable) {
                // The cron tries again.
            }
        });
    }

    /**
     * Issues the stornos of the pending requests.
     *
     * @param list<int>|null $onlyInvoices
     * @return int stornos issued
     */
    public function process(int $lockTimeout, ?array $onlyInvoices = null): int
    {
        $pending = $this->pending($onlyInvoices);
        if ($pending === []) {
            return 0;
        }
        $ownLock = !NumberingLock::held();
        if (!NumberingLock::acquire(0, $lockTimeout)) {
            $this->alertWaiting($pending);

            return 0;
        }
        $issued = 0;
        try {
            foreach ($pending as $name => $request) {
                try {
                    [$done, $count] = match ($request['type']) {
                        self::CANCEL => $this->cancel($request),
                        self::MASS_PAY => $this->massPayRefunds($request),
                        default => $this->refunds($request),
                    };
                    $issued += $count;
                    if ($done) {
                        $this->state->forget($name);
                        unset($pending[$name]);
                    }
                } catch (Throwable $e) {
                    $this->state->set($name, array_merge($request, ['error' => $e->getMessage()]));
                }
            }
        } finally {
            if ($ownLock) {
                NumberingLock::releaseIfOwner(0);
            }
        }
        $this->alertWaiting($pending);

        return $issued;
    }

    private function record(string $type, int $invoiceId, ?int $adminId): bool
    {
        $this->state->add(self::PENDING . $type . ':' . $invoiceId, [
            'type' => $type,
            'invoice' => $invoiceId,
            'admin' => $adminId,
            'since' => Clock::now()->format('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * @param list<int>|null $onlyInvoices
     * @return array<string, array{type: string, invoice: int, admin: ?int, since: string, alerted?: bool, error?: string}>
     */
    private function pending(?array $onlyInvoices): array
    {
        $pending = [];
        foreach ($this->state->withPrefix(self::PENDING) as $name => $request) {
            if (is_array($request) && isset($request['type'], $request['invoice'])
                && ($onlyInvoices === null || in_array((int) $request['invoice'], $onlyInvoices, true))) {
                $pending[$name] = $request;
            }
        }

        return $pending;
    }

    /**
     * The fiscal invoice document of a WHMCS invoice, or null for a proforma.
     */
    private function original(int $invoiceId): ?object
    {
        $document = $this->documents->forInvoice($invoiceId);

        return $document !== null && $document->kind === Document::KIND_INVOICE && (string) $document->number !== '' ? $document : null;
    }

    /**
     * @param array{invoice: int, admin: ?int} $request
     * @return array{0: bool, 1: int} done, stornos issued
     */
    private function cancel(array $request): array
    {
        $invoiceId = (int) $request['invoice'];
        $original = $this->original($invoiceId);
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status', 'subtotal', 'tax']);
        $key = 'cancel:' . $invoiceId;
        if ($original === null || $invoice === null || $invoice->status !== 'Cancelled'
            || Capsule::table(Document::TABLE)->where('dedupe_key', $key)->exists()) {
            return [true, 0];
        }

        $stornos = $this->documents->stornosOf((int) $original->id);
        $net = Money::cents((string) $invoice->subtotal) + $this->sum($stornos, 'amount_net');
        $tax = Money::cents((string) $invoice->tax) + $this->sum($stornos, 'amount_tax');
        if ($stornos !== [] && $net + $tax <= 0) {
            // Already refunded in full.
            return [true, 0];
        }
        $this->issue($original, [
            'dedupe_key' => $key,
            'source' => Document::SOURCE_CANCEL,
            'reason' => $stornos === [] ? DocumentBuilder::REASON_CANCEL : DocumentBuilder::REASON_CANCEL_REST,
            'net_cents' => -$net,
            'tax_cents' => -$tax,
            'issued_by' => $request['admin'],
        ]);

        return [true, 1];
    }

    /**
     * @param array{invoice: int, admin: ?int, since: string} $request
     * @return array{0: bool, 1: int} done, stornos issued
     */
    private function refunds(array $request): array
    {
        $invoiceId = (int) $request['invoice'];
        $original = $this->original($invoiceId);
        if ($original === null) {
            return [true, 0];
        }
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['subtotal', 'tax', 'total']);
        $invoiceTotal = Money::cents((string) $invoice->total);
        $notes = $this->refundNotes($invoiceId, (string) $original->created_at);
        $issued = 0;
        foreach ($notes as $note) {
            $stornos = $this->documents->stornosOf((int) $original->id);
            $noteTotal = Money::cents((string) $note->total);
            if (-$this->sum($stornos, 'total') + $noteTotal > $invoiceTotal) {
                // More than the invoice would be reversed: the admin decides.
                if ($this->state->add('storno_overflow:' . $note->id, true)) {
                    $this->alert('alert_storno_overflow', $original, (string) $note->id);
                }
                continue;
            }
            $full = $stornos === [] && $noteTotal === $invoiceTotal;
            $this->issue($original, [
                'dedupe_key' => 'note:' . $note->id,
                'source' => Document::SOURCE_REFUND,
                'reason' => $full ? DocumentBuilder::REASON_REFUND_FULL : DocumentBuilder::REASON_REFUND_PARTIAL,
                'billing_note_id' => (int) $note->id,
                'net_cents' => -($full ? Money::cents((string) $invoice->subtotal) : Money::cents((string) $note->subtotal)),
                'tax_cents' => -($full ? Money::cents((string) $invoice->tax) : Money::cents((string) $note->tax)),
                'issued_by' => $request['admin'],
            ]);
            $issued++;
        }
        if ($notes !== []) {
            return [true, $issued];
        }
        // The credit note is not there yet: try again, but not forever.
        $expired = Clock::parse($request['since']) < Clock::now()->modify('-' . self::GIVE_UP_DAYS . ' days');
        if ($expired) {
            $this->alert('alert_storno_no_note', $original, '');
        }

        return [$expired, 0];
    }

    /**
     * @param array{invoice: int, admin: ?int, since: string} $request
     * @return array{0: bool, 1: int} done, stornos issued
     */
    private function massPayRefunds(array $request): array
    {
        $containerId = (int) $request['invoice'];
        $children = $this->massPayChildren($containerId);
        if ($children === []) {
            return [true, 0];
        }
        $since = min(array_map(static fn (object $document): string => (string) $document->created_at, $children));
        $notes = $this->refundNotes($containerId, $since, false);
        if ($notes === []) {
            $expired = Clock::parse($request['since']) < Clock::now()->modify('-' . self::GIVE_UP_DAYS . ' days');
            if ($expired) {
                $this->alert('alert_storno_no_note', reset($children), '');
            }

            return [$expired, 0];
        }

        $containerTotal = Money::cents((string) Capsule::table('tblinvoices')->where('id', $containerId)->value('total'));
        $issued = 0;
        foreach ($notes as $note) {
            if (Money::cents((string) $note->total) !== $containerTotal) {
                // A partial refund cannot be split between the invoices safely.
                if ($this->state->add('storno_masspay:' . $note->id, true)) {
                    $this->massPayAlert('alert_masspay_partial', $containerId, (string) $note->total, $children);
                }
                continue;
            }
            foreach ($children as $childId => $original) {
                $key = 'masspay:' . $note->id . ':' . $childId;
                if (Capsule::table(Document::TABLE)->where('dedupe_key', $key)->exists()) {
                    continue;
                }
                if ($this->documents->stornosOf((int) $original->id) !== []) {
                    if ($this->state->add('storno_' . $key, true)) {
                        $this->massPayAlert('alert_masspay_reversed', $containerId, (string) $original->number, $children);
                    }
                    continue;
                }
                $invoice = Capsule::table('tblinvoices')->where('id', $childId)->first(['subtotal', 'tax', 'total']);
                $line = Money::cents((string) Capsule::table('tblinvoiceitems')->where('invoiceid', $containerId)->where('type', 'Invoice')->where('relid', $childId)->sum('amount'));
                if ($line !== Money::cents((string) $invoice->total)) {
                    // Partly paid before: only that part came back.
                    if ($this->state->add('storno_' . $key, true)) {
                        $this->massPayAlert('alert_masspay_partly_paid', $containerId, $original->number . ': ' . Money::format($line) . ' / ' . Money::format(Money::cents((string) $invoice->total)), $children);
                    }
                    continue;
                }
                $this->issue($original, [
                    'dedupe_key' => $key,
                    'source' => Document::SOURCE_REFUND,
                    'reason' => DocumentBuilder::REASON_REFUND_FULL,
                    'net_cents' => -Money::cents((string) $invoice->subtotal),
                    'tax_cents' => -Money::cents((string) $invoice->tax),
                    'issued_by' => $request['admin'],
                ]);
                $issued++;
            }
        }

        return [true, $issued];
    }

    /**
     * The fiscal invoices paid through a Mass Pay invoice, by invoice ID (none
     * when the invoice is not a Mass Pay invoice).
     *
     * @return array<int, object>
     */
    private function massPayChildren(int $invoiceId): array
    {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get(['type', 'relid']);
        if ($items->isEmpty() || $items->contains(static fn (object $item): bool => $item->type !== 'Invoice')) {
            return [];
        }
        $children = [];
        foreach ($items as $item) {
            $original = $this->original((int) $item->relid);
            if ($original !== null) {
                $children[(int) $item->relid] = $original;
            }
        }

        return $children;
    }

    /**
     * @param array<int, object> $children
     */
    private function massPayAlert(string $key, int $containerId, string $detail, array $children): void
    {
        $numbers = implode(', ', array_map(static fn (object $document): string => (string) $document->number, $children));
        try {
            AdminNotifier::send(
                Lang::get($key . '_subject', $containerId),
                Lang::get($key . '_body', $containerId, $detail, $numbers, AdminContext::adminUrl('invoices.php?action=edit&id=' . $containerId))
            );
        } catch (Throwable) {
            // An alert never stops the stornos.
        }
    }

    /**
     * The WHMCS credit notes created by refunds of the invoice since $since:
     * each refund transaction is paired with the next credit adjustment of
     * the same amount on the invoice. Notes that have a storno already are
     * left out when $skipReversed.
     *
     * @return list<object>
     */
    private function refundNotes(int $invoiceId, string $since, bool $skipReversed = true): array
    {
        $refunds = [];
        $noteIds = [];
        $rows = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->orderBy('id')
            ->get(['id', 'type', 'amountin', 'amountout', 'refundid', 'billingnoteid']);
        foreach ($rows as $row) {
            if ((int) $row->refundid > 0 && Money::cents((string) $row->amountout) > 0) {
                $refunds[] = Money::cents((string) $row->amountout);
            } elseif ($row->type === 'invoice_billing_adjustment_credit' && (int) $row->billingnoteid > 0) {
                $match = array_search(Money::cents((string) $row->amountin), $refunds, true);
                if ($match !== false) {
                    unset($refunds[$match]);
                    $noteIds[] = (int) $row->billingnoteid;
                }
            }
        }
        if ($noteIds === []) {
            return [];
        }
        $done = $skipReversed ? Capsule::table(Document::TABLE)->whereIn('billing_note_id', $noteIds)->pluck('billing_note_id')->map(static fn ($id): int => (int) $id)->all() : [];

        return Capsule::table('tblbillingnotes')
            ->whereIn('id', array_values(array_diff($noteIds, $done)))
            ->where('note_type', 'credit')
            // Only refunds made after the invoice became fiscal.
            ->where('date_issued', '>=', Clock::parse($since)->modify('-1 minute')->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get(['id', 'subtotal', 'tax', 'tax2', 'total'])
            ->all();
    }

    /**
     * Takes the next fiscal number and records the storno (the numbering
     * lock is held).
     *
     * @param array{dedupe_key: string, source: string, reason: string, net_cents: int, tax_cents: int,
     *              billing_note_id?: int|null, issued_by?: int|null} $data
     */
    private function issue(object $original, array $data): int
    {
        return Capsule::connection()->transaction(function () use ($original, $data): int {
            $today = Clock::today();

            return $this->documents->createStornoDocument($original, $data + [
                'number' => $this->numbering->allocate($today),
                'issue_date' => $today,
            ]);
        });
    }

    /**
     * @param list<object> $documents
     */
    private function sum(array $documents, string $column): int
    {
        return array_sum(array_map(static fn (object $document): int => Money::cents((string) $document->{$column}), $documents));
    }

    /**
     * @param array<string, array{type: string, invoice: int, since: string, alerted?: bool, error?: string}> $pending
     */
    private function alertWaiting(array $pending): void
    {
        $limit = Clock::now()->modify('-' . self::ALERT_AFTER_MINUTES . ' minutes');
        foreach ($pending as $name => $request) {
            if (($request['alerted'] ?? false) || Clock::parse($request['since']) > $limit) {
                continue;
            }
            $original = $this->original((int) $request['invoice']);
            if ($original !== null) {
                $this->alert('alert_storno_waiting', $original, (string) ($request['error'] ?? ''));
            }
            $this->state->set($name, array_merge($request, ['alerted' => true]));
        }
    }

    private function alert(string $key, object $original, string $details): void
    {
        try {
            AdminNotifier::send(
                Lang::get($key . '_subject', (string) $original->number),
                Lang::get($key . '_body', (string) $original->number, (int) $original->invoice_id, $details, AdminContext::adminUrl('invoices.php?action=edit&id=' . (int) $original->invoice_id))
            );
        } catch (Throwable) {
            // An alert never stops the stornos.
        }
    }
}
