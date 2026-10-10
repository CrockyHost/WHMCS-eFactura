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
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Inbox\InboxSync;
use WHMCS\Module\Addon\Efactura\Inbox\InvoiceReader;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * The SPV inbox pages: the messages of the seller CUI (invoices from
 * suppliers, messages from buyers, the answers to the addon's own uploads)
 * and the page of one message.
 */
final class InboxPage
{
    public const PER_PAGE = 50;
    /** What the admin is expected to read: invoices received, buyer messages. */
    public const TO_READ = [InboxSync::RECEIVED, InboxSync::BUYER];
    private const ALL = 'all';

    public function __construct(private readonly string $modulelink)
    {
    }

    /**
     * Messages not opened yet, for the menu and the dashboard.
     */
    public static function unseen(): int
    {
        return Capsule::table(InboxSync::TABLE)
            ->where('environment', Settings::environment())
            ->whereIn('kind', self::TO_READ)
            ->whereNull('seen_at')
            ->count();
    }

    /**
     * @param array<string, mixed> $query the GET parameters
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        // By default what is to be read: invoices received, buyer messages.
        $kind = (string) ($query['kind'] ?? '');
        $show = (string) ($query['show'] ?? '');
        $search = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));

        $rows = Capsule::table(InboxSync::TABLE)->where('environment', Settings::environment());
        if (in_array($kind, [InboxSync::RECEIVED, InboxSync::BUYER, InboxSync::SENT, InboxSync::ERRORS, InboxSync::OTHER], true)) {
            $rows->where('kind', $kind);
        } elseif ($kind !== self::ALL) {
            $kind = '';
            $rows->whereIn('kind', self::TO_READ);
        }
        if ($show === 'unseen') {
            $rows->whereNull('seen_at');
        } elseif ($show === 'unprocessed') {
            $rows->whereNull('processed_at');
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $rows->where(static function ($where) use ($like): void {
                $where->where('issuer_name', 'like', $like)->orWhere('issuer_cif', 'like', $like)->orWhere('invoice_number', 'like', $like)
                    ->orWhere('request_id', 'like', $like)->orWhere('details', 'like', $like);
            });
        }
        $total = (clone $rows)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $messages = [];
        foreach ($rows->orderByDesc('anaf_created_at')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get() as $row) {
            $messages[] = $this->summary($row);
        }
        $base = $this->modulelink . '&view=inbox&kind=' . rawurlencode($kind) . '&show=' . rawurlencode($show) . '&q=' . rawurlencode($search);
        $kinds = [['value' => '', 'label' => Lang::get('inbox_kind_to_read')]];
        foreach ([InboxSync::RECEIVED, InboxSync::BUYER, InboxSync::SENT, InboxSync::ERRORS] as $value) {
            $kinds[] = ['value' => $value, 'label' => Lang::get('inbox_kind_' . $value)];
        }
        $kinds[] = ['value' => self::ALL, 'label' => Lang::get('filter_all')];
        $lastRun = (string) (new RuntimeState())->get('inbox_last_run', '');

        return [
            'messages' => $messages,
            'filter' => ['kind' => $kind, 'show' => $show, 'q' => $search],
            'kindOptions' => $kinds,
            'countText' => Lang::get('inbox_count', $total),
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $base . '&page=' . ($page - 1) : '',
            'nextUrl' => $page < $pages ? $base . '&page=' . ($page + 1) : '',
            'lastSync' => DocumentPresenter::dateTime($lastRun),
        ];
    }

    /**
     * One message; opening it marks it as seen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $messageId, ?int $adminId): ?array
    {
        $row = Capsule::table(InboxSync::TABLE)->where('id', $messageId)->first();
        if ($row === null) {
            return null;
        }
        if ($row->seen_at === null) {
            Capsule::table(InboxSync::TABLE)->where('id', $messageId)->update(['seen_at' => Clock::now()->format('Y-m-d H:i:s'), 'seen_by' => $adminId]);
            $row = Capsule::table(InboxSync::TABLE)->where('id', $messageId)->first();
        }
        $invoice = null;
        if ($row->kind === InboxSync::RECEIVED && (int) $row->archive_id > 0) {
            $zip = (string) Capsule::table('mod_efactura_archive')->where('id', $row->archive_id)->value('content');
            try {
                $invoice = InvoiceReader::read((string) ResponseZip::read($zip)->invoiceXml);
            } catch (\RuntimeException) {
                $invoice = null;
            }
            // Dates as on the other pages; anything else as the supplier wrote it.
            foreach (['date', 'due'] as $field) {
                if ($invoice !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice[$field]) === 1) {
                    $invoice[$field] = DocumentPresenter::date($invoice[$field]);
                }
            }
        }

        return [
            'message' => $this->summary($row),
            'invoice' => $invoice,
            'raw' => (string) $row->details,
            'seen' => $row->seen_at !== null ? Lang::get('inbox_seen_by', DocumentPresenter::dateTime((string) $row->seen_at), AdminContext::name($row->seen_by !== null ? (int) $row->seen_by : null)) : '',
            'processed' => $row->processed_at !== null ? Lang::get('inbox_processed_by', DocumentPresenter::dateTime((string) $row->processed_at), AdminContext::name($row->processed_by !== null ? (int) $row->processed_by : null)) : '',
            'isProcessed' => $row->processed_at !== null,
        ];
    }

    /**
     * The buyer messages about a document, for its panel.
     *
     * @return list<array<string, mixed>>
     */
    public function forDocument(int $documentId): array
    {
        $messages = [];
        $rows = Capsule::table(InboxSync::TABLE)->where('document_id', $documentId)->where('kind', InboxSync::BUYER)->orderBy('anaf_created_at')->get();
        foreach ($rows as $row) {
            $messages[] = $this->summary($row);
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(object $row): array
    {
        $document = $row->document_id !== null ? Capsule::table(Document::TABLE)->where('id', $row->document_id)->first(['id', 'number', 'invoice_id']) : null;

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'kindLabel' => Lang::get('inbox_kind_' . ($row->kind !== '' ? $row->kind : InboxSync::OTHER)),
            'date' => DocumentPresenter::dateTime((string) $row->anaf_created_at),
            'issuer' => (string) ($row->issuer_name ?? ''),
            'issuerCui' => (string) ($row->issuer_cif ?? ''),
            'number' => (string) ($row->invoice_number ?? ''),
            'invoiceDate' => DocumentPresenter::date((string) $row->invoice_date),
            'total' => $row->total !== null ? Money::format(Money::cents((string) $row->total)) . ' ' . $row->currency : '',
            'text' => (string) ($row->message_text ?? ''),
            'details' => (string) $row->details,
            'requestId' => (string) ($row->request_id ?? ''),
            'document' => $document !== null ? ['number' => (string) $document->number, 'url' => $this->modulelink . '&view=document&id=' . (int) $document->id, 'invoiceId' => (int) $document->invoice_id] : null,
            'fileUrl' => (int) $row->archive_id > 0 ? $this->modulelink . '&view=download&archive=' . (int) $row->archive_id : '',
            'fileLost' => $row->archive_id !== null && (int) $row->archive_id === 0,
            'downloadError' => $row->archive_id === null && (string) $row->last_error !== '' ? (string) $row->last_error : '',
            'unseen' => $row->seen_at === null && in_array($row->kind, self::TO_READ, true),
            'processed' => $row->processed_at !== null,
            'url' => $this->modulelink . '&view=message&id=' . (int) $row->id,
        ];
    }
}
