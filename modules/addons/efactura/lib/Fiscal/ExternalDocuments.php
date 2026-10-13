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

use DateTimeImmutable;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Exchange\VatInRon;
use WHMCS\Module\Addon\Efactura\Support\Audit;
use WHMCS\Module\Addon\Efactura\Support\Money;
use WHMCS\Module\Addon\Efactura\Whmcs\FiscalInvoice;

/**
 * Registers the fiscal invoices and stornos issued and reported before the
 * addon, by another system, so that the addon treats them as its own
 * without ever sending them:
 *
 * - an unpaid one keeps its number and date when it is paid (WHMCS would
 *   otherwise give it a new number from the fiscal series);
 * - its PDF and page get the title of an invoice, not of a proforma;
 * - a later cancellation or refund issues a storno that points to it, and a
 *   storno already issued before counts, so nothing is reversed twice.
 *
 * Each row: kind ("invoice" or "storno"), invoice_id (the WHMCS invoice; for
 * a storno, the invoice it reverses), number and issue_date as issued. A
 * storno also gives its amounts: storno_invoice_id, a WHMCS invoice of its
 * own with negative amounts (as some billing addons create), or net and tax.
 * The rows are checked first; nothing is written while one has an error.
 */
final class ExternalDocuments
{
    public const REASON_IMPORT = 'import';

    public function __construct(private readonly BnrRates $rates)
    {
    }

    /**
     * Checks the rows and says what would be imported.
     *
     * @param list<array<string, string>> $rows
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, warnings: list<string>}
     */
    public function check(array $rows): array
    {
        $planned = [];
        $errors = [];
        $warnings = [];
        $numbers = [];
        $invoices = [];
        // The invoices first: a storno may reverse an invoice of the same file.
        $ordered = $rows;
        usort($ordered, static fn (array $a, array $b): int => (strtolower(trim($a['kind'] ?? '')) === Document::KIND_STORNO) <=> (strtolower(trim($b['kind'] ?? '')) === Document::KIND_STORNO));
        foreach ($ordered as $row) {
            $line = (int) ($row['line'] ?? 0);
            $problem = static function (string $text) use (&$errors, $line): void {
                $errors[] = "line {$line}: {$text}";
            };
            $kind = strtolower(trim($row['kind'] ?? ''));
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $number = trim($row['number'] ?? '');
            $date = self::date(trim($row['issue_date'] ?? ''));
            if (!in_array($kind, [Document::KIND_INVOICE, Document::KIND_STORNO], true)) {
                $problem('kind must be "invoice" or "storno"');
                continue;
            }
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'userid', 'invoicenum', 'subtotal', 'tax', 'total']);
            if ($invoice === null) {
                $problem("WHMCS invoice #{$invoiceId} does not exist");
                continue;
            }
            if ($number === '' || strlen($number) > 57) {
                $problem('the number is missing or too long');
                continue;
            }
            if (isset($numbers[$number]) || Capsule::table(Document::TABLE)->where('number', $number)->exists()) {
                $problem("the number {$number} is already registered");
                continue;
            }
            if ($date === null || $date > Clock::today()) {
                $problem('the issue date must be a past date as YYYY-MM-DD');
                continue;
            }
            $numbers[$number] = true;

            if ($kind === Document::KIND_INVOICE) {
                if (isset($invoices[$invoiceId]) || Capsule::table(Document::TABLE)->where('dedupe_key', Document::invoiceKey($invoiceId))->exists()) {
                    $problem("WHMCS invoice #{$invoiceId} already has a fiscal invoice");
                    continue;
                }
                $invoices[$invoiceId] = ['net' => Money::cents((string) $invoice->subtotal), 'tax' => Money::cents((string) $invoice->tax), 'reversed' => [0, 0]];
                if ((string) $invoice->invoicenum !== $number) {
                    $warnings[] = "line {$line}: WHMCS invoice #{$invoiceId} shows the number \"{$invoice->invoicenum}\", not {$number}: its PDF keeps the WHMCS title";
                }
                $planned[] = ['line' => $line, 'kind' => $kind, 'invoice' => $invoice, 'number' => $number, 'date' => $date];
                continue;
            }

