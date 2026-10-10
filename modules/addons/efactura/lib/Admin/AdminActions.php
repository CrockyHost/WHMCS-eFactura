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

namespace WHMCS\Module\Addon\Efactura\Admin;

use Throwable;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\StornoException;
use WHMCS\Module\Addon\Efactura\Numbering\EarlyIssueException;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Queue\DocumentActions;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * What an admin can do from the invoice panel and the addon pages. The
 * caller checks the CSRF token; WHMCS checks the access to the addon.
 */
final class AdminActions
{
    public const ACTIONS = ['send_now', 'hold', 'release', 'issue_early', 'storno', 'check'];

    /**
     * @param array<string, mixed> $input the submitted form
     * @return array{type: string, text: string, details: list<string>}
     */
    public function run(string $action, array $input, ?int $adminId): array
    {
        try {
            return match ($action) {
                'send_now', 'hold', 'release' => $this->queue($action, (int) ($input['document'] ?? 0), $adminId),
                'issue_early' => $this->issueEarly((int) ($input['invoice'] ?? 0), $adminId),
                'storno' => $this->storno((int) ($input['invoice'] ?? 0), (string) ($input['net'] ?? ''), (string) ($input['tax'] ?? ''), $adminId),
                'check' => $this->check((int) ($input['document'] ?? 0)),
                default => self::result('danger', Lang::get('action_unknown')),
            };
        } catch (Throwable $e) {
            return self::result('danger', Lang::get('action_failed', $e->getMessage()));
        }
    }

    private function queue(string $action, int $documentId, ?int $adminId): array
    {
        $document = Addon::documents()->find($documentId);
        if ($document === null) {
            return self::result('danger', Lang::get('action_document_missing'));
        }
        $actions = new DocumentActions(Addon::documents());
        $done = match ($action) {
            'send_now' => $actions->sendNow($documentId, $adminId),
            'hold' => $actions->hold($documentId, $adminId),
            default => $actions->release($documentId, $adminId),
        };
        if (!$done) {
            return self::result('warning', Lang::get('action_not_allowed', Lang::get('state_' . $document->state)));
        }

        return self::result('success', Lang::get('action_done_' . $action, (string) $document->number));
    }

    private function issueEarly(int $invoiceId, ?int $adminId): array
    {
        try {
            $number = Addon::earlyIssue()->issue($invoiceId, $adminId, NumberingLock::ADMIN_TIMEOUT);
        } catch (EarlyIssueException $e) {
            return self::result('warning', Lang::get('early_error_' . $e->reason));
        }

        return self::result('success', Lang::get('action_done_issue_early', $number));
    }

    private function storno(int $invoiceId, string $net, string $tax, ?int $adminId): array
    {
        $amount = '/^\s*\d+(?:[.,]\d{1,2})?\s*$/';
        if (preg_match($amount, $net) !== 1 || preg_match($amount, $tax) !== 1) {
            return self::result('warning', Lang::get('storno_error_amounts'));
        }
        try {
            $id = Addon::stornos()->issueManual($invoiceId, Money::cents(str_replace(',', '.', trim($net))), Money::cents(str_replace(',', '.', trim($tax))), $adminId, NumberingLock::ADMIN_TIMEOUT);
        } catch (StornoException $e) {
            return match ($e->reason) {
                StornoException::VAT => self::result('warning', Lang::get('storno_error_vat', ...$e->details)),
                StornoException::INVALID => self::result('danger', Lang::get('storno_error_invalid'), $e->details),
                default => self::result('warning', Lang::get('storno_error_' . $e->reason)),
            };
        }

        return self::result('success', Lang::get('action_done_storno', (string) Addon::documents()->find($id)?->number));
    }

    /**
     * Builds the XML from the current data without sending or saving it.
     */
    private function check(int $documentId): array
    {
        $document = Addon::documents()->find($documentId);
        if ($document === null) {
            return self::result('danger', Lang::get('action_document_missing'));
        }
        $result = Addon::documentBuilder()->build($document);
        if ($result->ok()) {
            return self::result('success', Lang::get('action_check_ok', (string) $document->number));
        }

        return self::result('danger', Lang::get('action_check_failed', (string) $document->number), array_map(
            static fn (array $issue): string => $issue['message'],
            $result->issues
        ));
    }

    /**
     * Whether a document can be checked against the current data: once sent,
     * the frozen XML is what counts.
     */
    public static function checkable(object $document): bool
    {
        return in_array($document->state, [Document::STATE_SCHEDULED, Document::STATE_HELD, Document::STATE_INVALID, Document::STATE_REJECTED], true);
    }

    /**
     * @param list<string> $details
     * @return array{type: string, text: string, details: list<string>}
     */
    private static function result(string $type, string $text, array $details = []): array
    {
        return ['type' => $type, 'text' => $text, 'details' => $details];
    }
}
