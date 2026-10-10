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

namespace WHMCS\Module\Addon\Efactura\Fiscal;

use RuntimeException;

/**
 * Why a storno could not be issued by an admin; $reason is a stable code.
 */
final class StornoException extends RuntimeException
{
    public const NOT_FISCAL = 'not_fiscal';
    public const AMOUNTS = 'amounts';
    public const BUSY = 'busy';
    /** The VAT does not match the rate; details: VAT, rate, net, expected VAT. */
    public const VAT = 'vat';
    public const VAT_NONE = 'vat_none';
    /** The XML cannot be built; details: the problems. */
    public const INVALID = 'invalid';

    /**
     * @param list<string> $details
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
