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

namespace WHMCS\Module\Addon\Efactura\ClientData;

/**
 * Address rules for Romanian clients and contacts: a county the UBL
 * generator recognizes and, in Bucharest, a sector. Other countries are not
 * checked. A rule is skipped when the client cannot change its field.
 */
final class AddressRules
{
    /**
     * @param array<string, string> $address country, state, city, address1, address2
     * @param list<string> $locked fields the client cannot change
     * @return list<Issue>
     */
    public static function check(array $address, array $locked = []): array
    {
        if (strtoupper(trim($address['country'] ?? '')) !== 'RO') {
            return [];
        }

        $issues = [];
        $state = trim($address['state'] ?? '');
        $county = CountyField::canonical($state);
        if ($county === null) {
            if (!in_array('state', $locked, true)) {
                $issues[] = new Issue('state', 'cd_error_county');
            }

            return $issues;
        }

        $sector = CountyField::sector(
            $address['city'] ?? '',
            $address['address1'] ?? '',
            $address['address2'] ?? '',
            $state
        );
        if ($county === CountyField::bucharest() && $sector === null && !in_array('city', $locked, true)) {
            $issues[] = new Issue('city', 'cd_error_sector');
        }

        return $issues;
    }
}
