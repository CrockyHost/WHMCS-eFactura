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
use DateTimeInterface;

/**
 * Romanian working days: no weekends and no legal holidays (art. 139 of the
 * Labour Code), including the Orthodox Good Friday, Easter and Pentecost.
 *
 * Deadlines follow Regulation 1182/71 as applied by ANAF: the day of the
 * event is not counted, the period starts with the next working day.
 */
final class WorkingDays
{
    /** Fixed-date legal holidays, "month-day". */
    private const FIXED_HOLIDAYS = [
        '01-01', '01-02', // New Year
        '01-06', '01-07', // Epiphany, Saint John (since 2024)
        '01-24', // Union of the Romanian Principalities
        '05-01', // Labour Day
        '06-01', // Children's Day
        '08-15', // Dormition of the Mother of God
        '11-30', // Saint Andrew
        '12-01', // National Day
        '12-25', '12-26', // Christmas
    ];

    /** Legal deadline for sending invoices to RO e-Factura. */
    public const LEGAL_DEADLINE_DAYS = 5;

    public static function isWorkingDay(DateTimeInterface $date): bool
    {
        return (int) $date->format('N') < 6 && !self::isHoliday($date);
    }

    public static function isHoliday(DateTimeInterface $date): bool
    {
        if (in_array($date->format('m-d'), self::FIXED_HOLIDAYS, true)) {
            return true;
        }
        $easter = self::orthodoxEaster((int) $date->format('Y'));
        $day = $date->format('Y-m-d');
        foreach (['-2 days', '+0 days', '+1 day', '+49 days', '+50 days'] as $offset) {
            if ($easter->modify($offset)->format('Y-m-d') === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * The working day that comes $days working days after $date ($date itself
     * not counted). With $days = 0, $date is returned unchanged.
     */
    public static function add(DateTimeInterface $date, int $days): DateTimeImmutable
    {
        $current = DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        while ($days > 0) {
            $current = $current->modify('+1 day');
            if (self::isWorkingDay($current)) {
                $days--;
            }
        }

        return $current;
    }

    /**
     * Last day for sending a document issued on $issueDate (inclusive, until
     * the end of the day).
     */
    public static function legalDeadline(DateTimeInterface $issueDate): DateTimeImmutable
    {
        return self::add($issueDate, self::LEGAL_DEADLINE_DAYS);
    }

    /**
     * Working days left from $today until $deadline: 0 on the deadline day,
     * negative after it.
     */
    public static function remaining(DateTimeInterface $today, DateTimeInterface $deadline): int
    {
        $from = DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        $to = DateTimeImmutable::createFromInterface($deadline)->setTime(0, 0);
        $sign = 1;
        if ($from > $to) {
            [$from, $to, $sign] = [$to, $from, -1];
        }
        $count = 0;
        for ($day = $from->modify('+1 day'); $day <= $to; $day = $day->modify('+1 day')) {
            if (self::isWorkingDay($day)) {
                $count++;
            }
        }

        return $sign * $count;
    }

    /**
     * Orthodox Easter Sunday as a Gregorian date (Meeus' Julian algorithm,
     * valid 1900 to 2099).
     */
    public static function orthodoxEaster(int $year): DateTimeImmutable
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->modify('+13 days');
    }
}
