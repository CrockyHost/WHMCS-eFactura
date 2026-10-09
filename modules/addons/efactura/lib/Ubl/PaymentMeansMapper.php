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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Iban;

/**
 * Payment means code (BT-81, UNCL 4461) from the WHMCS payment gateway,
 * as mapped in the settings, with the seller account for bank transfers.
 */
final class PaymentMeansMapper
{
    /** Map value meaning: do not write payment instructions. */
    public const NONE = 'none';

    /**
     * The code used when the gateway is not mapped yet.
     */
    public static function defaultCode(string $gateway): string
    {
        $gateway = strtolower($gateway);

        return match (true) {
            $gateway === 'banktransfer' => '30',
            $gateway === 'stripe' || str_contains($gateway, 'card') => '48',
            default => '68',
        };
    }

    public static function codeFor(string $gateway): string
    {
        $map = Settings::map('payment_means');

        return (string) ($map[$gateway] ?? self::defaultCode($gateway));
    }

    public static function forGateway(string $gateway, string $currency): ?PaymentMeans
    {
        $code = self::codeFor($gateway);
        if ($code === self::NONE || $code === '') {
            return null;
        }
        if (!in_array($code, PaymentMeans::ACCOUNT_CODES, true)) {
            return new PaymentMeans($code);
        }

        $iban = Iban::normalize(Settings::string(match (strtoupper($currency)) {
            'RON' => 'iban_ron',
            'EUR' => 'iban_eur',
            default => 'iban_' . strtolower($currency),
        }));

        return new PaymentMeans(
            $code,
            $iban !== '' ? $iban : null,
            Text::clean(Settings::string('company_legal_name')) ?: null,
            Settings::string('bank_bic') ?: null,
        );
    }

    /**
     * Active WHMCS gateways: system name => display name.
     *
     * @return array<string, string>
     */
    public static function gateways(): array
    {
        $gateways = [];
        foreach (Capsule::table('tblpaymentgateways')->where('setting', 'name')->orderBy('order')->get(['gateway', 'value']) as $row) {
            $gateways[(string) $row->gateway] = (string) $row->value;
        }

        return $gateways;
    }
}
