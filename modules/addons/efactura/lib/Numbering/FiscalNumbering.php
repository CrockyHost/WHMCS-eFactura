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

use DateTimeInterface;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;

/**
 * Allocation of fiscal numbers from the WHMCS counter, shared by the
 * invoices numbered by WHMCS at payment, the invoices issued early and the
 * stornos. Callers must hold the NumberingLock.
 */
final class FiscalNumbering
{
    public function __construct(private readonly SeriesCounter $counter)
    {
    }

    public function counter(): SeriesCounter
    {
        return $this->counter;
    }

    /**
     * Takes the next number of the series and advances the counter. A number
     * already in use is skipped (the counter was behind) and reported in
     * $skipped.
     *
     * @param list<string> $skipped
     */
    public function allocate(DateTimeInterface $date, array &$skipped = []): string
    {
        $this->assertLocked();
        $value = $this->counter->value();
        if (!ctype_digit($value)) {
            throw new RuntimeException("The fiscal counter (Next Paid Invoice Number) is not a number: {$value}");
        }

        $number = $this->counter->format($value, $date);
        while ($this->inUse($number)) {
            $skipped[] = $number;
            if (count($skipped) > 1000) {
                throw new RuntimeException('The fiscal counter is far behind the numbers in use.');
            }
            $value = SeriesCounter::increment($value);
            $number = $this->counter->format($value, $date);
        }
        $this->counter->set(SeriesCounter::increment($value));

        return $number;
    }

    /**
     * Gives back a number WHMCS has just assigned, when it is still the last
     * one taken from the counter. Returns false when that is not the case:
     * giving it back would then leave a gap, so the counter is not touched.
     */
    public function giveBack(string $number): bool
    {
        $this->assertLocked();
        $counter = $this->counter->counterOf($number);
        $current = $this->counter->value();
        if ($counter === null || !ctype_digit($current) || (int) $current !== (int) $counter + 1) {
            return false;
        }
        $this->counter->set($counter);

        return true;
    }

    /**
     * Whether a number is already on an invoice (other than $exceptInvoiceId),
     * a WHMCS billing note or an addon document.
     */
    public function inUse(string $number, ?int $exceptInvoiceId = null): bool
    {
        if ($number === '') {
            return false;
        }
        $invoices = Capsule::table('tblinvoices')->where('invoicenum', $number);
        if ($exceptInvoiceId !== null) {
            $invoices->where('id', '!=', $exceptInvoiceId);
        }
        if ($invoices->exists()) {
            return true;
        }
        if (Capsule::table('tblbillingnotes')->where('custom_number', $number)->exists()) {
            return true;
        }
        $documents = Capsule::table(Document::TABLE)->where('number', $number);
        if ($exceptInvoiceId !== null) {
            $documents->where(static function ($query) use ($exceptInvoiceId): void {
                $query->where('kind', '!=', Document::KIND_INVOICE)->orWhere('invoice_id', '!=', $exceptInvoiceId);
            });
        }

        return $documents->exists();
    }

    private function assertLocked(): void
    {
        if (!NumberingLock::held()) {
            throw new RuntimeException('Fiscal numbers can only be allocated under the numbering lock.');
        }
    }
}