            // A storno: of an invoice registered before or in this file.
            $original = Capsule::table(Document::TABLE)->where('dedupe_key', Document::invoiceKey($invoiceId))->first();
            if ($original === null && !isset($invoices[$invoiceId])) {
                $problem("WHMCS invoice #{$invoiceId} has no fiscal invoice to reverse");
                continue;
            }
            $stornoInvoice = null;
            if (trim($row['storno_invoice_id'] ?? '') !== '') {
                $stornoInvoice = Capsule::table('tblinvoices')->where('id', (int) $row['storno_invoice_id'])->first(['id', 'userid', 'invoicenum', 'subtotal', 'tax']);
                if ($stornoInvoice === null || (int) $stornoInvoice->userid !== (int) $invoice->userid) {
                    $problem('storno_invoice_id is not a WHMCS invoice of the same client');
                    continue;
                }
            }
            $net = trim($row['net'] ?? '') !== '' ? Money::cents($row['net']) : ($stornoInvoice !== null ? Money::cents((string) $stornoInvoice->subtotal) : null);
            $tax = trim($row['tax'] ?? '') !== '' ? Money::cents($row['tax']) : ($stornoInvoice !== null ? Money::cents((string) $stornoInvoice->tax) : null);
            if ($net === null || $tax === null || $net > 0 || $tax > 0 || $net + $tax >= 0) {
                $problem('a storno needs negative amounts: net and tax, or storno_invoice_id');
                continue;
            }
            $invoices[$invoiceId] ??= [
                'net' => Money::cents((string) $invoice->subtotal),
                'tax' => Money::cents((string) $invoice->tax),
                'reversed' => self::reversed((int) $original->id),
            ];
            [$reversedNet, $reversedTax] = $invoices[$invoiceId]['reversed'];
            if ($invoices[$invoiceId]['net'] + $reversedNet + $net < 0 || $invoices[$invoiceId]['tax'] + $reversedTax + $tax < 0) {
                $problem("the stornos of WHMCS invoice #{$invoiceId} would reverse more than the invoice");
                continue;
            }
            $invoices[$invoiceId]['reversed'] = [$reversedNet + $net, $reversedTax + $tax];
            if ($stornoInvoice !== null && (string) $stornoInvoice->invoicenum !== $number) {
                $warnings[] = "line {$line}: WHMCS invoice #{$stornoInvoice->id} shows the number \"{$stornoInvoice->invoicenum}\", not {$number}: its PDF keeps the WHMCS title";
            }
            $planned[] = ['line' => $line, 'kind' => $kind, 'invoice' => $invoice, 'number' => $number, 'date' => $date,
                'storno_invoice_id' => $stornoInvoice !== null ? (int) $stornoInvoice->id : null, 'net' => $net, 'tax' => $tax];
        }

        return ['rows' => $planned, 'errors' => self::byLine($errors), 'warnings' => self::byLine($warnings)];
    }

    /**
     * Messages in the order of the lines of the file.
     *
     * @param list<string> $messages "line N: ..."
     * @return list<string>
     */
    private static function byLine(array $messages): array
    {
        usort($messages, static fn (string $a, string $b): int => (int) substr($a, 5) <=> (int) substr($b, 5));

        return $messages;
    }

    /**
     * Imports the rows, all or none.
     *
     * @param list<array<string, string>> $rows
     * @return array{imported: int, errors: list<string>, warnings: list<string>}
     */
    public function import(array $rows): array
    {
        $check = $this->check($rows);
        if ($check['errors'] !== []) {
            return ['imported' => 0, 'errors' => $check['errors'], 'warnings' => $check['warnings']];
        }
        $warnings = $check['warnings'];
        Capsule::connection()->transaction(function () use ($check, &$warnings): void {
            foreach ($check['rows'] as $row) {
                $id = $row['kind'] === Document::KIND_INVOICE ? $this->insertInvoice($row) : $this->insertStorno($row);
                $warning = $this->saveRate($id);
                if ($warning !== null) {
                    $warnings[] = "line {$row['line']}: {$warning}";
                }
            }
        });

        return ['imported' => count($check['rows']), 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * @param array{invoice: object, number: string, date: DateTimeImmutable} $row
     */
    private function insertInvoice(array $row): int
    {
        $invoice = $row['invoice'];

        return $this->insert($row, [
            'dedupe_key' => Document::invoiceKey((int) $invoice->id),
            'kind' => Document::KIND_INVOICE,
            'invoice_id' => (int) $invoice->id,
            'total' => $invoice->total,
        ]);
    }

    /**
     * @param array{invoice: object, number: string, date: DateTimeImmutable, storno_invoice_id: ?int, net: int, tax: int} $row
     */
    private function insertStorno(array $row): int
    {
        $original = Capsule::table(Document::TABLE)->where('dedupe_key', Document::invoiceKey((int) $row['invoice']->id))->first(['id']);

        return $this->insert($row, [
            'dedupe_key' => 'import:' . $row['number'],
            'kind' => Document::KIND_STORNO,
            'reason' => self::REASON_IMPORT,
            'invoice_id' => (int) $row['invoice']->id,
            'storno_invoice_id' => $row['storno_invoice_id'],
            'original_document_id' => (int) $original->id,
            'total' => Money::format($row['net'] + $row['tax']),
            'amount_net' => Money::format($row['net']),
            'amount_tax' => Money::format($row['tax']),
        ]);
    }

    /**
     * @param array{invoice: object, number: string, date: DateTimeImmutable} $row
     * @param array<string, mixed> $fields
     */
    private function insert(array $row, array $fields): int
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        $currency = FiscalInvoice::currency((int) $row['invoice']->userid);
        $id = (int) Capsule::table(Document::TABLE)->insertGetId($fields + [
            'source' => Document::SOURCE_IMPORT,
            'client_id' => (int) $row['invoice']->userid,
            'number' => $row['number'],
            'issue_date' => $row['date']->format('Y-m-d'),
            'currency' => $currency !== '' ? $currency : null,
            'state' => Document::STATE_EXTERNAL,
            'state_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Audit::log('document_imported', $row['number'], array_filter(['kind' => $fields['kind'], 'issue_date' => $row['date']->format('Y-m-d')]),
            $id, (int) $row['invoice']->id, null, Document::STATE_EXTERNAL);

        return $id;
    }

    /**
     * Saves the BNR rate of a document in another currency than RON, which
     * its PDF shows and its stornos use. Returns a warning when it cannot.
     */
    private function saveRate(int $documentId): ?string
    {
        $document = Capsule::table(Document::TABLE)->where('id', $documentId)->first();
        $currency = strtoupper((string) $document->currency);
        if ($currency === '' || $currency === 'RON') {
            return null;
        }
        try {
            $original = $document->original_document_id !== null ? Capsule::table(Document::TABLE)->where('id', $document->original_document_id)->first() : null;
            $rate = VatInRon::rateFor($document, $original, $currency, $this->rates, true);
        } catch (Throwable $e) {
            return "no BNR rate for {$document->number}: " . $e->getMessage();
        }
        Capsule::table(Document::TABLE)->where('id', $documentId)->update([
            'exchange_rate' => $rate->rate,
            'exchange_rate_date' => $rate->date->format('Y-m-d'),
            'exchange_rate_source' => $rate->source,
        ]);

        return null;
    }

    /**
     * Net and VAT already reversed by the stornos of a document (negative).
     *
     * @return array{0: int, 1: int}
     */
    private static function reversed(int $originalId): array
    {
        $net = 0;
        $tax = 0;
        foreach (Capsule::table(Document::TABLE)->where('kind', Document::KIND_STORNO)->where('original_document_id', $originalId)->get(['amount_net', 'amount_tax']) as $storno) {
            $net += Money::cents((string) $storno->amount_net);
            $tax += Money::cents((string) $storno->amount_tax);
        }

        return [$net, $tax];
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? Clock::parse($value) : null;
    }
}
