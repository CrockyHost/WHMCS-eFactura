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

use DateTimeImmutable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Audit;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Creates and reads the fiscal documents (mod_efactura_documents).
 */
final class DocumentRepository
{
    /** A claim older than this is considered abandoned (crashed worker). */
    private const CLAIM_MINUTES = 15;

    /** @var list<string>|null */
    private static ?array $listColumns = null;

    /**
     * Every column except the XML, for queries that read many documents:
     * the XML is loaded only for the one being uploaded (find, claim).
     *
     * @return list<string>
     */
    public static function listColumns(): array
    {
        return self::$listColumns ??= array_values(array_diff(Capsule::schema()->getColumnListing(Document::TABLE), ['xml']));
    }

    public function forInvoice(int $invoiceId): ?object
    {
        return Capsule::table(Document::TABLE)->where('dedupe_key', Document::invoiceKey($invoiceId))->first();
    }

    public function find(int $documentId): ?object
    {
        return Capsule::table(Document::TABLE)->where('id', $documentId)->first();
    }

    /**
     * Moves a document from its current state to $to, only if nobody changed
     * the state meanwhile, and records the transition in the audit trail.
     *
     * @param array<string, mixed> $fields other columns to update
     * @param array<string, mixed> $context
     * @return bool false when the document was no longer in the expected state
     */
    public function transition(object $document, string $to, array $fields, string $event, string $message = '', array $context = [], ?int $adminId = null): bool
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        $changes = $fields + ['updated_at' => $now];
        if ($to !== $document->state) {
            $changes += ['state' => $to, 'state_changed_at' => $now];
        }
        $updated = Capsule::table(Document::TABLE)
            ->where('id', $document->id)
            ->where('state', $document->state)
            ->update($changes);
        if ($updated !== 1) {
            return false;
        }
        Audit::log($event, $message, $context, (int) $document->id, (int) $document->invoice_id, (string) $document->state, $to, $adminId);

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $documentId, array $fields): void
    {
        Capsule::table(Document::TABLE)->where('id', $documentId)->update($fields + ['updated_at' => Clock::now()->format('Y-m-d H:i:s')]);
    }

    /**
     * Claims a document for one worker: returns the fresh row, or null when
     * it is not in one of $states or another worker holds it.
     *
     * @param list<string> $states
     */
    public function claim(int $documentId, array $states): ?object
    {
        $token = bin2hex(random_bytes(10));
        $now = Clock::now();
        $claimed = Capsule::table(Document::TABLE)
            ->where('id', $documentId)
            ->whereIn('state', $states)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('lock_token')->orWhere('locked_at', '<', $now->modify('-' . self::CLAIM_MINUTES . ' minutes')->format('Y-m-d H:i:s'));
            })
            ->update(['lock_token' => $token, 'locked_at' => $now->format('Y-m-d H:i:s')]);

        return $claimed === 1 ? $this->find($documentId) : null;
    }

    public function releaseClaim(int $documentId): void
    {
        Capsule::table(Document::TABLE)->where('id', $documentId)->update(['lock_token' => null, 'locked_at' => null]);
    }

    /**
     * Stores a file in the archive (mod_efactura_archive).
     *
     * @return int the archive ID
     */
    public function archive(object $document, string $kind, string $filename, string $mime, string $content, ?string $uploadIndex = null, ?string $downloadId = null): int
    {
        return (int) Capsule::table('mod_efactura_archive')->insertGetId([
            'document_id' => $document->id,
            'kind' => $kind,
            'environment' => (string) ($document->environment ?: Settings::environment()),
            'upload_index' => $uploadIndex,
            'download_id' => $downloadId,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'content' => $content,
            'created_at' => Clock::now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Records the fiscal invoice of a WHMCS invoice and schedules it.
     *
     * @param array{number: string, proforma_number?: string|null, source: string, issue_date: DateTimeImmutable,
     *              issued_by?: int|null, review_reason?: string|null} $data
     * @return int the document ID
     */
    public function createInvoiceDocument(int $invoiceId, array $data): int
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'total']);
        $currency = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $invoice->userid)
            ->value('tblcurrencies.code');

        $now = Clock::now();
        $issueDate = $data['issue_date']->setTime(0, 0);
        $exclusion = ReportingPolicy::exclusionReason($invoiceId);
        $review = $data['review_reason'] ?? null;
        $state = match (true) {
            $review !== null => Document::STATE_HELD,
            $exclusion !== null => Document::STATE_EXCLUDED,
            default => Document::STATE_SCHEDULED,
        };

        $id = (int) Capsule::table(Document::TABLE)->insertGetId([
            'dedupe_key' => Document::invoiceKey($invoiceId),
            'kind' => Document::KIND_INVOICE,
            'source' => $data['source'],
            'invoice_id' => $invoiceId,
            'client_id' => (int) $invoice->userid,
            'number' => $data['number'],
            'proforma_number' => ($data['proforma_number'] ?? '') !== '' ? $data['proforma_number'] : null,
            'issue_date' => $issueDate->format('Y-m-d'),
            'issued_by' => $data['issued_by'] ?? null,
            'currency' => $currency !== null ? (string) $currency : null,
            'total' => $invoice->total,
            'state' => $state,
            'state_changed_at' => $now->format('Y-m-d H:i:s'),
            'exclusion_reason' => $exclusion,
            'review_reason' => $review,
            'review_at' => $review !== null ? $now->format('Y-m-d H:i:s') : null,
            'send_after' => $state === Document::STATE_EXCLUDED ? null : self::sendAfter($issueDate, $now)->format('Y-m-d H:i:s'),
            'deadline_date' => $state === Document::STATE_EXCLUDED ? null : WorkingDays::legalDeadline($issueDate)->format('Y-m-d'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        Audit::log('document_created', $data['number'], array_filter([
            'source' => $data['source'],
            'state' => $state,
            'exclusion' => $exclusion,
            'review' => $review,
            'proforma_number' => $data['proforma_number'] ?? null,
        ]), $id, $invoiceId, null, $state, $data['issued_by'] ?? null);

        return $id;
    }

    /**
     * Records a storno of $original and schedules it. It follows the
     * reporting of the original: the storno of an invoice that is not
     * reported is not reported either.
     *
     * @param array{dedupe_key: string, number: string, source: string, reason: string, issue_date: DateTimeImmutable,
     *              net_cents: int, tax_cents: int, billing_note_id?: int|null, issued_by?: int|null} $data
     * @return int the document ID
     */
    public function createStornoDocument(object $original, array $data): int
    {
        $now = Clock::now();
        $issueDate = $data['issue_date']->setTime(0, 0);
        $excluded = $original->state === Document::STATE_EXCLUDED;
        $state = $excluded ? Document::STATE_EXCLUDED : Document::STATE_SCHEDULED;
        $net = Money::format($data['net_cents']);
        $tax = Money::format($data['tax_cents']);

        $id = (int) Capsule::table(Document::TABLE)->insertGetId([
            'dedupe_key' => $data['dedupe_key'],
            'kind' => Document::KIND_STORNO,
            'source' => $data['source'],
            'reason' => $data['reason'],
            'invoice_id' => $original->invoice_id,
            'billing_note_id' => $data['billing_note_id'] ?? null,
            'original_document_id' => $original->id,
            'client_id' => $original->client_id,
            'number' => $data['number'],
            'issue_date' => $issueDate->format('Y-m-d'),
            'issued_by' => $data['issued_by'] ?? null,
            'currency' => $original->currency,
            'total' => Money::format($data['net_cents'] + $data['tax_cents']),
            'amount_net' => $net,
            'amount_tax' => $tax,
            'state' => $state,
            'state_changed_at' => $now->format('Y-m-d H:i:s'),
            'exclusion_reason' => $excluded ? $original->exclusion_reason : null,
            'send_after' => $excluded ? null : self::sendAfter($issueDate, $now)->format('Y-m-d H:i:s'),
            'deadline_date' => $excluded ? null : WorkingDays::legalDeadline($issueDate)->format('Y-m-d'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        Audit::log('storno_created', $data['number'], array_filter([
            'original' => $original->number,
            'reason' => $data['reason'],
            'net' => $net,
            'tax' => $tax,
            'billing_note_id' => $data['billing_note_id'] ?? null,
            'state' => $state,
        ]), $id, (int) $original->invoice_id, null, $state, $data['issued_by'] ?? null);

        return $id;
    }

    /**
     * The stornos already issued for an original document.
     *
     * @return list<object>
     */
    public function stornosOf(int $originalId): array
    {
        return Capsule::table(Document::TABLE)
            ->select(self::listColumns())
            ->where('kind', Document::KIND_STORNO)
            ->where('original_document_id', $originalId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function flagForReview(int $documentId, string $reason): void
    {
        $document = Capsule::table(Document::TABLE)->where('id', $documentId)->first(['state', 'invoice_id']);
        $now = Clock::now()->format('Y-m-d H:i:s');
        $values = ['review_reason' => $reason, 'review_at' => $now, 'updated_at' => $now];
        if (in_array($document->state, [Document::STATE_SCHEDULED, Document::STATE_INVALID, Document::STATE_RETRY], true)) {
            $values += ['state' => Document::STATE_HELD, 'state_changed_at' => $now];
        }
        Capsule::table(Document::TABLE)->where('id', $documentId)->update($values);
        Audit::log('document_review', $reason, [], $documentId, (int) $document->invoice_id, $document->state, $values['state'] ?? $document->state);
    }

    /**
     * When a document may be sent: at the start of the N-th working day after
     * its issue date (N = the send delay setting), or now when N is 0 or that
     * moment has passed.
     */
    public static function sendAfter(DateTimeImmutable $issueDate, DateTimeImmutable $now): DateTimeImmutable
    {
        $delay = max(0, min(Settings::MAX_SEND_DELAY_DAYS, Settings::int('send_delay_days')));
        $at = WorkingDays::add($issueDate, $delay);

        return $delay === 0 || $at < $now ? $now : $at;
    }
}
