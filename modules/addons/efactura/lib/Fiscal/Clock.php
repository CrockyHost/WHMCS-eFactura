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

namespace WHMCS\Module\Addon\Efactura\Fiscal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fiscal dates are Romanian dates, whatever the PHP time zone. Tests can
 * freeze the time.
 */
final class Clock
{
    public const ZONE = 'Europe/Bucharest';

    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
    }

    public static function today(): DateTimeImmutable
    {
        return self::now()->setTime(0, 0);
    }

    /**
     * A WHMCS date or date-time value interpreted as Romanian time.
     */
    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone(self::ZONE));
    }

    public static function freeze(?DateTimeImmutable $now): void
    {
        self::$frozen = $now?->setTimezone(new DateTimeZone(self::ZONE));
    }
}
