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

namespace WHMCS\Module\Addon\Efactura\Ubl;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Exchange\ExchangeRate;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Fiscal\ReportingPolicy;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Builds the CIUS-RO XML of a fiscal document from the WHMCS data as it is
 * now (the current client profile, the invoice, the credit note).
 *
 * The amounts are the WHMCS amounts, never recomputed: line amounts, VAT and
 * totals are those the client sees on the WHMCS invoice. When they cannot
 * form a valid e-Factura (for example a VAT rounding outside the ANAF
 * tolerance), the document is stopped with an explanation instead.
 */
final class DocumentBuilder
{
    public const REASON_REFUND_FULL = 'refund_full';
    public const REASON_REFUND_PARTIAL = 'refund_partial';
    public const REASON_CANCEL = 'cancel';
    /** Cancelled after partial refunds: what was not refunded. */
    public const REASON_CANCEL_REST = 'cancel_rest';
    /** Issued by an admin: the whole invoice or a part of it. */
    public const REASON_MANUAL = 'manual';

    // Text written in the XML: the fiscal document is in Romanian.
    private const AE_REASON = 'Taxare inversă';
    private const E_REASON = 'Neimpozabil în România, art. 278 alin. (2) Cod fiscal';
    private const PARTIAL_STORNO_LINE = 'Stornare parțială factura %s';
    private const REST_STORNO_LINE = 'Stornare rest factura %s';

    /** @var list<array{rule: string, message: string}> */
    private array $issues = [];

    public function __construct(private readonly BnrRates $rates)
    {
    }

    public function build(object $document): BuildResult
    {
        $this->issues = [];
        $invoiceRow = Capsule::table('tblinvoices')->where('id', $document->invoice_id)->first();
        if ($invoiceRow === null) {
            return $this->fail('MAP-INVOICE', Lang::get('map_invoice_missing', (int) $document->invoice_id));
        }
        $storno = $document->kind === Document::KIND_STORNO;
        $original = null;
        if ($storno) {
            $original = Capsule::table(Document::TABLE)->where('id', $document->original_document_id)->first();
            if ($original === null || (string) $original->number === '') {
                return $this->fail('MAP-ORIGINAL', Lang::get('map_original_missing'));
            }
        }

        $clientId = (int) $document->client_id;
        $currency = strtoupper((string) Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $clientId)
            ->value('tblcurrencies.code'));
        if ($currency === '') {
            return $this->fail('MAP-CURRENCY', Lang::get('map_currency_missing'));
        }

        $buyer = (new BuyerMapper())->map($clientId);
        array_push($this->issues, ...$buyer['issues']);

        $untaxedCategory = $this->untaxedCategory($buyer['party'], $invoiceRow);
        $rate = Money::trimDecimal((string) $invoiceRow->taxrate);

        if ($storno && $document->reason === self::REASON_REFUND_PARTIAL) {
            [$lines, $taxCents] = $this->partialStornoLines($document, $original, $rate, $untaxedCategory);
        } elseif ($storno && $document->reason === self::REASON_CANCEL_REST) {
            [$lines, $taxCents] = $this->storedStornoLines($document, $original, $rate, $untaxedCategory, self::REST_STORNO_LINE);
        } elseif ($storno && $document->reason === self::REASON_MANUAL && !self::wholeInvoice($document, $invoiceRow)) {
            [$lines, $taxCents] = $this->storedStornoLines($document, $original, $rate, $untaxedCategory, self::PARTIAL_STORNO_LINE);
        } else {
            [$lines, $taxCents] = $this->invoiceLines($invoiceRow, $rate, $untaxedCategory);
            if ($storno) {
                $lines = array_map(static fn (Line $line): Line => self::negate($line), $lines);
                $taxCents = -$taxCents;
            }
        }

        $subtotals = $this->subtotals($lines, $taxCents);
        $issueDate = Clock::parse((string) $document->issue_date);
        $totalCents = array_sum(array_map(static fn (Line $line): int => $line->amountCents, $lines)) + $taxCents;

