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

namespace WHMCS\Module\Addon\Efactura\ClientData\Anaf;

/**
 * A company as published by ANAF, already shaped like the WHMCS client
 * fields: county as the dropdown name, Bucharest sector as the city.
 */
final class CompanyRecord
{
    public function __construct(
        public readonly string $cui,
        public readonly string $name,
        public readonly string $regCom,
        public readonly string $address1,
        public readonly string $address2,
        public readonly string $city,
        public readonly string $county,
        public readonly string $postcode,
        public readonly bool $vatPayer,
        public readonly bool $vatOnCollection,
        public readonly bool $inactive,
        public readonly bool $deregistered,
        public readonly bool $eInvoiceRegistry,
        public readonly string $registrationState,
    ) {
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $bool = static fn (string $key): bool => (bool) ($data[$key] ?? false);

        return new self(
            cui: $string('cui'),
            name: $string('name'),
            regCom: $string('regCom'),
            address1: $string('address1'),
            address2: $string('address2'),
            city: $string('city'),
            county: $string('county'),
            postcode: $string('postcode'),
            vatPayer: $bool('vatPayer'),
            vatOnCollection: $bool('vatOnCollection'),
            inactive: $bool('inactive'),
            deregistered: $bool('deregistered'),
            eInvoiceRegistry: $bool('eInvoiceRegistry'),
            registrationState: $string('registrationState'),
        );
    }
}
