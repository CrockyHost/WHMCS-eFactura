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

use WHMCS\Module\Addon\Efactura\Fiscal\WorkingDays;
use WHMCS\Module\Addon\Efactura\Numbering\SeriesCounter;
use WHMCS\Module\Addon\Efactura\Queue\DeadlineMonitor;

$day = static fn (string $date): DateTimeImmutable => new DateTimeImmutable($date);

return [
    'Orthodox Easter dates' => static function (): void {
        $known = [2024 => '2024-05-05', 2025 => '2025-04-20', 2026 => '2026-04-12', 2027 => '2027-05-02', 2028 => '2028-04-16', 2030 => '2030-04-28'];
        foreach ($known as $year => $date) {
            Assert::same($date, WorkingDays::orthodoxEaster($year)->format('Y-m-d'), (string) $year);
        }
    },
    'legal holidays of 2026 are not working days' => static function () use ($day): void {
        $holidays = ['2026-01-01', '2026-01-02', '2026-01-06', '2026-01-07', '2026-01-24', '2026-04-10', '2026-04-12', '2026-04-13',
            '2026-05-01', '2026-05-31', '2026-06-01', '2026-08-15', '2026-11-30', '2026-12-01', '2026-12-25', '2026-12-26'];
        foreach ($holidays as $date) {
            Assert::true(WorkingDays::isHoliday($day($date)), $date);
            Assert::false(WorkingDays::isWorkingDay($day($date)), $date);
        }
        Assert::true(WorkingDays::isWorkingDay($day('2026-04-14')));
        Assert::false(WorkingDays::isWorkingDay($day('2026-10-10')), 'Saturday');
    },
    'the legal deadline is 5 working days after the issue date' => static function () use ($day): void {
        // Examples from the legal research (OUG 89/2025, Regulation 1182/71).
        Assert::same('2026-10-12', WorkingDays::legalDeadline($day('2026-10-05'))->format('Y-m-d'));
        Assert::same('2026-12-08', WorkingDays::legalDeadline($day('2026-11-27'))->format('Y-m-d'));
        // Issued on Holy Thursday: Good Friday, Easter and Easter Monday do not count.
        Assert::same('2026-04-20', WorkingDays::legalDeadline($day('2026-04-09'))->format('Y-m-d'));
        // Issued on a Saturday.
        Assert::same('2026-10-16', WorkingDays::legalDeadline($day('2026-10-10'))->format('Y-m-d'));
    },
    'adding working days' => static function () use ($day): void {
        Assert::same('2026-10-09', WorkingDays::add($day('2026-10-09 15:30'), 0)->format('Y-m-d'));
        Assert::same('2026-10-12', WorkingDays::add($day('2026-10-09'), 1)->format('Y-m-d'), 'Friday + 1 = Monday');
        Assert::same('2026-12-04', WorkingDays::add($day('2026-11-27'), 3)->format('Y-m-d'));
    },
    'remaining working days until a deadline' => static function () use ($day): void {
        Assert::same(5, WorkingDays::remaining($day('2026-10-05'), $day('2026-10-12')));
        Assert::same(0, WorkingDays::remaining($day('2026-10-12'), $day('2026-10-12')));
        Assert::same(-1, WorkingDays::remaining($day('2026-10-13'), $day('2026-10-12')));
        Assert::same(-1, WorkingDays::remaining($day('2026-10-19'), $day('2026-10-16')), 'the weekend does not count');
    },
    'deadline alert levels' => static function () use ($day): void {
        $deadline = $day('2026-10-16');
        $levels = ['2026-10-12' => 0, '2026-10-13' => 0, '2026-10-14' => 1, '2026-10-15' => 2, '2026-10-16' => 3, '2026-10-17' => 3, '2026-10-19' => 4, '2026-10-20' => 5];
        foreach ($levels as $today => $level) {
            Assert::same($level, DeadlineMonitor::level($day($today), $deadline), $today);
        }
    },
    'the series counter keeps its zero padding' => static function (): void {
        Assert::same('0095', SeriesCounter::increment('0094'));
        Assert::same('0100', SeriesCounter::increment('0099'));
        Assert::same('10000', SeriesCounter::increment('9999'));
        Assert::same('8', SeriesCounter::increment('7'));
    },
];
