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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\ClientData\InvoiceReferences;
use WHMCS\Module\Addon\Efactura\Support\Audit;

/**
 * The references public institutions ask for on an invoice: project
 * (BT-11), contract (BT-12), purchase order (BT-13), receiving advice
 * (BT-15), buyer accounting reference (BT-19) and buyer identifier (BT-46).
 *
 * An admin types them on the invoice until its XML is built for sending. An
 * empty one takes the default of the client (ClientData\InvoiceReferences),
 * read when the XML is built, like the rest of the client data. The values
 * that went into the XML are kept on the document (refs) and no longer
 * change; a storno carries those of the invoice it corrects.
 */
final class References
{
    public const TABLE = 'mod_efactura_references';

    /**
     * Business term => maximum length. CIUS-RO limits BT-12, BT-13 and BT-15
     * to 200 characters and BT-19 to 100; it sets no limit for BT-11 and
     * BT-46, which the addon keeps to 200.
     */
    public const TERMS = ['BT-11' => 200, 'BT-12' => 200, 'BT-13' => 200, 'BT-15' => 200, 'BT-19' => 100, 'BT-46' => 200];

    // Why the values of an invoice were not saved.
    public const ERROR_FROZEN = 'frozen';
    public const ERROR_BUSY = 'busy';
    public const ERROR_NOT_REPORTED = 'not_reported';
    public const ERROR_LENGTH = 'length';

    /**
     * The values typed on the invoice.
     *
     * @return array<string, string> business term => value
     */
    public static function ofInvoice(int $invoiceId): array
    {
        return self::decode(Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->value('refs'));
    }

    /**
     * The defaults in the client profile, as they are now.
     *
     * @return array<string, string>
     */
    public static function defaults(int $clientId): array
    {
        return self::normalize(InvoiceReferences::defaults($clientId));
    }

    /**
     * What goes into the XML of the invoice: its own values, and the client
     * defaults for the empty ones.
     *
     * @return array<string, string>
     */
    public static function resolve(int $invoiceId, int $clientId): array
    {
        $own = self::ofInvoice($invoiceId);
        $defaults = self::defaults($clientId);
        $values = [];
        foreach (array_keys(self::TERMS) as $term) {
            $value = $own[$term] ?? $defaults[$term] ?? null;
            if ($value !== null) {
                $values[$term] = $value;
            }
        }

        return $values;
    }

    /**
     * The references that went into the XML of a document.
     *
     * @return array<string, string>
     */
    public static function frozen(object $document): array
    {
        return self::decode($document->refs ?? null);
    }

    /**
     * Whether the references of an invoice can still change: a proforma, or
     * a fiscal invoice whose XML is not built yet. A document rejected by
     * ANAF counts as not built: its XML is built again when it is sent again.
     * Never for an invoice that is not reported to RO e-Factura, nor for one
     * reported before the addon.
     */
    public static function editable(int $invoiceId, ?object $document): bool
    {
        if ($document !== null) {
            if ($document->kind !== Document::KIND_INVOICE || in_array($document->state, [Document::STATE_EXCLUDED, Document::STATE_EXTERNAL], true)) {
                return false;
            }

            return $document->xml_sha256 === null || $document->state === Document::STATE_REJECTED;
        }
        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');

        return in_array($status, ['Unpaid', 'Draft'], true) && ReportingPolicy::nonFiscalReason($invoiceId) === null;
    }

    /**
     * Saves the values typed on an invoice; the empty ones are removed.
     *
     * @param array<mixed> $input business term => value
     * @return string|null null when saved, else one of the ERROR_ constants
     */
    public static function save(int $invoiceId, array $input, ?int $adminId): ?string
    {
        $values = self::normalize($input);
        if (self::tooLong($values) !== []) {
            return self::ERROR_LENGTH;
        }
        $documents = Addon::documents();
        $document = $documents->forInvoice($invoiceId);
        if (!self::editable($invoiceId, $document)) {
            return $document !== null && $document->kind === Document::KIND_INVOICE && $document->state !== Document::STATE_EXCLUDED
                ? self::ERROR_FROZEN
                : self::ERROR_NOT_REPORTED;
        }
        // Claimed, so that the worker cannot build the XML meanwhile.
        if ($document !== null) {
            $document = $documents->claim((int) $document->id, [(string) $document->state]);
            if ($document === null) {
                return self::ERROR_BUSY;
            }
        }
        try {
            if ($document !== null && !self::editable($invoiceId, $document)) {
                return self::ERROR_FROZEN;
            }
            if ($values === []) {
                Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->delete();
            } else {
                Capsule::table(self::TABLE)->updateOrInsert(['invoice_id' => $invoiceId], [
                    'refs' => (string) self::encode($values),
                    'updated_at' => Clock::now()->format('Y-m-d H:i:s'),
                    'updated_by' => $adminId,
                ]);
            }
            $summary = implode('; ', array_map(static fn (string $term, string $value): string => $term . ': ' . $value, array_keys($values), $values));
            Audit::log('references_saved', $summary !== '' ? $summary : '-', ['references' => $values], $document !== null ? (int) $document->id : null, $invoiceId, adminId: $adminId);
        } finally {
            if ($document !== null) {
                $documents->releaseClaim((int) $document->id);
            }
        }

        return null;
    }

    /**
     * The terms whose value is longer than allowed.
     *
     * @param array<string, string> $values
     * @return list<string>
     */
    public static function tooLong(array $values): array
    {
        return array_keys(array_filter(
            $values,
            static fn (string $value, string $term): bool => mb_strlen($value) > (self::TERMS[$term] ?? 0),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * The known terms only, each as one line of text, without the empty ones.
     *
     * @param array<mixed> $values
     * @return array<string, string>
     */
    public static function normalize(array $values): array
    {
        $clean = [];
        foreach (array_keys(self::TERMS) as $term) {
            $value = $values[$term] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            // Line breaks and control characters (not allowed in XML) become spaces.
            $value = trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');
            if ($value !== '') {
                $clean[$term] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, string> $values
     */
    public static function encode(array $values): ?string
    {
        return $values === [] ? null : (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The language key of a term's label ("BT-12" => "ref_bt12").
     */
    public static function langKey(string $term): string
    {
        return 'ref_' . strtolower(str_replace('-', '', $term));
    }

    /**
     * @return array<string, string>
     */
    private static function decode(mixed $json): array
    {
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($values) ? self::normalize($values) : [];
    }
}
