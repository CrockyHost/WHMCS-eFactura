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

use WHMCS\Module\Addon\Efactura\Romania\Cnp;
use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Support\Iban;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Local checks of an Invoice before it is sent, for the rules that most often
 * get documents rejected (EN 16931 BR-CO/BR-S and CIUS-RO BR-RO): mandatory
 * fields, lengths, county and Bucharest sector, identifiers and their check
 * digits, sums, VAT tolerance, payment account. The full Schematron runs in
 * the tests and at ANAF.
 */
final class Validator
{
    /** @var list<array{rule: string, message: string}> */
    private array $issues = [];

    /**
     * @return list<array{rule: string, message: string}> empty when the invoice can be sent
     */
    public function validate(Invoice $invoice): array
    {
        $this->issues = [];

        $this->length(Lang::get('ubl_field_number'), $invoice->number, 200, 'BR-RO-L200');
        if (preg_match('/\d/', $invoice->number) !== 1) {
            $this->add('BR-RO-010', Lang::get('ubl_number_digit', $invoice->number));
        }

        $this->party($invoice->seller, Lang::get('ubl_party_seller'), true);
        $this->party($invoice->buyer, Lang::get('ubl_party_buyer'), false);

        if (count($invoice->notes) > 20) {
            $this->add('BR-RO-A020', Lang::get('ubl_notes_count'));
        }
        foreach ($invoice->notes as $note) {
            $this->length(Lang::get('ubl_field_note'), $note, 300, 'BR-RO-L300');
        }

        $this->lines($invoice);
        $this->taxes($invoice);

        if ($invoice->currency !== 'RON' && $invoice->taxTotalRonCents === null) {
            $this->add('BR-RO-030', Lang::get('ubl_ron_vat', $invoice->currency));
        }
        if ($invoice->payableCents() > 0 && $invoice->dueDate === null) {
            $this->add('BR-CO-25', Lang::get('ubl_due_date'));
        }
        $means = $invoice->paymentMeans;
        if ($means !== null && in_array($means->code, PaymentMeans::ACCOUNT_REQUIRED, true)
            && ($means->iban === null || !Iban::isValid($means->iban))) {
            $this->add('BR-61', Lang::get('ubl_iban', $means->code, $invoice->currency));
        }

        return $this->issues;
    }

    private function party(Party $party, string $label, bool $seller): void
    {
        $required = ['ubl_field_name' => $party->name, 'ubl_field_street' => $party->street, 'ubl_field_city' => $party->city];
        foreach ($required as $key => $value) {
            if (trim($value) === '') {
                $this->add('BR-RO-080', Lang::get('ubl_required', $label, Lang::get($key)));
            }
        }
        $limits = [
            ['ubl_field_name', $party->name, 200, 'BR-RO-L200'],
            ['ubl_field_trade_name', $party->tradeName, 200, 'BR-RO-L200'],
            ['ubl_field_street', $party->street, 150, 'BR-RO-L150'],
            ['ubl_field_additional_street', $party->additionalStreet, 100, 'BR-RO-L100'],
            ['ubl_field_city', $party->city, 50, 'BR-RO-L050'],
            ['ubl_field_postcode', $party->postcode, 20, 'BR-RO-L020'],
            ['ubl_field_contact', $party->contactName, 100, 'BR-RO-L100'],
            ['ubl_field_phone', $party->phone, 100, 'BR-RO-L100'],
            ['ubl_field_email', $party->email, 100, 'BR-RO-L100'],
            ['ubl_field_legal_form', $party->legalForm, 1000, 'BR-RO-L1000'],
        ];
        foreach ($limits as [$key, $value, $max, $rule]) {
            $this->length($label . ' - ' . Lang::get($key), $value, $max, $rule);
        }

        if ($party->country === 'RO') {
            if ($party->county === null || !Counties::isValid($party->county)) {
                $this->add('BR-RO-110', Lang::get('ubl_county', $label, (string) $party->county));
            } elseif ($party->county === Counties::BUCHAREST && !Counties::isSector($party->city)) {
                $this->add('BR-RO-100', Lang::get('ubl_sector', $label, $party->city));
            }
        }

        if ($party->vatId !== null && preg_match('/^[A-Z]{2}[A-Z0-9]+$/', $party->vatId) !== 1) {
            $this->add('BR-CO-09', Lang::get('ubl_vat_prefix', $label, $party->vatId));
        }
        if ($party->vatId !== null && str_starts_with($party->vatId, 'RO') && !Cui::isValid($party->vatId)) {
            $this->add('BR-RO-CUI', Lang::get('ubl_cui', $label, $party->vatId));
        }

        if ($seller) {
            if (($party->vatId ?? '') === '' && ($party->taxRegistrationId ?? '') === '') {
                $this->add('BR-RO-065', Lang::get('ubl_seller_id'));
            }
            return;
        }

        if (($party->vatId ?? '') === '' && ($party->legalId ?? '') === '') {
            $this->add('BR-RO-120', Lang::get('ubl_buyer_id'));
        }
        $legalId = (string) $party->legalId;
        if ($party->country === 'RO' && $legalId !== '' && $legalId !== Cnp::UNKNOWN) {
            $valid = strlen($legalId) === 13 ? Cnp::isValid($legalId) : Cui::isValid($legalId);
            if (!$valid) {
                // A CNP is personal data: only its ends are shown.
                $this->add('BR-RO-ID', strlen($legalId) === 13
                    ? Lang::get('ubl_cnp', $label, self::mask($legalId))
                    : Lang::get('ubl_cui', $label, $legalId));
            }
        }
    }

