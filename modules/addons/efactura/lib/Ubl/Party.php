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

/**
 * Seller (BG-4) or buyer (BG-7).
 */
final class Party
{
    public function __construct(
        /** BT-27 / BT-44 */
        public readonly string $name,
        /** BT-35 / BT-50 */
        public readonly string $street,
        /** BT-37 / BT-52; SECTOR1..SECTOR6 in Bucharest */
        public readonly string $city,
        /** BT-40 / BT-55, ISO 3166-1 alpha-2 */
        public readonly string $country,
        /** BT-39 / BT-54, ISO 3166-2:RO for Romania */
        public readonly ?string $county = null,
        /** BT-38 / BT-53 */
        public readonly ?string $postcode = null,
        /** BT-36 / BT-51 */
        public readonly ?string $additionalStreet = null,
        /** BT-28 / BT-45 */
        public readonly ?string $tradeName = null,
        /** BT-31 / BT-48: VAT identifier with country prefix */
        public readonly ?string $vatId = null,
        /** BT-32: tax registration identifier of a seller that is not a VAT payer */
        public readonly ?string $taxRegistrationId = null,
        /** BT-30 / BT-47: legal registration identifier (CUI, CNP, 13 zeros) */
        public readonly ?string $legalId = null,
        /** BT-33: additional legal information (seller) */
        public readonly ?string $legalForm = null,
        public readonly ?string $contactName = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {
    }
}
