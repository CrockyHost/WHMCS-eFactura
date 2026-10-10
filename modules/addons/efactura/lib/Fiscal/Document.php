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

/**
 * Kinds, states and reasons of the fiscal documents in
 * mod_efactura_documents.
 */
final class Document
{
    public const TABLE = 'mod_efactura_documents';

    public const KIND_INVOICE = 'invoice';
    public const KIND_STORNO = 'storno';

    public const SOURCE_PAYMENT = 'payment';
    public const SOURCE_EARLY = 'early';
    public const SOURCE_REFUND = 'refund';
    public const SOURCE_CANCEL = 'cancel';

    // Waiting for its send time.
    public const STATE_SCHEDULED = 'scheduled';
    // Kept back by an admin or by a manual check (review_reason).
    public const STATE_HELD = 'held';
    // Local validation failed; waits for corrected data.
    public const STATE_INVALID = 'invalid';
    // Upload in progress (written before the network call).
    public const STATE_SENDING = 'sending';
    // Upload outcome unknown; reconciled through the message list.
    public const STATE_UNKNOWN = 'unknown';
    // Uploaded, ANAF is processing it.
    public const STATE_PROCESSING = 'processing';
    public const STATE_VALIDATED = 'validated';
    public const STATE_REJECTED = 'rejected';
    // Technical error, retried automatically.
    public const STATE_RETRY = 'retry';
    // A fiscal invoice that is not reported to RO e-Factura.
    public const STATE_EXCLUDED = 'excluded';

    public const EXCLUDED_EU_REVERSE_CHARGE = 'eu_reverse_charge';
    public const EXCLUDED_NON_EU = 'non_eu';

    public const REVIEW_LOCK_TIMEOUT = 'lock_timeout';
    public const REVIEW_COUNTER_GAP = 'counter_gap';
    public const REVIEW_DUPLICATE_NUMBER = 'duplicate_number';

    public static function invoiceKey(int $invoiceId): string
    {
        return 'invoice:' . $invoiceId;
    }
}