    private function lines(Invoice $invoice): void
    {
        if ($invoice->lines === []) {
            $this->add('BR-16', Lang::get('ubl_no_lines'));
        }
        foreach ($invoice->lines as $line) {
            $label = Lang::get('ubl_line', $line->id);
            if (trim($line->name) === '') {
                $this->add('BR-25', Lang::get('ubl_required', $label, Lang::get('ubl_field_item_name')));
            }
            $this->length($label . ' - ' . Lang::get('ubl_field_item_name'), $line->name, 100, 'BR-RO-L100');
            $this->length($label . ' - ' . Lang::get('ubl_field_item_description'), $line->description, 200, 'BR-RO-L200');
            $this->length($label . ' - ' . Lang::get('ubl_field_line_note'), $line->note, 300, 'BR-RO-L300');
            if (abs($line->quantity) !== 1 || ($line->amountCents !== 0 && ($line->amountCents < 0) !== ($line->quantity < 0))) {
                $this->add('BR-27', Lang::get('ubl_line_sign', $line->id));
            }
        }
    }

    private function taxes(Invoice $invoice): void
    {
        $byCategory = [];
        foreach ($invoice->lines as $line) {
            $key = $line->category . '|' . ($line->rate === null ? '' : Money::trimDecimal($line->rate));
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $line->amountCents;
        }

        $sum = 0;
        foreach ($invoice->taxSubtotals as $subtotal) {
            $sum += $subtotal->taxCents;
            $rate = $subtotal->rate === null ? '' : Money::trimDecimal($subtotal->rate);
            $key = $subtotal->category . '|' . $rate;
            $lines = $byCategory[$key] ?? null;
            unset($byCategory[$key]);
            if ($lines !== $subtotal->taxableCents) {
                $this->add('BR-' . $subtotal->category . '-08', Lang::get('ubl_tax_base', $subtotal->category, $rate, Money::format($subtotal->taxableCents), Money::format((int) $lines)));
            }

            if ($subtotal->category === 'S') {
                $expected = Money::percent($subtotal->taxableCents, $rate);
                if (abs($subtotal->taxCents - $expected) >= 100) {
                    $this->add('BR-S-09', Lang::get('ubl_tax_rate', Money::format($subtotal->taxCents), Money::format($subtotal->taxableCents), $rate, Money::format($expected)));
                }
                if ($subtotal->exemptionCode !== null || $subtotal->exemptionReason !== null) {
                    $this->add('BR-S-10', Lang::get('ubl_exempt_forbidden'));
                }
            } else {
                if ($subtotal->taxCents !== 0) {
                    $this->add('BR-' . $subtotal->category . '-09', Lang::get('ubl_exempt_tax', $subtotal->category));
                }
                if (($subtotal->exemptionCode ?? '') === '' && ($subtotal->exemptionReason ?? '') === '') {
                    $this->add('BR-' . $subtotal->category . '-10', Lang::get('ubl_exempt_reason', $subtotal->category));
                }
                $this->length(Lang::get('ubl_field_exemption'), $subtotal->exemptionReason, 100, 'BR-RO-L100');
            }
            if ($subtotal->category === 'AE' && ($invoice->buyer->vatId ?? '') === '' && ($invoice->buyer->legalId ?? '') === '') {
                $this->add('BR-AE-02', Lang::get('ubl_buyer_id'));
            }
        }
        foreach (array_keys($byCategory) as $key) {
            [$category, $rate] = explode('|', $key);
            $this->add('BR-' . $category . '-01', Lang::get('ubl_missing_category', $category, $rate));
        }
        if ($sum !== $invoice->taxTotalCents) {
            $this->add('BR-CO-14', Lang::get('ubl_tax_total', Money::format($sum), Money::format($invoice->taxTotalCents)));
        }
    }

    private function length(string $label, ?string $value, int $max, string $rule): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $this->add($rule, Lang::get('ubl_too_long', $label, mb_strlen($value), $max));
        }
    }

    private function add(string $rule, string $message): void
    {
        $this->issues[] = ['rule' => $rule, 'message' => $message];
    }

    /**
     * Shows only the start and the end of a personal identifier.
     */
    private static function mask(string $value): string
    {
        return strlen($value) <= 4 ? $value : substr($value, 0, 1) . str_repeat('*', strlen($value) - 3) . substr($value, -2);
    }
}
