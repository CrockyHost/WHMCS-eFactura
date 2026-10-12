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
use WHMCS\Module\Addon\Efactura\Support\WhmcsText;

/**
 * The default invoice references of a client: project (BT-11), contract
 * (BT-12), purchase order (BT-13), receiving advice (BT-15), buyer
 * accounting reference (BT-19) and buyer identifier (BT-46). An admin fills
 * them in the client profile, in admin-only client fields the addon owns;
 * they are not printed on the invoice and not shown in the client area.
 */
final class InvoiceReferences
{
    /** field role => business term */
    public const TERMS = [
        'bt11' => 'BT-11',
        'bt12' => 'BT-12',
        'bt13' => 'BT-13',
        'bt15' => 'BT-15',
        'bt19' => 'BT-19',
        'bt46' => 'BT-46',
    ];

    /**
     * The references filled in for a client, as text.
     *
     * @return array<string, string> business term ("BT-11") => value, only the filled ones
     */
    public static function defaults(int $clientId): array
    {
        $ids = ClientFields::referenceIds();
        $values = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->whereIn('fieldid', array_values($ids))
            ->pluck('value', 'fieldid')
            ->all();

        $defaults = [];
        foreach (self::TERMS as $role => $term) {
            // WHMCS stores what its forms save HTML-escaped ("A &amp; B").
            $value = trim(WhmcsText::decode($values[$ids[$role]] ?? ''));
            if ($value !== '') {
                $defaults[$term] = $value;
            }
        }

        return $defaults;
    }
}