        $prepaid = 0;
        $dueDate = null;
        $paymentMeans = null;
        if (!$storno) {
            $paid = Money::cents((string) Capsule::table('tblaccounts')->where('invoiceid', $invoiceRow->id)->sum(Capsule::raw('amountin - amountout')));
            $prepaid = max(0, min($paid, $totalCents));
            if ($totalCents - $prepaid > 0) {
                $due = (string) $invoiceRow->duedate;
                $dueDate = $due !== '' && !str_starts_with($due, '0000') ? Clock::parse($due) : $issueDate;
            }
            $paymentMeans = PaymentMeansMapper::forGateway((string) $invoiceRow->paymentmethod, $currency);
        }

        $exchangeRate = null;
        $taxRon = null;
        if ($currency !== 'RON') {
            $exchangeRate = $this->exchangeRate($currency, $issueDate, $original);
            if ($exchangeRate !== null) {
                $taxRon = Money::multiply($taxCents, $exchangeRate->rate);
            }
        }

        $notes = $this->notes($document, $original, $invoiceRow, $subtotals, $exchangeRate, $taxRon, $currency);

        $invoice = new Invoice(
            number: (string) $document->number,
            issueDate: $issueDate,
            currency: $currency,
            seller: SellerMapper::party(),
            buyer: $buyer['party'],
            lines: $lines,
            taxSubtotals: $subtotals,
            taxTotalCents: $taxCents,
            prepaidCents: $prepaid,
            dueDate: $dueDate,
            taxTotalRonCents: $taxRon,
            notes: $notes,
            paymentMeans: $paymentMeans,
            precedingNumber: $original !== null ? (string) $original->number : null,
            precedingDate: $original !== null ? Clock::parse((string) $original->issue_date) : null,
        );

        array_push($this->issues, ...(new Validator())->validate($invoice));
        $xml = $this->issues === [] ? (new UblWriter())->write($invoice) : null;

