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

use DateTimeImmutable;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthException;
use WHMCS\Module\Addon\Efactura\Anaf\Outcome;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseParser;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Romania\Text;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * Resolves the uploads whose answer was lost (research report 04, 8.5).
 *
 * The sent invoices of the seller (listaMesajePaginatieFactura, filter T)
 * around the upload are compared by content: the ZIP of every message not
 * known locally is downloaded once and matched with the document by the
 * SHA-256 of the invoice XML (ANAF returns the bytes sent, verified on the
 * test environment) or by number, issue date, seller and total. When nothing
 * matches for some hours, the same
 * bytes are uploaded again; if the first upload did arrive, ANAF answers with
 * a duplicate that names the original index, which the worker follows.
 *
 * Rejected uploads (filter E) cannot be matched: the error file does not
 * contain the invoice number. The second upload then gets the same errors.
 */
final class Reconciler
{
    /** A "sending" document older than this was left by a crashed worker. */
    private const STUCK_MINUTES = 15;
    private const CHECK_EVERY_MINUTES = 30;
    /** Without a match, the same bytes go again after a timeout... */
    private const RESEND_AFTER_HOURS = 6;
    /**
     * ...or after an explicit "eroare tehnica" answer: on the test environment
     * (2026-10-09) such uploads never appeared in the message lists, and the
     * same bytes were accepted when sent again.
     */
    private const RESEND_AFTER_TECHNICAL_HOURS = 1;
    /** Margins against the ANAF clock: before the upload, and before now. */
    private const WINDOW_BEFORE_MINUTES = 5;
    private const WINDOW_END_SECONDS = 60;
    private const MAX_PAGES = 20;
    private const BATCH = 20;
    /** Messages already downloaded and their invoice key, by upload index. */
    private const SEEN = 'reconcile_seen';

    public function __construct(
        private readonly ApiClient $api,
        private readonly DocumentRepository $documents,
        private readonly RuntimeState $state,
    ) {
    }

