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
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Queue\DeadlineMonitor;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * The addon pages about documents: the filterable list, one document with
 * its history, and the state of the queue for the dashboard.
 */
final class DocumentsPage
{
    public const PER_PAGE = 50;
    /** The "needs attention" filter. */
    public const ATTENTION = [Document::STATE_INVALID, Document::STATE_REJECTED, Document::STATE_HELD, Document::STATE_UNKNOWN, Document::STATE_RETRY];
    /** The worker is reported as stopped after this long without a run. */
    private const WORKER_LATE_MINUTES = 15;

    public function __construct(private readonly string $modulelink)
    {
    }

    /**
     * @param array<string, mixed> $query the GET parameters
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $state = (string) ($query['state'] ?? '');
        $kind = (string) ($query['kind'] ?? '');
        $search = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));

        $rows = Capsule::table(Document::TABLE . ' as d')
            ->leftJoin('tblclients as c', 'c.id', '=', 'd.client_id')
            ->select(array_merge(
                array_map(static fn (string $column): string => 'd.' . $column, DocumentRepository::listColumns()),
                ['c.companyname', 'c.firstname', 'c.lastname']
            ));
        if ($state === 'attention') {
            $rows->whereIn('d.state', self::ATTENTION);
        } elseif ($state !== '') {
            $rows->where('d.state', $state);
        }
        if (in_array($kind, [Document::KIND_INVOICE, Document::KIND_STORNO], true)) {
            $rows->where('d.kind', $kind);
        }
        if ($search !== '') {
            $rows->where(static function ($where) use ($search): void {
                $where->where('d.number', 'like', '%' . addcslashes($search, '%_\\') . '%');
                if (ctype_digit($search)) {
                    $where->orWhere('d.invoice_id', (int) $search)->orWhere('d.upload_index', $search);
                }
                $where->orWhere('c.companyname', 'like', '%' . addcslashes($search, '%_\\') . '%');
            });
        }
        $total = (clone $rows)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $documents = [];
        foreach ($rows->orderByDesc('d.id')->forPage($page, self::PER_PAGE)->get() as $row) {
            $documents[] = [
                'id' => (int) $row->id,
                'number' => (string) $row->number,
                'kindLabel' => Lang::get('kind_' . $row->kind),
                'reasonLabel' => $row->kind === Document::KIND_STORNO ? Lang::get('storno_reason_' . $row->reason) : '',
                'invoiceId' => (int) $row->invoice_id,
                'clientId' => (int) $row->client_id,
                'client' => trim((string) $row->companyname) !== '' ? (string) $row->companyname : trim($row->firstname . ' ' . $row->lastname),
                'issueDate' => DocumentPresenter::date((string) $row->issue_date),
                'total' => $row->total !== null ? Money::format(Money::cents((string) $row->total)) . ' ' . $row->currency : '',
                'stateLabel' => Lang::get('state_' . $row->state),
                'stateClass' => DocumentPresenter::stateClass((string) $row->state),
                'deadline' => in_array($row->state, DeadlineMonitor::WAITING, true) ? DocumentPresenter::date((string) $row->deadline_date) : '',
                'late' => in_array($row->state, DeadlineMonitor::WAITING, true) && $row->deadline_date !== null
                    && WorkingDays::remaining(Clock::today(), Clock::parse((string) $row->deadline_date)) <= 1,
                'uploadIndex' => (string) ($row->upload_index ?? ''),
                'url' => $this->modulelink . '&view=document&id=' . (int) $row->id,
            ];
        }

        $states = [['value' => '', 'label' => Lang::get('filter_all')], ['value' => 'attention', 'label' => Lang::get('filter_attention')]];
        foreach ([Document::STATE_SCHEDULED, Document::STATE_HELD, Document::STATE_INVALID, Document::STATE_SENDING, Document::STATE_UNKNOWN,
            Document::STATE_PROCESSING, Document::STATE_VALIDATED, Document::STATE_REJECTED, Document::STATE_RETRY, Document::STATE_EXCLUDED] as $value) {
            $states[] = ['value' => $value, 'label' => Lang::get('state_' . $value)];
        }
        $base = $this->modulelink . '&view=documents&state=' . rawurlencode($state) . '&kind=' . rawurlencode($kind) . '&q=' . rawurlencode($search);

        return [
            'documents' => $documents,
            'filter' => ['state' => $state, 'kind' => $kind, 'q' => $search],
            'stateOptions' => $states,
            'total' => $total,
            'countText' => Lang::get('documents_count', $total),
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $base . '&page=' . ($page - 1) : '',
            'nextUrl' => $page < $pages ? $base . '&page=' . ($page + 1) : '',
        ];
    }

    /**
     * One document, its stornos or its original, and its history.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $documentId): ?array
    {
        $document = Addon::documents()->find($documentId);
        if ($document === null) {
            return null;
        }
        $presenter = new DocumentPresenter($this->modulelink);
        $client = Capsule::table('tblclients')->where('id', $document->client_id)->first(['companyname', 'firstname', 'lastname']);
        $related = [];
        if ($document->kind === Document::KIND_INVOICE) {
            foreach (Addon::documents()->stornosOf((int) $document->id) as $storno) {
                $related[] = $presenter->present(Addon::documents()->find((int) $storno->id));
            }
        }

        $history = [];
        $rows = Capsule::table('mod_efactura_audit')->where('document_id', $documentId)->orderByDesc('id')->limit(200)->get();
        foreach ($rows as $row) {
            $history[] = [
                'date' => DocumentPresenter::dateTime((string) $row->created_at),
                'event' => Lang::has('event_' . $row->event) ? Lang::get('event_' . $row->event) : (string) $row->event,
                'states' => $row->from_state !== null && $row->to_state !== null && $row->from_state !== $row->to_state
                    ? Lang::get('state_' . $row->from_state) . ' → ' . Lang::get('state_' . $row->to_state) : '',
                'message' => (string) $row->message,
                'admin' => $row->admin_id !== null ? AdminContext::name((int) $row->admin_id) : '',
            ];
        }

        return [
            'doc' => $presenter->present($document),
            'client' => $client !== null ? (trim((string) $client->companyname) !== '' ? (string) $client->companyname : trim($client->firstname . ' ' . $client->lastname)) : '',
            'clientId' => (int) $document->client_id,
            'related' => $related,
            'history' => $history,
        ];
    }

    /**
     * The state of the queue for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function queue(): array
    {
        $state = new RuntimeState();
        $now = Clock::now();
        $lastRun = (string) $state->get('worker_last_run', '');
        $future = static fn (string $value): string => $value !== '' && Clock::parse($value) > $now ? DocumentPresenter::dateTime($value) : '';
        $horizon = WorkingDays::add(Clock::today(), 1)->format('Y-m-d');

        return [
            'lastRun' => DocumentPresenter::dateTime($lastRun),
            'workerLate' => Settings::bool('enabled') && ($lastRun === '' || Clock::parse($lastRun) < $now->modify('-' . self::WORKER_LATE_MINUTES . ' minutes')),
            'breakerUntil' => $future((string) $state->get('breaker_until', '')),
            'authPausedUntil' => $future((string) $state->get('auth_paused_until', '')),
            'pendingStornos' => count($state->withPrefix('storno:')),
            'attention' => Capsule::table(Document::TABLE)->whereIn('state', self::ATTENTION)->count(),
            'nearDeadline' => Capsule::table(Document::TABLE)->whereIn('state', DeadlineMonitor::WAITING)->whereNotNull('deadline_date')->where('deadline_date', '<=', $horizon)->count(),
            'attentionUrl' => $this->modulelink . '&view=documents&state=attention',
        ];
    }
}