        return new BuildResult($invoice, $xml, $this->issues, $buyer['type'], $exchangeRate);
    }

    /**
     * The lines of a WHMCS invoice, checked against its totals.
     *
     * @return array{0: list<Line>, 1: int} the lines and the VAT total
     */
    private function invoiceLines(object $invoice, string $rate, ?string $untaxedCategory): array
    {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice->id)->orderBy('id')->get(['description', 'amount', 'taxed']);
        $lines = [];
        $sum = 0;
        foreach ($items as $index => $item) {
            $amount = Money::cents((string) $item->amount);
            $sum += $amount;
            [$category, $lineRate] = $this->category((bool) $item->taxed, $rate, $untaxedCategory, (string) $item->description);
            $text = Text::item((string) $item->description);
            $lines[] = new Line(
                id: (string) ($index + 1),
                name: $text['name'],
                amountCents: $amount,
                category: $category,
                rate: $lineRate,
                description: $text['description'],
                note: $text['note'],
                quantity: $amount < 0 ? -1 : 1,
            );
        }

        $subtotal = Money::cents((string) $invoice->subtotal);
        $tax = Money::cents((string) $invoice->tax);
        $total = Money::cents((string) $invoice->total);
        if ($sum !== $subtotal) {
            $this->issue('MAP-SUBTOTAL', Lang::get('map_subtotal', Money::format($sum), Money::format($subtotal)));
        }
        if (Money::cents((string) $invoice->tax2) !== 0) {
            $this->issue('MAP-TAX2', Lang::get('map_tax2', (string) $invoice->tax2));
        }
        if ($subtotal + $tax !== $total) {
            $this->issue('MAP-TOTAL', Lang::get('map_total', Money::format($subtotal), Money::format($tax), Money::format($total)));
        }

        return [$lines, $tax];
    }

    /**
     * One line for a partial refund, with the net and VAT of the WHMCS credit note.
     *
     * @return array{0: list<Line>, 1: int}
     */
    private function partialStornoLines(object $document, object $original, string $invoiceRate, ?string $untaxedCategory): array
    {
        $note = Capsule::table('tblbillingnotes')->where('id', $document->billing_note_id)->first();
        if ($note === null) {
            $this->issue('MAP-NOTE', Lang::get('map_note_missing', (int) $document->billing_note_id));

            return [[], 0];
        }
        $net = Money::cents((string) $note->subtotal);
        $tax = Money::cents((string) $note->tax);
        if (Money::cents((string) $note->tax2) !== 0) {
            $this->issue('MAP-TAX2', Lang::get('map_tax2', (string) $note->tax2));
        }
        if ($net + $tax !== Money::cents((string) $note->total)) {
            $this->issue('MAP-TOTAL', Lang::get('map_total', Money::format($net), Money::format($tax), (string) $note->total));
        }
        $noteRate = Money::trimDecimal((string) $note->taxrate);
        [$category, $rate] = $this->category($tax !== 0, $noteRate !== '0' ? $noteRate : $invoiceRate, $untaxedCategory, '');
        $line = new Line(
            id: '1',
            name: sprintf(self::PARTIAL_STORNO_LINE, $original->number),
            amountCents: -$net,
            category: $category,
            rate: $rate,
            quantity: -1,
        );

        return [[$line], -$tax];
    }

    /**
     * Whether a storno reverses the whole invoice (its lines are then negated).
     */
    private static function wholeInvoice(object $document, object $invoice): bool
    {
        return $document->amount_net !== null && $document->amount_tax !== null
            && Money::cents((string) $document->amount_net) === -Money::cents((string) $invoice->subtotal)
            && Money::cents((string) $document->amount_tax) === -Money::cents((string) $invoice->tax);
    }

    /**
     * One line with the amounts fixed when the storno was issued: the rest of
     * a cancelled invoice, or a part reversed by an admin.
     *
     * @return array{0: list<Line>, 1: int}
     */
    private function storedStornoLines(object $document, object $original, string $invoiceRate, ?string $untaxedCategory, string $lineName): array
    {
        if ($document->amount_net === null || $document->amount_tax === null) {
            $this->issue('MAP-STORNO', Lang::get('map_storno_amounts'));

            return [[], 0];
        }
        $net = Money::cents((string) $document->amount_net);
        $tax = Money::cents((string) $document->amount_tax);
        [$category, $rate] = $this->category($tax !== 0, $invoiceRate, $untaxedCategory, '');
        $line = new Line(
            id: '1',
            name: sprintf($lineName, $original->number),
            amountCents: $net,
            category: $category,
            rate: $rate,
            quantity: -1,
        );

        return [[$line], $tax];
    }

    /**
     * VAT category and rate of a line.
     *
     * @return array{0: string, 1: ?string}
     */
    private function category(bool $taxed, string $rate, ?string $untaxedCategory, string $description): array
    {
        if ($taxed && $rate !== '0') {
            return ['S', $rate];
        }
        if ($untaxedCategory !== null) {
            return [$untaxedCategory, '0'];
        }
        $this->issue('MAP-VAT', Lang::get('map_untaxed_line', Text::cut(Text::clean($description), 60)[0]));

        return ['S', $rate];
    }

    /**
     * The category of the lines without VAT: reverse charge for EU companies,
     * not taxable in Romania for clients outside the EU. Null for clients in
     * Romania, where an untaxed line cannot be classified safely.
     */
    private function untaxedCategory(Party $buyer, object $invoice): ?string
    {
        if ($buyer->country === 'RO' || Money::cents((string) $invoice->tax) !== 0) {
            return null;
        }
        if (in_array($buyer->country, ReportingPolicy::EU_COUNTRIES, true)) {
            return $buyer->vatId !== null ? 'AE' : null;
        }

        return 'E';
    }

    /**
     * VAT breakdown per category and rate; the VAT of the standard rate is
     * the WHMCS VAT.
     *
     * @param list<Line> $lines
     * @return list<TaxSubtotal>
     */
    private function subtotals(array $lines, int $taxCents): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $line->category . '|' . $line->rate;
            $groups[$key] ??= ['category' => $line->category, 'rate' => $line->rate, 'taxable' => 0];
            $groups[$key]['taxable'] += $line->amountCents;
        }

        $subtotals = [];
        $standardDone = false;
        foreach ($groups as $group) {
            $tax = 0;
            if ($group['category'] === 'S' && !$standardDone) {
                $tax = $taxCents;
                $standardDone = true;
            }
            $subtotals[] = new TaxSubtotal(
                $group['category'],
                $group['rate'],
                $group['taxable'],
                $tax,
                $group['category'] === 'AE' ? 'VATEX-EU-AE' : null,
                match ($group['category']) {
                    'AE' => self::AE_REASON,
                    'E' => self::E_REASON,
                    default => null,
                },
            );
        }

        return $subtotals;
    }

    private function exchangeRate(string $currency, \DateTimeImmutable $issueDate, ?object $original): ?ExchangeRate
    {
        // A storno converts its VAT at the rate of the invoice it corrects,
        // so that the VAT in RON cancels exactly.
        if ($original !== null && $original->exchange_rate !== null) {
            return new ExchangeRate($currency, Money::trimDecimal((string) $original->exchange_rate), Clock::parse((string) $original->exchange_rate_date), (string) $original->exchange_rate_source);
        }
        try {
            return $this->rates->forDocumentDate($currency, $issueDate);
        } catch (Throwable $e) {
            $this->issue('MAP-RATE', Lang::get('map_exchange_rate', $currency, $e->getMessage()));

            return null;
        }
    }

    /**
     * BT-22: storno reference, WHMCS invoice notes and the legal mentions.
     *
     * @param list<TaxSubtotal> $subtotals
     * @return list<string>
     */
    private function notes(object $document, ?object $original, object $invoice, array $subtotals, ?ExchangeRate $rate, ?int $taxRon, string $currency): array
    {
        $notes = [];
        if ($original !== null) {
            $date = Clock::parse((string) $original->issue_date)->format('d.m.Y');
            $notes[] = match ((string) $document->reason) {
                self::REASON_REFUND_PARTIAL => "Stornare parțială a facturii {$original->number} din {$date} (rambursare parțială)",
                self::REASON_CANCEL => "Stornare totală a facturii {$original->number} din {$date} (factură anulată)",
                self::REASON_CANCEL_REST => "Stornare a restului facturii {$original->number} din {$date} (factură anulată după rambursări parțiale)",
                self::REASON_MANUAL => self::wholeInvoice($document, $invoice)
                    ? "Stornare totală a facturii {$original->number} din {$date}"
                    : "Stornare parțială a facturii {$original->number} din {$date}",
                default => "Stornare totală a facturii {$original->number} din {$date} (rambursare)",
            };
        }

        $categories = array_map(static fn (TaxSubtotal $subtotal): string => $subtotal->category, $subtotals);
        if (in_array('S', $categories, true) && Settings::bool('company_vat_on_collection')) {
            $notes[] = 'TVA la încasare';
        }
        if (in_array('AE', $categories, true)) {
            $notes[] = 'Taxare inversă';
        }
        if ($rate !== null && $taxRon !== null) {
            $notes[] = sprintf(
                'Curs %s 1 %s = %s RON din %s; TVA: %s RON',
                $rate->source,
                $currency,
                str_replace('.', ',', $rate->rate),
                $rate->date->format('d.m.Y'),
                str_replace('.', ',', Money::format($taxRon))
            );
        }

        $text = implode('; ', Text::lines((string) $invoice->notes));
        while ($text !== '' && count($notes) < 20) {
            [$chunk, $text] = Text::cut($text, 300);
            $notes[] = $chunk;
        }

        return $notes;
    }

    private static function negate(Line $line): Line
    {
        return new Line(
            id: $line->id,
            name: $line->name,
            amountCents: -$line->amountCents,
            category: $line->category,
            rate: $line->rate,
            description: $line->description,
            note: $line->note,
            quantity: -$line->quantity,
            unitCode: $line->unitCode,
            sellerItemId: $line->sellerItemId,
        );
    }

    private function fail(string $rule, string $message): BuildResult
    {
        $this->issue($rule, $message);

        return new BuildResult(null, null, $this->issues, BuyerMapper::TYPE_B2C);
    }

    private function issue(string $rule, string $message): void
    {
        $this->issues[] = ['rule' => $rule, 'message' => $message];
    }
}
