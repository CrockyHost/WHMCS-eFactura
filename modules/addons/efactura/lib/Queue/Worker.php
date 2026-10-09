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

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Anaf\Outcome;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseParser;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Ubl\BuyerMapper;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;

/**
 * The queue worker, run by the WHMCS cron (AfterCronJob) or cron/worker.php.
 *
 * Research report 04, section 8: the XML is generated when the document is
 * due and frozen (bytes and SHA-256) before the upload; the state is written
 * before every network call; an upload whose outcome is unknown is never
 * repeated blindly but reconciled; HTTP 200 answers are classified by
 * content; the signed ZIP is downloaded and archived at once (ANAF keeps it
 * 60 days); the daily ANAF limits are respected per message.
 */
final class Worker
{
    private const LOCK = 'worker';
    private const BATCH = 50;
    /** Under the ANAF limits of 100 status checks and 10 downloads per message per day. */
    private const STATUS_CHECKS_PER_DAY = 90;
    private const DOWNLOADS_PER_DAY = 8;
    /** Consecutive transient failures that pause the queue. */
    private const BREAKER_THRESHOLD = 5;
    private const INVALID_RETRY_MINUTES = 15;
    private const UNKNOWN_FIRST_CHECK_MINUTES = 20;

    /** @var array<string, int> */
    private array $report = [];
    private float $deadline = 0.0;
    private Closure $pause;

    public function __construct(
        private readonly ApiClient $api,
        private readonly Connection $connection,
        private readonly DocumentBuilder $builder,
        private readonly DocumentRepository $documents,
        private readonly RuntimeState $state,
        private readonly ?Reconciler $reconciler = null,
        private readonly ?DeadlineMonitor $deadlines = null,
        ?Closure $pause = null,
    ) {
        // At most one upload per second.
        $this->pause = $pause ?? static function (): void {
            sleep(1);
        };
    }

    /**
     * @param list<int>|null $only process only these documents
     * @return array<string, int> what was done
     */
    public function run(int $budgetSeconds = 50, ?array $only = null): array
    {
        $this->report = ['uploaded' => 0, 'invalid' => 0, 'validated' => 0, 'rejected' => 0, 'archived' => 0, 'unknown' => 0, 'retry' => 0, 'reconciled' => 0];
        if (!Settings::bool('enabled')) {
            return ['disabled' => 1];
        }
        if (!Lock::acquire(self::LOCK, 0)) {
            return ['busy' => 1];
        }
        $this->deadline = microtime(true) + $budgetSeconds;
        try {
            if ($this->apiUsable()) {
                $this->sendDue($only);
                $this->checkStatus($only);
                $this->downloadAnswers($only);
                if ($this->reconciler !== null && $this->timeLeft()) {
                    $this->report['reconciled'] += $this->reconciler->run($this, $only);
                }
            }
            $this->deadlines?->run($only);
            $this->state->set('worker_last_run', Clock::now()->format('Y-m-d H:i:s'));
        } finally {
            Lock::release(self::LOCK);
        }

        return $this->report;
    }

    /**
     * Uploads one claimed document. Public for the reconciler, which sends
     * the same bytes again when an unknown upload cannot be matched.
     */
    public function upload(object $document): void
    {
        $document = $this->documents->claim((int) $document->id, [Document::STATE_SCHEDULED, Document::STATE_RETRY, Document::STATE_INVALID, Document::STATE_UNKNOWN]);
        if ($document === null) {
            return;
        }
        try {
            if ($document->xml === null) {
                $document = $this->generate($document);
                if ($document === null) {
                    return;
                }
            }

            $now = Clock::now();
            $this->documents->transition($document, Document::STATE_SENDING, [
                'attempts' => (int) $document->attempts + 1,
                'upload_started_at' => $now->format('Y-m-d H:i:s'),
                'environment' => $this->api->environment(),
            ], 'upload_started', (string) $document->xml_sha256);
            $document = $this->documents->find((int) $document->id);

            try {
                $outcome = ResponseParser::upload($this->api->upload(
                    (string) $document->xml,
                    $document->upload_endpoint === 'uploadb2c',
                    Cui::normalize(Settings::string('company_cui')),
                    (bool) $document->upload_extern
                ));
            } catch (OAuthException $e) {
                // No token: the request was not sent.
                $outcome = new Outcome($e->reauthorize ? Outcome::AUTH : Outcome::RETRY, $e->getMessage());
            } catch (Throwable $e) {
                // Whether the request left is not known.
                $outcome = new Outcome(Outcome::UNKNOWN, $e->getMessage());
            }
            $this->afterUpload($document, $outcome);
            ($this->pause)();
        } finally {
            $this->documents->releaseClaim((int) $document->id);
        }
    }

