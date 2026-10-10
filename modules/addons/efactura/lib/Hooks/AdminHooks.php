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
use WHMCS\Module\Addon\Efactura\Admin\CreditNotePanel;
use WHMCS\Module\Addon\Efactura\Admin\InvoicePanel;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Output in WHMCS admin pages. A failure shows a short notice and never
 * breaks the page.
 */
final class AdminHooks
{
    /**
     * AdminInvoicesControlsOutput: the e-Factura panel of the invoice.
     *
     * @param array<string, mixed> $vars
     */
    public static function invoiceControls(array $vars): string
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0 || !AdminContext::canUseAddon()) {
            return '';
        }
        try {
            Lang::boot(Settings::string('ui_language'), AdminContext::id());

            return (new InvoicePanel())->render($invoiceId);
        } catch (Throwable $e) {
            return self::failure($e);
        }
    }

    /**
     * AdminAreaFooterOutput: on the page of a credit note, the storno issued
     * from it (moved next to its status by admin.js).
     *
     * @param array<string, mixed> $vars
     */
    public static function footer(array $vars): string
    {
        $noteId = CreditNotePanel::noteFromRequest((string) ($_SERVER['REQUEST_URI'] ?? ''), (string) ($_GET['rp'] ?? ''));
        if ($noteId === null || !AdminContext::canUseAddon()) {
            return '';
        }
        try {
            Lang::boot(Settings::string('ui_language'), AdminContext::id());

            return (new CreditNotePanel())->render($noteId);
        } catch (Throwable $e) {
            return self::failure($e);
        }
    }

    private static function failure(Throwable $e): string
    {
        return '<div class="alert alert-danger">' . htmlspecialchars(Addon::NAME . ': ' . $e->getMessage(), ENT_QUOTES) . '</div>';
    }
}
