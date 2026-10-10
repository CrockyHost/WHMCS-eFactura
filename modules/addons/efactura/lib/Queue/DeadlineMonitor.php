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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Audit;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Ubl\Text;

/**
 * Alerts for documents that risk the legal deadline (5 working days from
 * issue) and for uploads that ANAF keeps "in prelucrare" for too long.
 *
 * The deadline alert grows more insistent: 2 working days before, 1 day
 * before, on the last day, then every working day after it. Each level is
 * sent once per document (deadline_alert), in one digest per run.
 */
final class DeadlineMonitor
{
    /** Documents not yet with ANAF, which need someone to act. */
    public const WAITING = [Document::STATE_SCHEDULED, Document::STATE_HELD, Document::STATE_INVALID, Document::STATE_RETRY, Document::STATE_REJECTED, Document::STATE_UNKNOWN];
    /** Working days before the deadline when the alerts start. */
    private const FIRST_ALERT_DAYS = 2;
    /** Hours "in prelucrare" after which an alert is sent, by level. */
    private const PROCESSING_HOURS = [1 => 24, 2 => 48];

    /**
     * @param list<int>|null $only
     * @return int documents alerted
     */
    public function run(?array $only = null): int
    {
        return $this->deadlines($only) + $this->processing($only);
    }

    /**
     * The alert level of a document on $today: 0 before the alerts start,
     * 1 and 2 before the deadline, 3 on the last day, then 4, 5... for each
     * working day of delay.
     */
    public static function level(\DateTimeInterface $today, \DateTimeInterface $deadline): int
    {
        $remaining = WorkingDays::remaining($today, $deadline);

        return $remaining > self::FIRST_ALERT_DAYS ? 0 : self::FIRST_ALERT_DAYS + 1 - $remaining;
    }

    /**
     * @param list<int>|null $only
     */
    private function deadlines(?array $only): int
    {
        $today = Clock::today();
        $horizon = WorkingDays::add($today, self::FIRST_ALERT_DAYS)->format('Y-m-d');
        $documents = $this->select($only)
            ->whereIn('state', self::WAITING)
            ->whereNotNull('deadline_date')
            ->where('deadline_date', '<=', $horizon)
            ->orderBy('deadline_date')->orderBy('number')
            ->get();

        $due = [];
        $top = 0;
        foreach ($documents as $document) {
            $level = self::level($today, Clock::parse((string) $document->deadline_date));
            if ($level > (int) $document->deadline_alert) {
                $due[] = [$document, $level];
                $top = max($top, $level);
            }
        }
        if ($due === []) {
            return 0;
        }

        $lines = [];
        foreach ($due as [$document, $level]) {
            $remaining = WorkingDays::remaining($today, Clock::parse((string) $document->deadline_date));
            $when = match (true) {
                $remaining > 0 => Lang::get('alert_deadline_left', $remaining),
                $remaining === 0 => Lang::get('alert_deadline_last_day'),
                default => Lang::get('alert_deadline_late', -$remaining),
            };
            $lines[] = '- ' . Lang::get('alert_deadline_line', (string) $document->number, (int) $document->invoice_id, Lang::get('state_' . $document->state), (string) $document->deadline_date, $when)
                . $this->reason($document);
        }
        $subject = match (true) {
            $top > self::FIRST_ALERT_DAYS + 1 => 'alert_deadline_overdue_subject',
            $top === self::FIRST_ALERT_DAYS + 1 => 'alert_deadline_today_subject',
            default => 'alert_deadline_soon_subject',
        };
        AdminNotifier::send(Lang::get($subject, count($due)), Lang::get('alert_deadline_body', implode("\n", $lines), $this->link()));

        foreach ($due as [$document, $level]) {
            Capsule::table(Document::TABLE)->where('id', $document->id)->update(['deadline_alert' => $level]);
            Audit::log('deadline_alert', (string) $level, ['deadline' => $document->deadline_date], (int) $document->id, (int) $document->invoice_id);
        }

        return count($due);
    }

    /**
     * @param list<int>|null $only
     */
    private function processing(?array $only): int
    {
        $now = Clock::now();
        $documents = $this->select($only)
            ->where('state', Document::STATE_PROCESSING)
            ->where('uploaded_at', '<=', $now->modify('-' . self::PROCESSING_HOURS[1] . ' hours')->format('Y-m-d H:i:s'))
            ->orderBy('uploaded_at')
            ->get();

        $due = [];
        $hours = 0;
        foreach ($documents as $document) {
            $level = 0;
            foreach (self::PROCESSING_HOURS as $candidate => $limit) {
                if (Clock::parse((string) $document->uploaded_at) <= $now->modify('-' . $limit . ' hours')) {
                    $level = $candidate;
                }
            }
            if ($level > (int) $document->processing_alert) {
                $due[] = [$document, $level];
                $hours = max($hours, self::PROCESSING_HOURS[$level]);
            }
        }
        if ($due === []) {
            return 0;
        }

        $lines = array_map(static fn (array $entry): string => '- ' . Lang::get('alert_processing_line', (string) $entry[0]->number, (int) $entry[0]->invoice_id, (string) $entry[0]->upload_index, (string) $entry[0]->uploaded_at), $due);
        AdminNotifier::send(Lang::get('alert_processing_subject', $hours, count($due)), Lang::get('alert_processing_body', implode("\n", $lines), $this->link()));
        foreach ($due as [$document, $level]) {
            Capsule::table(Document::TABLE)->where('id', $document->id)->update(['processing_alert' => $level]);
            Audit::log('processing_alert', (string) $level, ['index' => $document->upload_index], (int) $document->id, (int) $document->invoice_id);
        }

        return count($due);
    }

    private function reason(object $document): string
    {
        $reason = match (true) {
            $document->state === Document::STATE_HELD && $document->review_reason !== null => Lang::get('review_' . $document->review_reason),
            $document->last_error !== null && $document->last_error !== '' => (string) $document->last_error,
            default => '',
        };

        return $reason === '' ? '' : ': ' . Text::cut(str_replace(["\r", "\n"], ' ', $reason), 200)[0];
    }

    private function link(): string
    {
        return AdminContext::adminUrl('addonmodules.php?module=' . Addon::MODULE);
    }

    /**
     * @param list<int>|null $only
     */
    private function select(?array $only): \Illuminate\Database\Query\Builder
    {
        $query = Capsule::table(Document::TABLE)->select(DocumentRepository::listColumns());
        if ($only !== null) {
            $query->whereIn('id', $only === [] ? [0] : $only);
        }

        return $query;
    }
}
