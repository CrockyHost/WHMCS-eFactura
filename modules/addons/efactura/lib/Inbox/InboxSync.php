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

namespace WHMCS\Module\Addon\Efactura\Inbox;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\Outcome;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseParser;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;

/**
 * The SPV inbox of the seller CUI: every message of the paged list
 * (listaMesajePaginatieFactura) is recorded once. Invoices received from
 * suppliers and messages from buyers are downloaded and archived at once
 * (ANAF keeps them 60 days); the messages about the addon's own uploads are
 * linked to their documents. A new message from a buyer is e-mailed to the
 * administrators.
 */
final class InboxSync
{
    public const TABLE = 'mod_efactura_messages';
    public const RECEIVED = 'received';
    public const BUYER = 'buyer';
    public const SENT = 'sent';
    public const ERRORS = 'errors';
    public const OTHER = 'other';

    private const LOCK = 'inbox';
    private const UNTIL = 'inbox_synced_until';
    private const LAST_RUN = 'inbox_last_run';
    /** The cron syncs at most this often. */
    private const EVERY_MINUTES = 30;
    /** The first sync reads this far back (ANAF keeps messages 60 days). */
    private const FIRST_DAYS = 59;
    private const WINDOW_BEFORE_MINUTES = 5;
    private const WINDOW_END_SECONDS = 60;
    private const MAX_PAGES = 20;
    private const MAX_DOWNLOADS = 30;
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly ApiClient $api,
        private readonly RuntimeState $state,
    ) {
    }

    /**
     * The kind of an ANAF message type ("tip").
     */
    public static function kind(string $type): string
    {
        $type = strtoupper(trim($type));

        return match (true) {
            $type === 'FACTURA PRIMITA' => self::RECEIVED,
            str_starts_with($type, 'MESAJ CUMPARATOR') => self::BUYER,
            $type === 'FACTURA TRIMISA' => self::SENT,
            $type === 'ERORI FACTURA' => self::ERRORS,
            default => self::OTHER,
        };
    }

    public function due(): bool
    {
        $last = (string) $this->state->get(self::LAST_RUN, '');

        return $last === '' || Clock::parse($last) <= Clock::now()->modify('-' . self::EVERY_MINUTES . ' minutes');
    }

    public function lastRun(): string
    {
        return (string) $this->state->get(self::LAST_RUN, '');
    }

    /**
     * Reads the new messages and downloads what is due.
     *
     * @param Closure(): bool|null $hasBudget whether there is still time for another call
     * @return array{listed: int, new: int, downloaded: int, error: string}
     */
    public function run(?Closure $hasBudget = null): array
    {
        $report = ['listed' => 0, 'new' => 0, 'downloaded' => 0, 'error' => ''];
        $cui = Cui::normalize(Settings::string('company_cui'));
        if ($cui === '') {
            return $report;
        }
        if (!Lock::acquire(self::LOCK, 0)) {
            return ['error' => 'busy'] + $report;
        }
        try {
            $now = Clock::now();
            $this->state->set(self::LAST_RUN, $now->format('Y-m-d H:i:s'));
            try {
                [$report['listed'], $report['new']] = $this->list($cui, $now);
            } catch (RuntimeException $e) {
                $report['error'] = $e->getMessage();
            }
            $report['downloaded'] = $this->download($hasBudget ?? static fn (): bool => true);
        } finally {
            Lock::release(self::LOCK);
        }

        return $report;
    }

    /**
     * @return array{0: int, 1: int} messages listed, new ones
     */
    private function list(string $cui, DateTimeImmutable $now): array
    {
        $until = (string) $this->state->get(self::UNTIL, '');
        $start = $until !== '' ? Clock::parse($until)->modify('-' . self::WINDOW_BEFORE_MINUTES . ' minutes') : $now->modify('-' . self::FIRST_DAYS . ' days');
        $start = max($start, $now->modify('-' . self::FIRST_DAYS . ' days'));
        $end = $now->modify('-' . self::WINDOW_END_SECONDS . ' seconds');
        if ($end <= $start) {
            return [0, 0];
        }

        $listed = 0;
        $new = 0;
        $page = 1;
        do {
            $outcome = ResponseParser::messages($this->api->listMessagesPaged($cui, $start->getTimestamp() * 1000, $end->getTimestamp() * 1000, $page));
            if ($outcome->kind === Outcome::EMPTY) {
                break;
            }
            if ($outcome->kind !== Outcome::MESSAGES) {
                throw new RuntimeException($outcome->message !== '' ? $outcome->message : 'listaMesajePaginatieFactura: ' . $outcome->kind);
            }
            foreach ($outcome->messages as $message) {
                $listed++;
                $new += $this->record($message) ? 1 : 0;
            }
        } while ($page++ < min($outcome->pages, self::MAX_PAGES));
        $this->state->set(self::UNTIL, $end->format('Y-m-d H:i:s'));

        return [$listed, $new];
    }

    /**
     * Records a message of the list once; returns whether it was new.
     *
     * @param array<string, string> $message
     */
    private function record(array $message): bool
    {
        $anafId = (string) ($message['id'] ?? '');
        if ($anafId === '') {
            return false;
        }
        $environment = $this->api->environment();
        if (Capsule::table(self::TABLE)->where('environment', $environment)->where('anaf_id', $anafId)->exists()) {
            return false;
        }
        $type = (string) ($message['tip'] ?? '');
        $kind = self::kind($type);
        $details = (string) ($message['detalii'] ?? '');
        $requestId = (string) ($message['id_solicitare'] ?? '');
        $created = (string) ($message['data_creare'] ?? '');
        $issuer = preg_match('/\bcif_emitent=(\d+)/', $details, $match) === 1 ? $match[1] : null;
        $now = Clock::now()->format('Y-m-d H:i:s');

        return Capsule::table(self::TABLE)->insertOrIgnore([
            'environment' => $environment,
            'anaf_id' => $anafId,
            'request_id' => $requestId !== '' ? $requestId : null,
            'cif' => (string) ($message['cif'] ?? '') ?: null,
            'type' => $type,
            'kind' => $kind,
            // The messages about the addon's own uploads.
            'document_id' => in_array($kind, [self::SENT, self::ERRORS], true) && $requestId !== '' ? self::documentByIndex($requestId) : null,
            'details' => $details,
            'anaf_created_at' => preg_match('/^\d{12}$/', $created) === 1 ? DateTimeImmutable::createFromFormat('YmdHi', $created)->format('Y-m-d H:i:00') : null,
            'issuer_cif' => $issuer,
            'raw' => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Downloads the invoices received and the buyer messages not archived
     * yet, oldest first.
     *
     * @param Closure(): bool $hasBudget
     */
    private function download(Closure $hasBudget): int
    {
        $pending = Capsule::table(self::TABLE)
            ->where('environment', $this->api->environment())
            ->whereIn('kind', [self::RECEIVED, self::BUYER])
            ->whereNull('archive_id')
            ->where('download_attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit(self::MAX_DOWNLOADS)
            ->get(['id', 'anaf_id', 'kind', 'request_id', 'download_attempts']);
        $done = 0;
        foreach ($pending as $message) {
            if (!$hasBudget()) {
                break;
            }
            $outcome = ResponseParser::download($this->api->download((string) $message->anaf_id));
            if ($outcome->kind === Outcome::LIMIT) {
                break;
            }
            if ($outcome->kind !== Outcome::ZIP) {
                Capsule::table(self::TABLE)->where('id', $message->id)->update([
                    'download_attempts' => (int) $message->download_attempts + 1,
                    'last_error' => $outcome->message,
                    // Gone for good: ANAF keeps the files 60 days.
                    'archive_id' => $outcome->is(Outcome::EXPIRED, Outcome::FATAL) ? 0 : null,
                    'updated_at' => Clock::now()->format('Y-m-d H:i:s'),
                ]);
                continue;
            }
            try {
                $this->store($message, $outcome->body);
                $done++;
            } catch (Throwable $e) {
                Capsule::table(self::TABLE)->where('id', $message->id)->update([
                    'download_attempts' => (int) $message->download_attempts + 1,
                    'last_error' => $e->getMessage(),
                    'updated_at' => Clock::now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        return $done;
    }

    private function store(object $message, string $zip): void
    {
        $read = ResponseZip::read($zip);
        $now = Clock::now()->format('Y-m-d H:i:s');
        $archiveId = (int) Capsule::table('mod_efactura_archive')->insertGetId([
            'message_id' => $message->id,
            'kind' => 'inbox_zip',
            'environment' => $this->api->environment(),
            'upload_index' => $message->request_id,
            'download_id' => $message->anaf_id,
            'filename' => $message->anaf_id . '.zip',
            'mime' => 'application/zip',
            'size' => strlen($zip),
            'sha256' => hash('sha256', $zip),
            'content' => $zip,
            'created_at' => $now,
        ]);
        $fields = ['archive_id' => $archiveId, 'last_error' => null, 'updated_at' => $now];

        if ($message->kind === self::RECEIVED) {
            $invoice = InvoiceReader::read((string) $read->invoiceXml);
            if ($invoice !== null) {
                $fields += [
                    'issuer_cif' => Cui::normalize($invoice['supplier']['cui']) ?: null,
                    'issuer_name' => mb_substr($invoice['supplier']['name'], 0, 255),
                    'invoice_number' => mb_substr($invoice['number'], 0, 64),
                    'invoice_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice['date']) === 1 ? $invoice['date'] : null,
                    'currency' => strlen($invoice['currency']) === 3 ? $invoice['currency'] : null,
                    'total' => is_numeric($invoice['total']) ? Money::format(Money::cents($invoice['total'])) : null,
                ];
            }
        } else {
            // A buyer message: its text and the invoice of the addon it is about.
            $answer = InvoiceReader::buyerMessage((string) ($read->errorsXml ?? $read->invoiceXml));
            if ($answer !== null) {
                $fields += [
                    'message_text' => $answer['message'],
                    'document_id' => $answer['index'] !== '' ? self::documentByIndex($answer['index']) : null,
                ];
            }
        }
        Capsule::table(self::TABLE)->where('id', $message->id)->update($fields);

        if ($message->kind === self::BUYER) {
            $this->alertBuyerMessage((int) $message->id);
        }
    }

    private function alertBuyerMessage(int $messageId): void
    {
        $message = Capsule::table(self::TABLE)->where('id', $messageId)->first();
        if ($message === null || $message->alerted_at !== null) {
            return;
        }
        $document = $message->document_id !== null ? Capsule::table(Document::TABLE)->where('id', $message->document_id)->first(['number', 'invoice_id']) : null;
        try {
            AdminNotifier::send(
                Lang::get('alert_buyer_message_subject', $document !== null ? (string) $document->number : (string) $message->request_id),
                Lang::get('alert_buyer_message_body', $document !== null ? (string) $document->number : '-', (string) $message->message_text,
                    AdminContext::adminUrl('addonmodules.php?module=efactura&view=message&id=' . $messageId))
            );
        } catch (Throwable) {
            // An alert never stops the inbox.
        }
        Capsule::table(self::TABLE)->where('id', $messageId)->update(['alerted_at' => Clock::now()->format('Y-m-d H:i:s')]);
    }

    private static function documentByIndex(string $index): ?int
    {
        $id = Capsule::table(Document::TABLE)->where('upload_index', $index)->value('id')
            ?? Capsule::table('mod_efactura_archive')->where('upload_index', $index)->whereNotNull('document_id')->value('document_id');

        return $id !== null ? (int) $id : null;
    }
}
