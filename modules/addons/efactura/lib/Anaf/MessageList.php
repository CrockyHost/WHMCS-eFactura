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

namespace WHMCS\Module\Addon\Efactura\Anaf;

use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * Reads the paged list of messages (listaMesajePaginatieFactura, 500 per
 * page) of a CUI between two moments, one window at a time, oldest first.
 *
 * A window with more than MAX_PAGES pages is halved until it fits (a window
 * of a minute is read whole), and after a window that fitted easily the next
 * one may be twice as long, so that a busy day does not leave the rest of the
 * interval to small windows. The time budget is checked before each window,
 * never inside one: a window begun is read to its end.
 */
final class MessageList
{
    /** Pages read from one window. */
    public const MAX_PAGES = 20;
    private const MIN_WINDOW_SECONDS = 60;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * @param string|null $filter E (errors), T (sent), P (received), R (buyer messages)
     * @param Closure(): bool $hasBudget whether there is time for another window
     * @param Closure(array<string, string>): void $message called for every message
     * @param (Closure(DateTimeImmutable): void)|null $windowRead called with the end of each window read in full
     * @return bool whether the whole interval was read
     * @throws RuntimeException when ANAF does not answer with a list
     */
    public function read(string $cui, DateTimeImmutable $start, DateTimeImmutable $end, ?string $filter, Closure $hasBudget, Closure $message, ?Closure $windowRead = null): bool
    {
        // ANAF takes whole seconds (as milliseconds).
        $start = self::seconds($start);
        $end = self::seconds($end);
        $span = max(1, $end->getTimestamp() - $start->getTimestamp());
        while ($start < $end && $hasBudget()) {
            // The first page tells how many pages the window has.
            do {
                $windowEnd = min($start->modify('+' . $span . ' seconds'), $end);
                $outcome = $this->page($cui, $start, $windowEnd, 1, $filter);
                $split = $outcome !== null && $outcome->pages > self::MAX_PAGES && $span > self::MIN_WINDOW_SECONDS;
                if ($split) {
                    $span = max(self::MIN_WINDOW_SECONDS, intdiv($span, 2));
                }
            } while ($split);

            $pages = $outcome?->pages ?? 0;
            $page = 1;
            while ($outcome !== null) {
                foreach ($outcome->messages as $item) {
                    $message($item);
                }
                $outcome = $page < $outcome->pages ? $this->page($cui, $start, $windowEnd, ++$page, $filter) : null;
            }
            if ($windowRead !== null) {
                $windowRead($windowEnd);
            }
            $start = $windowEnd;
            if ($pages <= intdiv(self::MAX_PAGES, 2)) {
                $span = max(1, min($span * 2, $end->getTimestamp() - $start->getTimestamp()));
            }
        }

        return $start >= $end;
    }

    private static function seconds(DateTimeImmutable $moment): DateTimeImmutable
    {
        // Through the timestamp: setTime() is ambiguous in the hour repeated in autumn.
        return (new DateTimeImmutable('@' . $moment->getTimestamp()))->setTimezone($moment->getTimezone());
    }

    /**
     * One page of the list; null when the window has no messages.
     */
    private function page(string $cui, DateTimeImmutable $start, DateTimeImmutable $end, int $page, ?string $filter): ?Outcome
    {
        $outcome = ResponseParser::messages($this->api->listMessagesPaged($cui, $start->getTimestamp() * 1000, $end->getTimestamp() * 1000, $page, $filter));
        if ($outcome->kind === Outcome::EMPTY) {
            return null;
        }
        if ($outcome->kind !== Outcome::MESSAGES) {
            throw new RuntimeException($outcome->message !== '' ? $outcome->message : 'listaMesajePaginatieFactura: ' . $outcome->kind);
        }

        return $outcome;
    }
}
