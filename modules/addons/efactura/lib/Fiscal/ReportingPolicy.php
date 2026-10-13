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
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * Which paid WHMCS invoices are fiscal invoices, and which fiscal invoices
 * are reported to RO e-Factura.
 *
 * Not fiscal (they must not take a number from the fiscal series): Mass Pay
 * containers, which only group other invoices; Add Funds and zero-total
 * invoices when the settings exclude them.
 *
 * Fiscal but not reported: invoices to EU companies under reverse charge, to
 * EU consumers under the Union OSS scheme and to clients outside the EU, when
 * the settings exclude them. They keep their fiscal number.
 */
final class ReportingPolicy
{
    public const NOT_FISCAL_MASS_PAY = 'mass_pay';
    public const NOT_FISCAL_ADD_FUNDS = 'add_funds';
    public const NOT_FISCAL_ZERO_TOTAL = 'zero_total';

    /** EU member states as WHMCS country codes (Greece is GR). */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Why the invoice is not a fiscal invoice, or null when it is one.
     */
    public static function nonFiscalReason(int $invoiceId): ?string
    {
        $types = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->pluck('type')
            ->map(static fn ($type): string => (string) $type)->unique()->values()->all();
        if ($types === ['Invoice']) {
            return self::NOT_FISCAL_MASS_PAY;
        }
        if ($types === ['AddFunds'] && Settings::bool('exclude_add_funds')) {
            return self::NOT_FISCAL_ADD_FUNDS;
        }
        $total = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
        if (Settings::bool('exclude_zero_total') && round((float) $total, 2) === 0.0) {
            return self::NOT_FISCAL_ZERO_TOTAL;
        }

        return null;
    }

    /**
     * Why a fiscal invoice is not reported to RO e-Factura, or null when it
     * is reported.
     */
    public static function exclusionReason(int $invoiceId): ?string
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid', 'tax', 'tax2']);
        if ($invoice === null) {
            return null;
        }
        $country = strtoupper((string) Capsule::table('tblclients')->where('id', $invoice->userid)->value('country'));
        if ($country === 'RO' || $country === '') {
            return null;
        }
        if (!in_array($country, self::EU_COUNTRIES, true)) {
            return Settings::bool('exclude_non_eu') ? Document::EXCLUDED_NON_EU : null;
        }
        // An EU client invoiced without VAT is a company under reverse charge.
        $tax = round((float) $invoice->tax + (float) $invoice->tax2, 2);
        if ($tax === 0.0 && Settings::bool('exclude_eu_reverse_charge')) {
            return Document::EXCLUDED_EU_REVERSE_CHARGE;
        }
        // An EU client charged VAT is a consumer. Under the Union OSS scheme
        // the place of supply is the country of the client, declared through
        // OSS; without it, the place is Romania and the invoice is reported.
        if ($tax !== 0.0 && Settings::bool('exclude_eu_oss')) {
            return Document::EXCLUDED_EU_OSS;
        }

        return null;
    }

    /**
     * The VAT rate WHMCS charges clients in Romania (its level 1 tax rule),
     * or null without one.
     */
    public static function romanianRate(): ?string
    {
        $rate = Capsule::table('tbltax')->where('level', 1)->where('country', 'RO')->where('state', '')->value('taxrate');

        return $rate === null ? null : Money::trimDecimal((string) $rate);
    }

    /**
     * The other EU countries for which WHMCS charges a VAT rate that is not
     * the Romanian one: right only under the Union OSS scheme.
     *
     * @return array<string, string> rate by country code
     */
    public static function foreignEuRates(): array
    {
        $romanian = self::romanianRate();
        if ($romanian === null) {
            return [];
        }
        $rates = [];
        $rules = Capsule::table('tbltax')->where('level', 1)->whereIn('country', self::EU_COUNTRIES)->where('country', '!=', 'RO')
            ->orderBy('country')->get(['country', 'taxrate']);
        foreach ($rules as $rule) {
            $rate = Money::trimDecimal((string) $rule->taxrate);
            if ($rate !== $romanian && $rate !== '0') {
                $rates[(string) $rule->country] = $rate;
            }
        }

        return $rates;
    }
}
