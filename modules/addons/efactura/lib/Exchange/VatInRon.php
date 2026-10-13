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

namespace WHMCS\Module\Addon\Efactura\Exchange;

use RuntimeException;
use WHMCS\Module\Addon\Efactura\Fiscal\Clock;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * The VAT of a foreign-currency fiscal document in RON (art. 319 alin. (23)
 * Fiscal Code) and its mention: in the XML (BT-22, in Romanian), and in the
 * PDF and on the invoice page (in the language of the client). Both are made
 * here, from the same rate, so they cannot differ.
 */
final class VatInRon
{
    /** The mention of the XML; the language files give it for the PDF ("vat_in_ron"). */
    private const NOTE = 'Curs %1$s 1 %2$s = %3$s RON din %4$s; TVA: %5$s RON';

    private function __construct(
        public readonly string $currency,
        public readonly ExchangeRate $rate,
        public readonly int $taxRonCents,
    ) {
    }

    public static function of(string $currency, int $taxCents, ExchangeRate $rate): self
    {
        return new self(strtoupper($currency), $rate, Money::multiply($taxCents, $rate->rate));
    }

    /**
     * The rate a fiscal document converts its VAT with: the one saved on it
     * when its XML was built, for a storno the one of the invoice it corrects
     * (so that the VAT in RON cancels exactly), otherwise the BNR rate for its
     * issue date. With $download false, only the rates already downloaded:
     * null when the rate is not among them.
     *
     * @throws RuntimeException when the rate cannot be downloaded
     */
    public static function rateFor(object $document, ?object $original, string $currency, BnrRates $rates, bool $download): ?ExchangeRate
    {
        foreach ([$document, $original] as $source) {
            if ($source !== null && ($source->exchange_rate ?? null) !== null) {
                return new ExchangeRate(
                    strtoupper($currency),
                    Money::trimDecimal((string) $source->exchange_rate),
                    Clock::parse((string) $source->exchange_rate_date),
                    (string) $source->exchange_rate_source,
                );
            }
        }
        $date = Clock::parse((string) $document->issue_date);

        return $download ? $rates->forDocumentDate($currency, $date) : $rates->downloaded($currency, $date);
    }

    /**
     * The mention in the XML.
     */
    public function note(): string
    {
        return $this->format(self::NOTE, ',');
    }

    /**
     * The same mention in "romanian" or "english", from the language files.
     */
    public function text(string $language): string
    {
        return $this->format(Lang::in($language, 'vat_in_ron'), $language === 'romanian' ? ',' : '.');
    }

    private function format(string $template, string $decimalSeparator): string
    {
        return sprintf(
            $template,
            $this->rate->source,
            $this->currency,
            str_replace('.', $decimalSeparator, $this->rate->rate),
            $this->rate->date->format('d.m.Y'),
            str_replace('.', $decimalSeparator, Money::format($this->taxRonCents)),
        );
    }
}
