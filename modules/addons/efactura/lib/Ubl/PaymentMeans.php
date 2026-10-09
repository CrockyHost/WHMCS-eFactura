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
 * Payment instructions (BG-16).
 */
final class PaymentMeans
{
    /** UNCL 4461 codes offered in the settings. */
    public const CODES = ['1', '10', '30', '42', '48', '54', '55', '58', '68', '97'];

    /** Codes for which BR-61 requires the payee account (IBAN). */
    public const ACCOUNT_REQUIRED = ['30', '58'];

    /** Codes for which the payee account is written when it is known. */
    public const ACCOUNT_CODES = ['30', '42', '58'];

    public function __construct(
        /** BT-81 */
        public readonly string $code,
        /** BT-84 */
        public readonly ?string $iban = null,
        /** BT-85 */
        public readonly ?string $accountName = null,
        /** BT-86 */
        public readonly ?string $bic = null,
    ) {
    }
}
