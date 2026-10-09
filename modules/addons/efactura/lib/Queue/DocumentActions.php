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

namespace WHMCS\Module\Addon\Efactura\Queue;

use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;

/**
 * What an admin can do with a document waiting in the queue: send it now,
 * hold it, release it, or send a rejected one again after correcting the
 * data (the XML is generated again, with the same number).
 */
final class DocumentActions
{
    /** States from which "send now" is allowed. */
    public const SENDABLE = [Document::STATE_SCHEDULED, Document::STATE_HELD, Document::STATE_INVALID, Document::STATE_RETRY, Document::STATE_REJECTED];

    public function __construct(private readonly DocumentRepository $documents)
    {
    }

    /**
     * Queues the document for the next worker run. For a held document this
     * also confirms the manual check; for a rejected one, the XML is built
     * again from the corrected data.
     */
    public function sendNow(int $documentId, ?int $adminId): bool
    {
        $document = $this->documents->find($documentId);
        if ($document === null || !in_array($document->state, self::SENDABLE, true)) {
            return false;
        }
        $fields = [
            'send_after' => Clock::now()->format('Y-m-d H:i:s'),
            'next_attempt_at' => null,
            'held_at' => null,
            'held_by' => null,
            'review_reason' => null,
            'alerted_state' => null,
        ];
        if ($document->state === Document::STATE_REJECTED) {
            // A rejected invoice is corrected and sent again with the same number.
            $fields += ['xml' => null, 'xml_sha256' => null, 'upload_index' => null, 'download_id' => null, 'archive_id' => null, 'anaf_state' => null, 'errors' => null];
        }

        return $this->documents->transition($document, Document::STATE_SCHEDULED, $fields, 'send_now', '', [], $adminId);
    }

    public function hold(int $documentId, ?int $adminId): bool
    {
        $document = $this->documents->find($documentId);
        if ($document === null || !in_array($document->state, [Document::STATE_SCHEDULED, Document::STATE_INVALID, Document::STATE_RETRY], true)) {
            return false;
        }

        return $this->documents->transition($document, Document::STATE_HELD, [
            'held_at' => Clock::now()->format('Y-m-d H:i:s'),
            'held_by' => $adminId,
        ], 'held', '', [], $adminId);
    }

    /**
     * Releases a held document to its schedule (and confirms a manual check).
     */
    public function release(int $documentId, ?int $adminId): bool
    {
        $document = $this->documents->find($documentId);
        if ($document === null || $document->state !== Document::STATE_HELD) {
            return false;
        }

        return $this->documents->transition($document, Document::STATE_SCHEDULED, [
            'held_at' => null,
            'held_by' => null,
            'review_reason' => null,
        ], 'released', '', [], $adminId);
    }
}
