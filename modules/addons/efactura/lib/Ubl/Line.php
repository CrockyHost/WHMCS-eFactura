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

/**
 * Invoice line (BG-25). A negative line (discount, storno) has quantity -1
 * and a positive unit price, as BR-27 requires.
 */
final class Line
{
    public function __construct(
        /** BT-126 */
        public readonly string $id,
        /** BT-153, at most 100 characters */
        public readonly string $name,
        /** BT-131, in minor units */
        public readonly int $amountCents,
        /** BT-151: S, E, AE, ... */
        public readonly string $category,
        /** BT-152, e.g. "21"; null for category O */
        public readonly ?string $rate,
        /** BT-154, at most 200 characters */
        public readonly ?string $description = null,
        /** BT-127, at most 300 characters */
        public readonly ?string $note = null,
        /** BT-129: 1 or -1 */
        public readonly int $quantity = 1,
        /** BT-130 */
        public readonly string $unitCode = 'C62',
        /** BT-155 */
        public readonly ?string $sellerItemId = null,
    ) {
    }

    /**
     * BT-146: the net unit price, never negative.
     */
    public function priceCents(): int
    {
        return intdiv(abs($this->amountCents), max(1, abs($this->quantity)));
    }
}
