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

namespace WHMCS\Module\Addon\Efactura\Hooks;

use Throwable;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\ConnectionMonitor;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Hooks run by the WHMCS cron. A failure is logged and never interrupts
 * the cron.
 */
final class CronHooks
{
    /** Time for the queue in each cron run (the system cron runs every 5 minutes). */
    private const WORKER_BUDGET_SECONDS = 45;

    /**
     * After every cron run: uploads, status checks, downloads, reconciliation
     * and deadline alerts.
     */
    public static function afterCron(): void
    {
        try {
            Lang::boot(Settings::string('ui_language'));
            Addon::worker()->run(self::WORKER_BUDGET_SECONDS);
        } catch (Throwable $e) {
            logActivity(Addon::NAME . ': the e-Factura queue run failed: ' . $e->getMessage());
        }
    }

    public static function daily(): void
    {
        try {
            Lang::boot(Settings::string('ui_language'));
            (new ConnectionMonitor(Addon::connection(), AdminNotifier::reauthorization(...)))->run();
        } catch (Throwable $e) {
            logActivity(Addon::NAME . ': daily ANAF connection check failed: ' . $e->getMessage());
        }
    }
}
