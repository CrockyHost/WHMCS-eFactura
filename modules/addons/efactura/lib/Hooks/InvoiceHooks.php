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
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Numbering\EarlyIssue;
use WHMCS\Module\Addon\Efactura\Numbering\EarlyIssueException;
use WHMCS\Module\Addon\Efactura\Numbering\NumberingLock;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\AdminNotifier;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Invoice hooks. Each one does nothing while processing is disabled, and an
 * error is logged and reported but never interrupts WHMCS (a payment must
 * always go through).
 */
final class InvoiceHooks
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function addInvoicePayment(array $vars): void
    {
        self::guard('AddInvoicePayment', $vars, static function (int $invoiceId): void {
            Addon::paymentNumbering()->beforePayment($invoiceId);
        });
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function invoicePaidPreEmail(array $vars): void
    {
        self::guard('InvoicePaidPreEmail', $vars, static function (int $invoiceId): void {
            Addon::paymentNumbering()->afterNumbering($invoiceId);
        });
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function invoicePaid(array $vars): void
    {
        // Always release, even if processing was disabled meanwhile.
        try {
            Addon::paymentNumbering()->afterPayment((int) ($vars['invoiceid'] ?? 0));
        } catch (Throwable $e) {
            NumberingLock::release();
        }
    }

    /**
     * InvoiceCreationPreEmail and InvoiceCreated: issue the fiscal invoice at
     * once for the clients chosen in the settings.
     *
     * @param array<string, mixed> $vars
     */
    public static function invoiceCreated(array $vars): void
    {
        self::guard('InvoiceCreated', $vars, static function (int $invoiceId): void {
            $clientId = (int) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
            if ($clientId <= 0 || !EarlyIssue::appliesToClient($clientId) || Addon::documents()->forInvoice($invoiceId) !== null) {
                return;
            }
            try {
                Addon::earlyIssue()->issue($invoiceId, null, NumberingLock::paymentTimeout());
            } catch (EarlyIssueException $e) {
                // Drafts and non-fiscal invoices are skipped silently.
                if ($e->reason === EarlyIssueException::BUSY) {
                    self::report('early issue', $invoiceId, $e);
                }
            }
        });
    }

    /**
     * InvoiceCancelled: the storno of a fiscal invoice (a proforma has none).
     * WHMCS runs the hook before it saves the new status, so the storno is
     * issued at the end of the request (or by the cron).
     *
     * @param array<string, mixed> $vars
     */
    public static function invoiceCancelled(array $vars): void
    {
        self::guard('InvoiceCancelled', $vars, static function (int $invoiceId): void {
            $stornos = Addon::stornos();
            if ($stornos->requestCancel($invoiceId, AdminContext::id())) {
                $stornos->processAtShutdown();
            }
        });
    }

    /**
     * AddTransaction: a refund of a payment of a fiscal invoice. WHMCS creates
     * the credit note after this hook, so the storno is issued at the end of
     * the request (or by the cron).
     *
     * @param array<string, mixed> $vars
     */
    public static function addTransaction(array $vars): void
    {
        if ((float) ($vars['amountout'] ?? 0) <= 0 || (int) ($vars['refundid'] ?? 0) <= 0) {
            return;
        }
        self::guard('AddTransaction', $vars, static function (int $invoiceId): void {
            $stornos = Addon::stornos();
            if ($stornos->requestRefund($invoiceId, AdminContext::id())) {
                $stornos->processAtShutdown();
            }
        });
    }

    /**
     * InvoiceRefunded: the invoice is refunded in full; the credit note of the
     * last refund exists now.
     *
     * @param array<string, mixed> $vars
     */
    public static function invoiceRefunded(array $vars): void
    {
        self::guard('InvoiceRefunded', $vars, static function (int $invoiceId): void {
            $stornos = Addon::stornos();
            if ($stornos->requestRefund($invoiceId, AdminContext::id())) {
                $stornos->process(NumberingLock::paymentTimeout(), [$invoiceId]);
            }
        });
    }

    /**
     * @param array<string, mixed> $vars
     * @param callable(int): void $callback
     */
    private static function guard(string $hook, array $vars, callable $callback): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        try {
            if ($invoiceId <= 0 || !Settings::bool('enabled')) {
                return;
            }
            Lang::boot(Settings::string('ui_language'));
            $callback($invoiceId);
        } catch (Throwable $e) {
            self::report($hook, $invoiceId, $e);
        }
    }

    private static function report(string $context, int $invoiceId, Throwable $e): void
    {
        try {
            AdminNotifier::send(
                Lang::get('alert_hook_failed_subject', $invoiceId),
                Lang::get('alert_hook_failed_body', $context, $invoiceId, $e->getMessage())
            );
        } catch (Throwable) {
            logActivity(Addon::NAME . ": {$context} failed for invoice #{$invoiceId}: " . $e->getMessage());
        }
    }
}
