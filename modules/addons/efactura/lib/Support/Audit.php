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

namespace WHMCS\Module\Addon\Efactura\Support;

use WHMCS\Database\Capsule;

/**
 * Audit trail (mod_efactura_audit): state transitions and admin actions.
 * Never store secrets in the message or the context.
 */
final class Audit
{
    public const TABLE = 'mod_efactura_audit';

    /**
     * @param array<string, mixed> $context
     */
    public static function log(
        string $event,
        string $message = '',
        array $context = [],
        ?int $documentId = null,
        ?int $invoiceId = null,
        ?string $fromState = null,
        ?string $toState = null,
        ?int $adminId = null,
    ): void {
        Capsule::table(self::TABLE)->insert([
            'document_id' => $documentId,
            'invoice_id' => $invoiceId,
            'event' => $event,
            'from_state' => $fromState,
            'to_state' => $toState,
            'message' => $message,
            'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'admin_id' => $adminId ?? AdminContext::id(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
