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

namespace WHMCS\Module\Addon\Efactura\Anaf;

/**
 * What an ANAF answer means for the addon, independent of the HTTP status.
 */
final class Outcome
{
    // upload
    /** The file entered the system; $index is the upload index. */
    public const ACCEPTED = 'accepted';
    /** Rejected for a reason that retrying cannot fix (XSD, parameters, size). */
    public const REFUSED = 'refused';
    /** The request may have reached ANAF: reconcile before sending again. */
    public const UNKNOWN = 'unknown';

    // stareMesaj
    public const OK = 'ok';
    public const NOK = 'nok';
    public const PROCESSING = 'processing';
    /** "XML cu erori nepreluat de sistem": refused at upload. */
    public const XML_ERRORS = 'xml_errors';

    // descarcare
    public const ZIP = 'zip';
    /** The file is older than the 60 days ANAF keeps it. */
    public const EXPIRED = 'expired';

    // listaMesaje
    public const MESSAGES = 'messages';
    /** "Nu exista mesaje ...": an empty result, not an error. */
    public const EMPTY = 'empty';

    // any call
    /** Transient problem, safe to retry later. */
    public const RETRY = 'retry';
    /** No SPV rights or no valid authorization: stop and alert. */
    public const AUTH = 'auth';
    /** Daily limit reached for this call: wait until tomorrow. */
    public const LIMIT = 'limit';
    /** A definitive error (wrong environment, unknown id, ...). */
    public const FATAL = 'fatal';

    /**
     * @param list<array<string, string>> $messages listaMesaje entries
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $message = '',
        public readonly ?string $index = null,
        public readonly ?string $downloadId = null,
        public readonly array $messages = [],
        public readonly string $body = '',
        public readonly int $pages = 1,
    ) {
    }

    public function is(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }
}
