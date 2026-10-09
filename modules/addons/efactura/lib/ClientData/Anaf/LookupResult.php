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

namespace WHMCS\Module\Addon\Efactura\ClientData\Anaf;

/**
 * Outcome of a company lookup.
 */
final class LookupResult
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    /** ANAF did not answer in time, answered with an error, or is busy. */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $status,
        public readonly ?CompanyRecord $company = null,
        public readonly bool $cached = false,
    ) {
    }
}
