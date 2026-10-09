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

use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Settings\Settings;

/**
 * The seller (BG-4) from the addon settings.
 */
final class SellerMapper
{
    public static function party(): Party
    {
        $cui = Cui::normalize(Settings::string('company_cui'));
        $vatPayer = Settings::bool('company_vat_payer');
        $regCom = Text::clean(Settings::string('company_reg_com'));
        $capital = Text::clean(Settings::string('company_share_capital'));

        return new Party(
            name: Text::clean(Settings::string('company_legal_name')),
            street: Text::clean(Settings::string('company_street')),
            city: Text::clean(Settings::string('company_city')),
            country: 'RO',
            county: Settings::string('company_county') ?: null,
            postcode: Text::clean(Settings::string('company_postcode')) ?: null,
            tradeName: Text::clean(Settings::string('company_trade_name')) ?: null,
            vatId: $vatPayer && $cui !== '' ? 'RO' . $cui : null,
            taxRegistrationId: !$vatPayer && $cui !== '' ? $cui : null,
            legalId: $regCom !== '' ? $regCom : null,
            legalForm: $capital !== '' ? 'Capital social: ' . $capital : null,
            contactName: Text::clean(Settings::string('company_contact_name')) ?: null,
            phone: Text::clean(Settings::string('company_phone')) ?: null,
            email: Text::clean(Settings::string('company_email')) ?: null,
        );
    }
}