    /**
     * Whether another upload may start in this run.
     */
    public function canUpload(): bool
    {
        return $this->timeLeft() && !$this->breakerOpen() && !$this->authPaused();
    }

    /**
     * Builds and freezes the XML. Returns the updated row, or null when the
     * data is not valid (the document becomes "invalid").
     */
    private function generate(object $document): ?object
    {
        $result = $this->builder->build($document);
        $now = Clock::now();
        if (!$result->ok()) {
            $errors = array_map(static fn (array $issue): array => ['source' => 'local', 'rule' => $issue['rule'], 'message' => $issue['message']], $result->issues);
            $this->documents->transition($document, Document::STATE_INVALID, [
                'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'last_error' => $errors[0]['message'] ?? null,
                'next_attempt_at' => $now->modify('+' . self::INVALID_RETRY_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
            ], 'invalid', (string) ($errors[0]['message'] ?? ''), ['rules' => array_column($errors, 'rule')]);
            $this->report['invalid']++;
            if ($document->alerted_state !== Document::STATE_INVALID) {
                $this->alert('alert_invalid', $document, implode("\n", array_column($errors, 'message')));
                $this->documents->update((int) $document->id, ['alerted_state' => Document::STATE_INVALID]);
            }

            return null;
        }

        $xml = (string) $result->xml;
        $this->documents->update((int) $document->id, [
            'xml' => $xml,
            'xml_sha256' => hash('sha256', $xml),
            'xml_generated_at' => $now->format('Y-m-d H:i:s'),
            'buyer_type' => $result->buyerType,
            'upload_endpoint' => $result->buyerType === BuyerMapper::TYPE_B2C ? 'uploadb2c' : 'upload',
            'upload_extern' => $result->invoice !== null && $result->invoice->buyer->country !== 'RO' ? 1 : 0,
            'exchange_rate' => $result->exchangeRate?->rate,
            'exchange_rate_date' => $result->exchangeRate?->date->format('Y-m-d'),
            'exchange_rate_source' => $result->exchangeRate?->source,
            'errors' => null,
        ]);

        return $this->documents->find((int) $document->id);
    }

    private function afterUpload(object $document, Outcome $outcome): void
    {
        $now = Clock::now();
        switch ($outcome->kind) {
            case Outcome::ACCEPTED:
                $this->documents->transition($document, Document::STATE_PROCESSING, [
                    'upload_index' => $outcome->index,
                    'uploaded_at' => $now->format('Y-m-d H:i:s'),
                    'next_attempt_at' => $now->modify('+1 minute')->format('Y-m-d H:i:s'),
                    'status_checks' => 0,
                    'downloads' => 0,
                    'counters_date' => $now->format('Y-m-d'),
                    'last_error' => null,
                ], 'uploaded', (string) $outcome->index);
                $this->documents->archive($document, 'xml_sent', $document->number . '.xml', 'application/xml', (string) $document->xml, $outcome->index);
                $this->breaker(true);
                $this->report['uploaded']++;
                break;

            case Outcome::REFUSED:
                $this->reject($document, [$outcome->message], 'upload');
                break;

            case Outcome::UNKNOWN:
                $this->documents->transition($document, Document::STATE_UNKNOWN, [
                    'last_error' => $outcome->message,
                    'next_attempt_at' => $now->modify('+' . self::UNKNOWN_FIRST_CHECK_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                ], 'upload_unknown', $outcome->message);
                $this->breaker(false);
                $this->report['unknown']++;
                break;

            case Outcome::AUTH:
                $this->pauseForAuthorization($outcome->message);
                $this->retryLater($document, $outcome->message, $now->modify('+1 hour'));
                break;

            case Outcome::LIMIT:
                $this->retryLater($document, $outcome->message, Schedule::tomorrow($now));
                break;

            default:
                $this->breaker(false);
                $this->retryLater($document, $outcome->message, Schedule::backoff((int) $document->attempts, $now));
        }
    }

    private function retryLater(object $document, string $message, DateTimeImmutable $at): void
    {
        $this->documents->transition($document, Document::STATE_RETRY, [
            'last_error' => $message,
            'next_attempt_at' => $at->format('Y-m-d H:i:s'),
        ], 'retry', $message);
        $this->report['retry']++;
    }

    /**
     * Uploads the documents that are due, oldest deadline first.
     *
     * @param list<int>|null $only
     */
    private function sendDue(?array $only): void
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        $due = $this->select($only)
            ->where(static function ($query) use ($now): void {
                $query->where(static function ($query) use ($now): void {
                    $query->where('state', Document::STATE_SCHEDULED)->where('send_after', '<=', $now);
                })->orWhere(static function ($query) use ($now): void {
                    $query->whereIn('state', [Document::STATE_RETRY, Document::STATE_INVALID])->where('next_attempt_at', '<=', $now);
                });
            })
            ->orderBy('deadline_date')->orderBy('send_after')->orderBy('id')
            ->limit(self::BATCH)
            ->get();
        foreach ($due as $document) {
            if (!$this->canUpload()) {
                return;
            }
            $this->upload($document);
        }
    }

    /**
     * @param list<int>|null $only
     */
    private function checkStatus(?array $only): void
    {
        $due = $this->select($only)
            ->where('state', Document::STATE_PROCESSING)
            ->where('next_attempt_at', '<=', Clock::now()->format('Y-m-d H:i:s'))
            ->orderBy('next_attempt_at')
            ->limit(self::BATCH)
            ->get();
        foreach ($due as $document) {
            if (!$this->canUpload()) {
                return;
            }
            if (!$this->guarded(fn () => $this->checkOne($document), $document)) {
                return;
            }
        }
    }

    /**
     * Runs one status check or download; a token problem stops the phase,
     * any other error is recorded on the document and the queue goes on.
     */
    private function guarded(Closure $step, object $document): bool
    {
        try {
            $step();
        } catch (OAuthException $e) {
            $this->pauseForAuthorization($e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->documents->update((int) $document->id, [
                'last_error' => get_class($e) . ': ' . $e->getMessage(),
                'next_attempt_at' => Clock::now()->modify('+15 minutes')->format('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }

    private function checkOne(object $document): void
    {
        $now = Clock::now();
        if (!$this->countCall($document, 'status_checks', self::STATUS_CHECKS_PER_DAY)) {
            $this->documents->update((int) $document->id, ['next_attempt_at' => Schedule::tomorrow($now)->format('Y-m-d H:i:s')]);

            return;
        }
        $outcome = ResponseParser::state($this->api->messageState((string) $document->upload_index));
        $uploadedAt = Clock::parse((string) $document->uploaded_at);
        switch ($outcome->kind) {
            case Outcome::OK:
                $this->documents->transition($document, Document::STATE_VALIDATED, [
                    'anaf_state' => 'ok',
                    'download_id' => $outcome->downloadId,
                    'validated_at' => $now->format('Y-m-d H:i:s'),
                    'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                    'last_error' => null,
                ], 'validated', (string) $outcome->downloadId);
                $this->breaker(true);
                $this->report['validated']++;
                $this->downloadOne($this->documents->find((int) $document->id));
                break;

            case Outcome::NOK:
                $this->documents->transition($document, Document::STATE_REJECTED, [
                    'anaf_state' => 'nok',
                    'download_id' => $outcome->downloadId,
                    'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                ], 'rejected', (string) $outcome->downloadId);
                $this->breaker(true);
                // The errors (or a duplicate) are in the ZIP.
                $this->downloadOne($this->documents->find((int) $document->id));
                break;

            case Outcome::PROCESSING:
                $this->documents->update((int) $document->id, [
                    'anaf_state' => 'in prelucrare',
                    'next_attempt_at' => Schedule::nextStatusCheck($uploadedAt, $now)->format('Y-m-d H:i:s'),
                ]);
                $this->breaker(true);
                break;

            case Outcome::XML_ERRORS:
                $this->reject($document, [$outcome->message], 'anaf');
                break;

            case Outcome::LIMIT:
                $this->documents->update((int) $document->id, ['next_attempt_at' => Schedule::tomorrow($now)->format('Y-m-d H:i:s')]);
                break;

            case Outcome::AUTH:
                $this->pauseForAuthorization($outcome->message);
                $this->documents->update((int) $document->id, ['last_error' => $outcome->message, 'next_attempt_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s')]);
                break;

            case Outcome::FATAL:
                // Typically the index belongs to the other ANAF environment.
                $this->reject($document, [$outcome->message], 'anaf');
                break;

            default:
                $this->breaker(false);
                $this->documents->update((int) $document->id, [
                    'last_error' => $outcome->message,
                    'next_attempt_at' => max(Schedule::nextStatusCheck($uploadedAt, $now), $now->modify('+5 minutes'))->format('Y-m-d H:i:s'),
                ]);
        }
    }

    /**
     * @param list<int>|null $only
     */
    private function downloadAnswers(?array $only): void
    {
        $due = $this->select($only)
            ->whereIn('state', [Document::STATE_VALIDATED, Document::STATE_REJECTED])
            ->whereNotNull('download_id')
            ->whereNull('archive_id')
            ->where(static function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Clock::now()->format('Y-m-d H:i:s'));
            })
            ->limit(self::BATCH)
            ->get();
        foreach ($due as $document) {
            if (!$this->canUpload()) {
                return;
            }
            if (!$this->guarded(fn () => $this->downloadOne($document), $document)) {
                return;
            }
        }
    }

    /**
     * Downloads and archives the ZIP of a validated or rejected document.
     */
    public function downloadOne(?object $document): void
    {
        if ($document === null || $document->download_id === null || $document->archive_id !== null) {
            return;
        }
        $now = Clock::now();
        if (!$this->countCall($document, 'downloads', self::DOWNLOADS_PER_DAY)) {
            $this->documents->update((int) $document->id, ['next_attempt_at' => Schedule::tomorrow($now)->format('Y-m-d H:i:s')]);

            return;
        }
        $outcome = ResponseParser::download($this->api->download((string) $document->download_id));
        if ($outcome->kind !== Outcome::ZIP) {
            $next = match ($outcome->kind) {
                Outcome::LIMIT => Schedule::tomorrow($now),
                Outcome::AUTH => $now->modify('+1 hour'),
                default => $now->modify('+10 minutes'),
            };
            if ($outcome->kind === Outcome::AUTH) {
                $this->pauseForAuthorization($outcome->message);
            }
            $fields = ['last_error' => $outcome->message, 'next_attempt_at' => $next->format('Y-m-d H:i:s')];
            if ($outcome->is(Outcome::EXPIRED, Outcome::FATAL)) {
                // Definitive: the verdict is known but the file is gone.
                $fields['next_attempt_at'] = null;
                $fields['archive_id'] = 0;
                $this->alert('alert_archive_lost', $document, $outcome->message);
            }
            $this->documents->update((int) $document->id, $fields);

            return;
        }

        try {
            $zip = ResponseZip::read($outcome->body);
        } catch (RuntimeException $e) {
            $this->documents->update((int) $document->id, ['last_error' => $e->getMessage(), 'next_attempt_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s')]);

            return;
        }
        $archiveId = $this->documents->archive($document, $zip->isInvoice() ? 'anaf_zip' : 'anaf_errors_zip', $document->download_id . '.zip', 'application/zip', $outcome->body, $document->upload_index, $document->download_id);

        if ($zip->isInvoice()) {
            $this->documents->update((int) $document->id, ['archive_id' => $archiveId, 'next_attempt_at' => null, 'last_error' => null]);
            $this->report['archived']++;

            return;
        }

        if ($zip->duplicateIndex !== null && $zip->duplicateIndex !== $document->upload_index) {
            // Sent before: follow the original upload, which carries the verdict.
            $this->documents->transition($document, Document::STATE_PROCESSING, [
                'upload_index' => $zip->duplicateIndex,
                'download_id' => null,
                'anaf_state' => null,
                'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                'status_checks' => 0,
                'downloads' => 0,
            ], 'duplicate', $zip->duplicateIndex, ['rejected_index' => $document->upload_index, 'date' => $zip->duplicateDate]);

            return;
        }

        $this->documents->update((int) $document->id, ['archive_id' => $archiveId, 'next_attempt_at' => null]);
        $this->reject($this->documents->find((int) $document->id), $zip->errors, 'anaf');
    }

    /**
     * @param list<string> $messages
     */
    private function reject(object $document, array $messages, string $source): void
    {
        $errors = array_map(static fn (string $message): array => ['source' => $source, 'rule' => '', 'message' => $message], array_values(array_filter($messages, static fn (string $m): bool => $m !== '')));
        $fields = [
            'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'last_error' => $errors[0]['message'] ?? null,
        ];
        if ($document->state === Document::STATE_REJECTED) {
            $this->documents->update((int) $document->id, $fields);
        } else {
            $this->documents->transition($document, Document::STATE_REJECTED, $fields + ['next_attempt_at' => null], 'rejected', (string) ($errors[0]['message'] ?? ''));
        }
        $this->report['rejected']++;
        if ($document->alerted_state !== Document::STATE_REJECTED) {
            $this->alert('alert_rejected', $document, implode("\n", array_column($errors, 'message')));
            $this->documents->update((int) $document->id, ['alerted_state' => Document::STATE_REJECTED]);
        }
    }

    /**
     * Counts a call against the daily ANAF limit of a message.
     */
    private function countCall(object $document, string $counter, int $max): bool
    {
        $today = Clock::today()->format('Y-m-d');
        $count = $document->counters_date === $today ? (int) $document->{$counter} : 0;
        if ($count >= $max) {
            return false;
        }
        $fields = [$counter => $count + 1, 'counters_date' => $today];
        if ($document->counters_date !== $today) {
            $fields += ['status_checks' => 0, 'downloads' => 0];
            $fields[$counter] = 1;
        }
        $this->documents->update((int) $document->id, $fields);

        return true;
    }

    private function apiUsable(): bool
    {
        $status = $this->connection->status();
        if (!$status['connected'] || $status['needs_reauthorization']) {
            return false;
        }
        try {
            $this->connection->accessToken();
        } catch (OAuthException) {
            return false;
        }

        return !$this->breakerOpen();
    }

    /**
     * Circuit breaker: after BREAKER_THRESHOLD transient failures in a row,
     * the queue pauses 15 minutes, doubling up to 2 hours.
     */
    private function breaker(bool $success): void
    {
        if ($success) {
            if ((int) $this->state->get('breaker_failures', 0) !== 0) {
                $this->state->set('breaker_failures', 0);
                $this->state->set('breaker_level', 0);
            }

            return;
        }
        $failures = (int) $this->state->get('breaker_failures', 0) + 1;
        $this->state->set('breaker_failures', $failures);
        if ($failures >= self::BREAKER_THRESHOLD) {
            $level = min(3, (int) $this->state->get('breaker_level', 0));
            $this->state->set('breaker_until', Clock::now()->modify('+' . (15 * (2 ** $level)) . ' minutes')->format('Y-m-d H:i:s'));
            $this->state->set('breaker_level', $level + 1);
            $this->state->set('breaker_failures', 0);
        }
    }

    private function breakerOpen(): bool
    {
        $until = (string) $this->state->get('breaker_until', '');

        return $until !== '' && Clock::parse($until) > Clock::now();
    }

    private function pauseForAuthorization(string $message): void
    {
        $now = Clock::now();
        $this->state->set('auth_paused_until', $now->modify('+1 hour')->format('Y-m-d H:i:s'));
        if ($this->state->get('auth_alerted_on') !== $now->format('Y-m-d')) {
            $this->state->set('auth_alerted_on', $now->format('Y-m-d'));
            AdminNotifier::send(
                Lang::get('alert_auth_subject'),
                Lang::get('alert_auth_body', $message, AdminContext::adminUrl('addonmodules.php?module=efactura&view=anaf'))
            );
        }
    }

    private function authPaused(): bool
    {
        $until = (string) $this->state->get('auth_paused_until', '');

        return $until !== '' && Clock::parse($until) > Clock::now();
    }

    private function alert(string $key, object $document, string $details): void
    {
        try {
            AdminNotifier::send(
                Lang::get($key . '_subject', (string) $document->number),
                Lang::get($key . '_body', (string) $document->number, (int) $document->invoice_id, $details, AdminContext::adminUrl('invoices.php?action=edit&id=' . (int) $document->invoice_id))
            );
        } catch (Throwable) {
            // An alert never stops the queue.
        }
    }

    /**
     * @param list<int>|null $only
     */
    private function select(?array $only): \Illuminate\Database\Query\Builder
    {
        $query = Capsule::table(Document::TABLE);
        if ($only !== null) {
            $query->whereIn('id', $only === [] ? [0] : $only);
        }

        return $query;
    }

    private function timeLeft(): bool
    {
        return microtime(true) < $this->deadline;
    }
}
