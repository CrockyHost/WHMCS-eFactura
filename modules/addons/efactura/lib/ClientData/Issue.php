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

namespace WHMCS\Module\Addon\Efactura\ClientData;

/**
 * A problem with one field of a client form. The message is a key of
 * lang/clientdata; it never contains what the client typed.
 */
final class Issue
{
    /** Blocks the form in strict mode, only warns otherwise. */
    public const ERROR = 'error';

    /** Always blocks, whatever the mode (a company without CUI). */
    public const REQUIRED = 'required';

    /**
     * @param string $field form field ("state", "city", "cui", ...)
     */
    public function __construct(
        public readonly string $field,
        public readonly string $key,
        public readonly string $level = self::ERROR,
    ) {
    }

    public function blocks(string $mode): bool
    {
        return $this->level === self::REQUIRED || $mode === FormContext::STRICT;
    }
}
