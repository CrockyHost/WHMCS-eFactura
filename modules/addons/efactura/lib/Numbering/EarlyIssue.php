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

namespace WHMCS\Module\Addon\Efactura\Numbering;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Fiscal\ReportingPolicy;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Audit;

/**
 * Issues the fiscal invoice before payment ("Issue fiscal now", or the rule
 * by client group or client ID): the invoice gets the next fiscal number and
 * today's date, replacing its proforma number. At payment WHMCS overwrites
 * both; PaymentNumbering puts them back.
 */
final class EarlyIssue
{
    public function __construct(
        private readonly FiscalNumbering $numbering,
        private readonly DocumentRepository $documents,
    ) {
    }

    /**
     * Whether the client's invoices are issued as fiscal invoices at once.
     */
    public static function appliesToClient(int $clientId): bool
    {
        if (in_array($clientId, Settings::intList('early_issue_clients'), true)) {
            return true;
        }
        $groupId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('groupid');

        return $groupId > 0 && in_array($groupId, Settings::intList('early_issue_groups'), true);
    }

    /**
     * @return string the fiscal number given to the invoice
     * @throws EarlyIssueException
     */
    public function issue(int $invoiceId, ?int $adminId, int $lockTimeout): string
    {
        if (!Settings::bool('enabled')) {
            throw new EarlyIssueException('Processing is disabled.', EarlyIssueException::DISABLED);
        }
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'invoicenum', 'status']);
        if ($invoice === null || $invoice->status !== 'Unpaid') {
            throw new EarlyIssueException('Only unpaid invoices can be issued early.', EarlyIssueException::NOT_UNPAID);
        }
        if ($this->documents->forInvoice($invoiceId) !== null) {
            throw new EarlyIssueException('The invoice already has a fiscal number.', EarlyIssueException::ALREADY_FISCAL);
        }
        if (ReportingPolicy::nonFiscalReason($invoiceId) !== null) {
            throw new EarlyIssueException('This invoice is not a fiscal document.', EarlyIssueException::NOT_FISCAL);
        }

        $ownLock = !NumberingLock::held();
        if (!NumberingLock::acquire(0, $lockTimeout)) {
            throw new EarlyIssueException('The fiscal numbering is busy; try again.', EarlyIssueException::BUSY);
        }
        try {
            return Capsule::connection()->transaction(function () use ($invoice, $adminId): string {
                $today = Clock::today();
                $number = $this->numbering->allocate($today);
                Capsule::table('tblinvoices')->where('id', $invoice->id)->update([
                    'invoicenum' => $number,
                    'date' => $today->format('Y-m-d'),
                ]);
                $this->documents->createInvoiceDocument((int) $invoice->id, [
                    'number' => $number,
                    'proforma_number' => (string) $invoice->invoicenum,
                    'source' => Document::SOURCE_EARLY,
                    'issue_date' => $today,
                    'issued_by' => $adminId,
                ]);
                Audit::log('issued_early', $number, ['proforma_number' => (string) $invoice->invoicenum], null, (int) $invoice->id, adminId: $adminId);

                return $number;
            });
        } finally {
            if ($ownLock) {
                NumberingLock::releaseIfOwner(0);
            }
        }
    }
}
