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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Romania\Cnp;

/**
 * Rows added to the "Clients Information" panel of the admin client
 * summary for a Romanian client: CUI and trade register number for a legal
 * entity, the CNP for an individual who gave one, nothing otherwise.
 */
final class ClientSummary
{
    /**
     * @return list<array{0: string, 1: string}> label, value
     */
    public static function rows(int $clientId, Texts $texts): array
    {
        $client = $clientId > 0 ? Capsule::table('tblclients')->where('id', $clientId)->first(['country', 'companyname', 'tax_id']) : null;
        if ($client === null || strtoupper(trim((string) $client->country)) !== 'RO') {
            return [];
        }

        $values = FieldMap::load()->stored($clientId);
        if (IdentityRules::infer(FieldMap::text($client->companyname), $values['cui'], FieldMap::text($client->tax_id)) === IdentityRules::COMPANY) {
            return [
                [$texts->get('client_field_role_cui'), $values['cui']],
                [$texts->get('client_field_role_regcom'), $values['regcom']],
            ];
        }

        // An individual: the CNP field, or a CNP still kept in the CUI field.
        $cnp = $values['cnp'] !== '' ? $values['cnp'] : (Cnp::isValid($values['cui']) ? $values['cui'] : '');

        return $cnp === '' ? [] : [[$texts->get('client_field_role_cnp'), $cnp]];
    }
}
