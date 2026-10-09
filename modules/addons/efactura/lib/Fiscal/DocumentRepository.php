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

/**
 * Creates and reads the fiscal documents (mod_efactura_documents).
 */
final class DocumentRepository
{
    public function forInvoice(int $invoiceId): ?object
    {
        return Capsule::table(Document::TABLE)->where('dedupe_key', Document::invoiceKey($invoiceId))->first();
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
