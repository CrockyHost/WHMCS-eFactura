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

namespace WHMCS\Module\Addon\Efactura\Anaf\OAuth;

use RuntimeException;

/**
 * An OAuth failure. When $reauthorize is true, retrying cannot help: the
 * person with the qualified certificate must authorize the addon again.
 */
final class OAuthException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_CONNECTED = 'not_connected';
    public const INVALID_STATE = 'invalid_state';
    public const DENIED = 'access_denied';
    public const REJECTED = 'rejected';
    public const TRANSPORT = 'transport';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly bool $reauthorize = false,
    ) {
        parent::__construct($message);
    }
}
