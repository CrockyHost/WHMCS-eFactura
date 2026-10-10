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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Turns a document row into what the admin templates show: its state,
 * dates, ANAF data, errors, files and the actions allowed in its state.
 */
final class DocumentPresenter
{
    /** Bootstrap label class of each state. */
    private const STATE_CLASS = [
        Document::STATE_SCHEDULED => 'info',
        Document::STATE_HELD => 'warning',
        Document::STATE_INVALID => 'danger',
        Document::STATE_SENDING => 'info',
        Document::STATE_UNKNOWN => 'warning',
        Document::STATE_PROCESSING => 'primary',
        Document::STATE_VALIDATED => 'success',
        Document::STATE_REJECTED => 'danger',
        Document::STATE_RETRY => 'warning',
        Document::STATE_EXCLUDED => 'default',
    ];

    public function __construct(private readonly string $modulelink)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(object $document): array
    {
        $state = (string) $document->state;
        $currency = (string) $document->currency;
        $storno = $document->kind === Document::KIND_STORNO;
        $original = $storno ? Capsule::table(Document::TABLE)->where('id', $document->original_document_id)->first(['id', 'number', 'state']) : null;

        return [
            'id' => (int) $document->id,
            'invoiceId' => (int) $document->invoice_id,
            'number' => (string) $document->number,
            'kind' => (string) $document->kind,
            'kindLabel' => Lang::get('kind_' . $document->kind),
            'reasonLabel' => $storno ? Lang::get('storno_reason_' . $document->reason) : '',
            'original' => $original !== null ? ['id' => (int) $original->id, 'number' => (string) $original->number] : null,
            'waitsForOriginal' => $original !== null && $original->state !== Document::STATE_VALIDATED
                && in_array($state, [Document::STATE_SCHEDULED, Document::STATE_HELD, Document::STATE_RETRY, Document::STATE_INVALID], true),
            'state' => $state,
            'stateLabel' => Lang::get('state_' . $state),
            'stateClass' => self::stateClass($state),
            'issueDate' => self::date((string) $document->issue_date),
            'total' => $document->total !== null ? Money::format(Money::cents((string) $document->total)) . ' ' . $currency : '',
            'sendAfter' => in_array($state, [Document::STATE_SCHEDULED, Document::STATE_HELD], true) ? self::dateTime((string) $document->send_after) : '',
            'deadline' => $this->deadline($document),
            'environment' => (string) ($document->environment ?? ''),
            'uploadIndex' => (string) ($document->upload_index ?? ''),
            'anafState' => (string) ($document->anaf_state ?? ''),
            'uploadedAt' => self::dateTime((string) $document->uploaded_at),
            'validatedAt' => self::dateTime((string) $document->validated_at),
            'attempts' => (int) $document->attempts,
            'nextAttempt' => in_array($state, [Document::STATE_RETRY, Document::STATE_UNKNOWN, Document::STATE_INVALID, Document::STATE_PROCESSING], true) ? self::dateTime((string) $document->next_attempt_at) : '',
            'lastError' => in_array($state, [Document::STATE_VALIDATED, Document::STATE_EXCLUDED], true) ? '' : (string) ($document->last_error ?? ''),
            'errors' => self::errors((string) ($document->errors ?? '')),
            'review' => $state === Document::STATE_HELD && $document->review_reason !== null ? Lang::get('review_' . $document->review_reason) : '',
            'held' => $state === Document::STATE_HELD && $document->held_at !== null
                ? Lang::get('panel_held_by', AdminContext::name($document->held_by !== null ? (int) $document->held_by : null), self::dateTime((string) $document->held_at)) : '',
            'excluded' => $state === Document::STATE_EXCLUDED && $document->exclusion_reason !== null ? Lang::get('excluded_' . $document->exclusion_reason) : '',
            'issuedBy' => $document->issued_by !== null ? AdminContext::name((int) $document->issued_by) : '',
            'files' => $this->files($document),
            'archiveLost' => $document->archive_id !== null && (int) $document->archive_id === 0,
            'actions' => self::actions($state),
            'checkable' => AdminActions::checkable($document),
            'detailUrl' => $this->modulelink . '&view=document&id=' . (int) $document->id,
        ];
    }

    public static function stateClass(string $state): string
    {
        return self::STATE_CLASS[$state] ?? 'default';
    }

    /**
     * The actions an admin can take in a state, the main one first.
     *
     * @return list<array{action: string, label: string, style: string, confirm: string}>
     */
    public static function actions(string $state): array
    {
        $action = static fn (string $action, string $label, string $style, string $confirm = ''): array => [
            'action' => $action, 'label' => Lang::get($label), 'style' => $style, 'confirm' => $confirm !== '' ? Lang::get($confirm) : '',
        ];

        return match ($state) {
            Document::STATE_SCHEDULED => [$action('send_now', 'btn_send_now', 'primary'), $action('hold', 'btn_hold', 'default')],
            Document::STATE_HELD => [$action('send_now', 'btn_send_now', 'primary'), $action('release', 'btn_release', 'default')],
            Document::STATE_INVALID, Document::STATE_RETRY => [$action('send_now', 'btn_retry_now', 'primary'), $action('hold', 'btn_hold', 'default')],
            Document::STATE_REJECTED => [$action('send_now', 'btn_send_again', 'primary', 'confirm_send_again')],
            default => [],
        };
    }

    private function deadline(object $document): string
    {
        if ($document->deadline_date === null || in_array($document->state, [Document::STATE_VALIDATED, Document::STATE_EXCLUDED], true)) {
            return '';
        }
        $deadline = Clock::parse((string) $document->deadline_date);
        $remaining = WorkingDays::remaining(Clock::today(), $deadline);
        $when = match (true) {
            $remaining > 0 => Lang::get('alert_deadline_left', $remaining),
            $remaining === 0 => Lang::get('alert_deadline_last_day'),
            default => Lang::get('alert_deadline_late', -$remaining),
        };

        return $deadline->format('d.m.Y') . ' (' . $when . ')';
    }

    /**
     * @return list<array{label: string, url: string, icon: string, date: string}>
     */
    private function files(object $document): array
    {
        $files = [];
        $rows = Capsule::table('mod_efactura_archive')->where('document_id', $document->id)->orderBy('id')->get(['id', 'kind', 'upload_index', 'created_at']);
        foreach ($rows as $row) {
            $files[] = [
                'label' => Lang::get('file_' . $row->kind) . ($row->upload_index !== null && $row->kind === 'xml_sent' ? ' (' . $row->upload_index . ')' : ''),
                'url' => $this->modulelink . '&view=download&archive=' . (int) $row->id,
                'icon' => $row->kind === 'xml_sent' ? 'fa-file-code' : 'fa-file-archive',
                'date' => self::dateTime((string) $row->created_at),
            ];
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function errors(string $json): array
    {
        $errors = json_decode($json, true);
        if (!is_array($errors)) {
            return [];
        }
        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error) && ($error['message'] ?? '') !== '') {
                $rule = (string) ($error['rule'] ?? '');
                $messages[] = ($rule !== '' && !str_contains((string) $error['message'], $rule) ? '[' . $rule . '] ' : '') . $error['message'];
            }
        }

        return $messages;
    }

    public static function date(string $value): string
    {
        return $value === '' || str_starts_with($value, '0000') ? '' : Clock::parse($value)->format('d.m.Y');
    }

    public static function dateTime(string $value): string
    {
        return $value === '' || str_starts_with($value, '0000') ? '' : Clock::parse($value)->format('d.m.Y H:i');
    }
}
