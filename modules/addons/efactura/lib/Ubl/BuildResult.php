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

use WHMCS\Module\Addon\Efactura\Exchange\ExchangeRate;

/**
 * The outcome of generating the XML of a document: the XML when it can be
 * sent, otherwise the problems to show to the admin.
 */
final class BuildResult
{
    /**
     * @param list<array{rule: string, message: string}> $issues
     */
    public function __construct(
        public readonly ?Invoice $invoice,
        public readonly ?string $xml,
        public readonly array $issues,
        public readonly string $buyerType,
        public readonly ?ExchangeRate $exchangeRate = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->xml !== null && $this->issues === [];
    }
}
