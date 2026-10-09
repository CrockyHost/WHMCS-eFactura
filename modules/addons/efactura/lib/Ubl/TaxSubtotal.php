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
 * VAT breakdown per category and rate (BG-23).
 */
final class TaxSubtotal
{
    public function __construct(
        /** BT-118 */
        public readonly string $category,
        /** BT-119; null for category O */
        public readonly ?string $rate,
        /** BT-116 */
        public readonly int $taxableCents,
        /** BT-117 */
        public readonly int $taxCents,
        /** BT-121 */
        public readonly ?string $exemptionCode = null,
        /** BT-120 */
        public readonly ?string $exemptionReason = null,
    ) {
    }
}
