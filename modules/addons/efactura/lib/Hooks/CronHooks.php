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
