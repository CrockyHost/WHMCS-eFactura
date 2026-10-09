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

namespace WHMCS\Module\Addon\Efactura\Ubl;

use DateTimeImmutable;

/**
 * A UBL 2.1 Invoice (BT-3 = 380) in the CIUS-RO subset used by the addon:
 * fiscal invoices and stornos (negative quantities and amounts, positive
 * prices, BT-25/BT-26 pointing to the corrected invoice). Amounts are minor
 * units of the document currency.
 */
final class Invoice
{
    public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1';

    /**
     * @param list<Line> $lines
     * @param list<TaxSubtotal> $taxSubtotals
     * @param list<string> $notes BT-22
     */
    public function __construct(
        /** BT-1 */
        public readonly string $number,
        /** BT-2 */
        public readonly DateTimeImmutable $issueDate,
        /** BT-5 */
        public readonly string $currency,
        public readonly Party $seller,
        public readonly Party $buyer,
        public readonly array $lines,
        public readonly array $taxSubtotals,
        /** BT-110 */
        public readonly int $taxTotalCents,
        /** BT-113 */
        public readonly int $prepaidCents = 0,
        /** BT-9 */
        public readonly ?DateTimeImmutable $dueDate = null,
        /** BT-111: VAT total in RON, for documents in another currency */
        public readonly ?int $taxTotalRonCents = null,
        public readonly array $notes = [],
        public readonly ?PaymentMeans $paymentMeans = null,
        /** BT-25 */
        public readonly ?string $precedingNumber = null,
        /** BT-26 */
        public readonly ?DateTimeImmutable $precedingDate = null,
    ) {
    }

    /**
     * BT-106: sum of the line amounts.
     */
    public function lineTotalCents(): int
    {
        return array_sum(array_map(static fn (Line $line): int => $line->amountCents, $this->lines));
    }

    /**
     * BT-112
     */
    public function totalCents(): int
    {
        return $this->lineTotalCents() + $this->taxTotalCents;
    }

    /**
     * BT-115
     */
    public function payableCents(): int
    {
        return $this->totalCents() - $this->prepaidCents;
    }

    public function taxCurrency(): ?string
    {
        return $this->currency === 'RON' ? null : 'RON';
    }
}
