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

namespace WHMCS\Module\Addon\Efactura\Anaf\OAuth;

use Closure;

/**
 * Daily care of the ANAF connection: refreshes the access token before it
 * expires and warns the admins as the yearly re-authorization with the
 * qualified certificate approaches (each threshold once).
 */
final class ConnectionMonitor
{
    /** Days before the re-authorization deadline at which admins are alerted. */
    public const ALERT_DAYS = [30, 14, 7, 3, 1, 0];

    /**
     * @param Closure(int $threshold, int $daysLeft, array<string, mixed> $status): void $notify
     */
    public function __construct(private readonly Connection $connection, private readonly Closure $notify)
    {
    }

    public function run(): void
    {
        $status = $this->connection->status();
        if (!$status['connected']) {
            return;
        }

        if (!$status['needs_reauthorization']) {
            try {
                $this->connection->refresh();
            } catch (OAuthException) {
                // Connection already recorded the error and, when needed,
                // flagged the re-authorization; the alert below reports it.
            }
            $status = $this->connection->status();
        }

        $daysLeft = $status['needs_reauthorization'] ? 0 : $status['days_left'];
        if ($daysLeft === null) {
            return;
        }
        $threshold = self::threshold($daysLeft);
        $sent = $this->connection->alertDaysSent();
        if ($threshold === null || ($sent !== null && $sent <= $threshold)) {
            return;
        }

        ($this->notify)($threshold, $daysLeft, $status);
        $this->connection->markAlertSent($threshold);
    }

    /**
     * The alert threshold reached with $daysLeft days left, or null when
     * none is reached yet.
     */
    public static function threshold(int $daysLeft): ?int
    {
        if ($daysLeft <= 0) {
            return 0;
        }
        $reached = null;
        foreach (self::ALERT_DAYS as $days) {
            if ($daysLeft <= $days) {
                $reached = $days;
            }
        }

        return $reached;
    }
}
