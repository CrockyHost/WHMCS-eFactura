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

/**
 * When the worker tries again (research report 04, 8.4): status checks soon
 * after the upload, then hourly, then every 3 hours, well under the 100
 * checks per message per day; growing pauses after transient errors.
 */
final class Schedule
{
    /** Minutes after the upload of the first status checks. */
    private const FIRST_CHECKS = [1, 2, 4, 8, 15, 30, 60];

    /** Minutes between attempts after transient upload errors. */
    private const BACKOFF = [1, 5, 15, 30, 60, 120];

    public static function nextStatusCheck(DateTimeImmutable $uploadedAt, DateTimeImmutable $now): DateTimeImmutable
    {
        $age = intdiv($now->getTimestamp() - $uploadedAt->getTimestamp(), 60);
        foreach (self::FIRST_CHECKS as $minutes) {
            if ($age < $minutes) {
                return max($uploadedAt->modify("+{$minutes} minutes"), $now->modify('+1 minute'));
            }
        }

        return $now->modify($age < 24 * 60 ? '+60 minutes' : '+180 minutes');
    }

    public static function backoff(int $attempts, DateTimeImmutable $now): DateTimeImmutable
    {
        $minutes = self::BACKOFF[min(max(0, $attempts - 1), count(self::BACKOFF) - 1)];

        return $now->modify("+{$minutes} minutes");
    }

    /**
     * Shortly after midnight (Romanian time), when the daily ANAF limits reset.
     */
    public static function tomorrow(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+1 day')->setTime(0, 15);
    }
}
