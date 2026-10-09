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

use RuntimeException;

/**
 * Why an invoice could not be issued early; $reason is a stable code.
 */
final class EarlyIssueException extends RuntimeException
{
    public const DISABLED = 'disabled';
    public const NOT_UNPAID = 'not_unpaid';
    public const ALREADY_FISCAL = 'already_fiscal';
    public const NOT_FISCAL = 'not_fiscal';
    public const BUSY = 'busy';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