    /**
     * @param list<int>|null $only
     * @return int documents matched or uploaded again
     */
    public function run(Worker $worker, ?array $only = null): int
    {
        $this->recoverStuck($only);
        $now = Clock::now();
        $query = Capsule::table(Document::TABLE)
            ->where('state', Document::STATE_UNKNOWN)
            ->where('next_attempt_at', '<=', $now->format('Y-m-d H:i:s'));
        if ($only !== null) {
            $query->whereIn('id', $only === [] ? [0] : $only);
        }
        $due = $query->orderBy('upload_started_at')->limit(self::BATCH)->get();
        if ($due->isEmpty()) {
            return 0;
        }

        $start = Clock::parse((string) $due->min('upload_started_at'))->modify('-' . self::WINDOW_BEFORE_MINUTES . ' minutes');
        try {
            $sent = $this->sentInvoices($start, $now);
        } catch (OAuthException | RuntimeException $e) {
            foreach ($due as $document) {
                $this->documents->update((int) $document->id, [
                    'last_error' => $e->getMessage(),
                    'next_attempt_at' => $now->modify('+' . self::CHECK_EVERY_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                ]);
            }

            return 0;
        }

        $done = 0;
        foreach ($due as $document) {
            $match = null;
            foreach ($sent as $index => $message) {
                if (self::matches($message, $document)) {
                    $match = $index;
                    break;
                }
            }
            if ($match !== null) {
                $this->adopt($document, (string) $match, $sent[$match]);
                unset($sent[$match]);
                $done++;
            } elseif (Clock::parse((string) $document->upload_started_at) <= $now->modify('-' . self::resendAfterHours($document) . ' hours')) {
                if (!$worker->canUpload()) {
                    break;
                }
                // The same frozen bytes; a duplicate answer leads to the original.
                $worker->upload($document);
                $done++;
            } else {
                $this->documents->update((int) $document->id, [
                    'next_attempt_at' => $now->modify('+' . self::CHECK_EVERY_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                ]);
            }
        }

        return $done;
    }

    private static function resendAfterHours(object $document): int
    {
        return str_contains(Text::fold((string) $document->last_error), 'eroare tehnica') ? self::RESEND_AFTER_TECHNICAL_HOURS : self::RESEND_AFTER_HOURS;
    }

    /**
     * The same bytes, or the same number, issue date, seller and total: an
     * invoice of other software that reused the number is not taken.
     *
     * @param array{number: string, date: string, seller: string, total?: string, sha256?: string} $message
     */
    private static function matches(array $message, object $document): bool
    {
        if (($message['sha256'] ?? '') !== '' && $message['sha256'] === $document->xml_sha256) {
            return true;
        }

        return $message['number'] === (string) $document->number
            && $message['date'] === (string) $document->issue_date
            && Cui::normalize($message['seller']) === Cui::normalize(Settings::string('company_cui'))
            && ($message['total'] ?? '') !== ''
            && Money::cents($message['total']) === Money::cents((string) $document->total);
    }

    /**
     * Documents left in "sending" by a worker that stopped mid-upload: the
     * request may have reached ANAF, so they are reconciled, not resent.
     *
     * @param list<int>|null $only
     */
    private function recoverStuck(?array $only): void
    {
        $limit = Clock::now()->modify('-' . self::STUCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $query = Capsule::table(Document::TABLE)
            ->where('state', Document::STATE_SENDING)
            ->where('upload_started_at', '<', $limit)
            ->where(static function ($query) use ($limit): void {
                $query->whereNull('lock_token')->orWhere('locked_at', '<', $limit);
            });
        if ($only !== null) {
            $query->whereIn('id', $only === [] ? [0] : $only);
        }
        foreach ($query->get() as $document) {
            $this->documents->transition($document, Document::STATE_UNKNOWN, [
                'lock_token' => null,
                'locked_at' => null,
                'next_attempt_at' => Clock::now()->format('Y-m-d H:i:s'),
            ], 'upload_unknown', 'The worker stopped during the upload.');
        }
    }

    /**
     * The invoices sent for the seller CUI since $start that are not linked to
     * a document yet, with the key read from their ZIP.
     *
     * @return array<string, array{number: string, date: string, seller: string, total: string, sha256: string, download_id: string, zip: string|null}>
     */
    private function sentInvoices(DateTimeImmutable $start, DateTimeImmutable $now): array
    {
        $cui = Cui::normalize(Settings::string('company_cui'));
        $start = max($start, $now->modify('-59 days'));
        $end = $now->modify('-' . self::WINDOW_END_SECONDS . ' seconds');
        if ($end <= $start) {
            return [];
        }

        $messages = [];
        $page = 1;
        do {
            $outcome = ResponseParser::messages($this->api->listMessagesPaged($cui, $start->getTimestamp() * 1000, $end->getTimestamp() * 1000, $page, 'T'));
            if ($outcome->kind === Outcome::EMPTY) {
                break;
            }
            if ($outcome->kind !== Outcome::MESSAGES) {
                throw new RuntimeException($outcome->message !== '' ? $outcome->message : 'listaMesajePaginatieFactura: ' . $outcome->kind);
            }
            foreach ($outcome->messages as $message) {
                if (($message['id_solicitare'] ?? '') !== '' && ($message['id'] ?? '') !== '') {
                    $messages[$message['id_solicitare']] = $message['id'];
                }
            }
        } while ($page++ < min($outcome->pages, self::MAX_PAGES));

        $known = array_merge(
            Capsule::table(Document::TABLE)->whereIn('upload_index', array_map('strval', array_keys($messages)))->pluck('upload_index')->all(),
            Capsule::table('mod_efactura_archive')->whereIn('upload_index', array_map('strval', array_keys($messages)))->pluck('upload_index')->all()
        );
        $seen = $this->seen($now);
        $sent = [];
        foreach ($messages as $index => $downloadId) {
            $index = (string) $index;
            if (in_array($index, $known, true)) {
                continue;
            }
            if (isset($seen[$index])) {
                $sent[$index] = $seen[$index] + ['download_id' => $downloadId, 'zip' => null];
                continue;
            }
            $download = ResponseParser::download($this->api->download($downloadId));
            if ($download->kind !== Outcome::ZIP) {
                continue;
            }
            try {
                $key = ResponseZip::read($download->body)->invoiceKey();
            } catch (RuntimeException) {
                continue;
            }
            if ($key === null) {
                continue;
            }
            $seen[$index] = $key + ['at' => $now->format('Y-m-d')];
            $sent[$index] = $key + ['download_id' => $downloadId, 'zip' => $download->body];
        }
        $this->state->set(self::SEEN, $seen);

        return $sent;
    }

    /**
     * @param array{number: string, date: string, seller: string, total: string, sha256: string, download_id: string, zip: string|null} $message
     */
    private function adopt(object $document, string $index, array $message): void
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        $adopted = $this->documents->transition($document, Document::STATE_VALIDATED, [
            'upload_index' => $index,
            'download_id' => $message['download_id'],
            'anaf_state' => 'ok',
            'uploaded_at' => $document->upload_started_at,
            'validated_at' => $now,
            'next_attempt_at' => $now,
            'last_error' => null,
        ], 'reconciled', $index);
        if (!$adopted) {
            return;
        }
        $this->documents->archive($document, 'xml_sent', $document->number . '.xml', 'application/xml', (string) $document->xml, $index);
        if ($message['zip'] !== null) {
            $archiveId = $this->documents->archive($document, 'anaf_zip', $message['download_id'] . '.zip', 'application/zip', $message['zip'], $index, $message['download_id']);
            $this->documents->update((int) $document->id, ['archive_id' => $archiveId, 'next_attempt_at' => null]);
        }
        // Otherwise the worker downloads the ZIP with the other answers.
    }

    /**
     * @return array<string, array{number: string, date: string, seller: string, total: string, sha256: string, at: string}>
     */
    private function seen(DateTimeImmutable $now): array
    {
        $oldest = $now->modify('-60 days')->format('Y-m-d');
        $seen = $this->state->get(self::SEEN, []);

        return array_filter(is_array($seen) ? $seen : [], static fn ($entry): bool => is_array($entry) && ($entry['at'] ?? '') >= $oldest);
    }
}
