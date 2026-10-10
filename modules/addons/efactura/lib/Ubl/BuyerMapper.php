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
use WHMCS\Module\Addon\Efactura\ClientData\ClientFields;
use WHMCS\Module\Addon\Efactura\ClientData\FieldMap;
use WHMCS\Module\Addon\Efactura\Fiscal\ReportingPolicy;
use WHMCS\Module\Addon\Efactura\Romania\Cnp;
use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Romania\Cui;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * The buyer (BG-7) from the current WHMCS client profile and the client
 * fields the addon owns (CIUS-RO identifiers, research report 03, 3.3):
 * - Romanian company: CUI without RO as BT-47; RO + CUI as BT-48 only for a
 *   VAT payer: the client wrote the RO prefix, or the native WHMCS VAT number
 *   (tax_id) is RO + the same CUI;
 * - Romanian individual: CNP as BT-47, or 13 zeros when there is none; a
 *   valid CNP typed into the CUI field without a company name (older data)
 *   is read as the CNP of an individual;
 * - when the CUI field is empty, the native tax_id is used (EU VAT numbers);
 * - county (the WHMCS State/Region field) as ISO 3166-2:RO, Bucharest
 *   sector as the city.
 * Missing or unrecognized data is reported, never filled in by guessing.
 */
final class BuyerMapper
{
    public const TYPE_B2B = 'b2b';
    public const TYPE_B2C = 'b2c';

    /** @var list<array{rule: string, message: string}> */
    private array $issues = [];

    /**
     * @return array{party: Party, type: string, issues: list<array{rule: string, message: string}>}
     */
    public function map(int $clientId): array
    {
        $this->issues = [];
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if ($client === null) {
            $this->issue('MAP-CLIENT', Lang::get('map_client_missing', $clientId));

            return ['party' => new Party('', '', '', 'RO'), 'type' => self::TYPE_B2C, 'issues' => $this->issues];
        }

        $country = strtoupper(trim((string) $client->country));
        $company = Text::clean((string) $client->companyname);
        $person = Text::clean($client->firstname . ' ' . $client->lastname);
        $nativeTaxId = Text::clean((string) $client->tax_id);
        $fields = FieldMap::load()->stored($clientId);
        $taxId = Text::clean($fields['cui']);
        if ($taxId === '') {
            $taxId = $nativeTaxId;
        }
        $legacyCnp = $country === 'RO' && $company === '' && Cnp::isValid($taxId) ? Cnp::normalize($taxId) : null;
        $isCompany = ($company !== '' || $taxId !== '') && $legacyCnp === null;
        $countyText = Text::clean((string) $client->state);

        $vatId = null;
        $legalId = null;
        $county = null;
        $city = Text::clean((string) $client->city);

        if ($country === 'RO') {
            if ($isCompany) {
                if ($taxId === '') {
                    $this->issue('MAP-CUI', Lang::get('map_company_without_cui', $company, ClientFields::label('cui')));
                } else {
                    $cui = Cui::normalize($taxId);
                    if (!Cui::isValid($cui)) {
                        $this->issue('MAP-CUI', Lang::get('map_cui_invalid', $taxId));
                    }
                    $legalId = $cui;
                    $vatPayer = preg_match('/^\s*RO/i', $taxId) === 1
                        || (preg_match('/^\s*RO/i', $nativeTaxId) === 1 && Cui::normalize($nativeTaxId) === $cui);
                    $vatId = $vatPayer ? 'RO' . $cui : null;
                }
            } else {
                $cnp = Cnp::normalize($fields['cnp']);
                if ($cnp === '' && $legacyCnp !== null) {
                    $cnp = $legacyCnp;
                }
                if ($cnp === '') {
                    $legalId = Cnp::UNKNOWN;
                } elseif (Cnp::isValid($cnp) || $cnp === Cnp::UNKNOWN) {
                    $legalId = $cnp;
                } else {
                    $this->issue('MAP-CNP', Lang::get('map_cnp_invalid'));
                }
            }

            $label = Lang::get('field_native_state');
            if ($countyText === '') {
                $this->issue('MAP-COUNTY', Lang::get('map_county_missing', $label));
            } else {
                $county = Counties::fromText($countyText);
                if ($county === null) {
                    $this->issue('MAP-COUNTY', Lang::get('map_county_unknown', $countyText, $label));
                }
            }
            if ($county === Counties::BUCHAREST) {
                $sector = Counties::sectorFromText($city, (string) $client->address1, (string) $client->address2, $countyText);
                if ($sector === null) {
                    $this->issue('MAP-SECTOR', Lang::get('map_sector_missing'));
                } else {
                    $city = $sector;
                }
            }
        } else {
            $county = $countyText !== '' ? $countyText : null;
            if ($isCompany) {
                if (in_array($country, ReportingPolicy::EU_COUNTRIES, true)) {
                    $normalized = strtoupper(preg_replace('/[\s.\-]/', '', $taxId) ?? '');
                    if ($normalized === '') {
                        $this->issue('MAP-VATID', Lang::get('map_eu_vat_missing'));
                    } else {
                        // Greece uses EL in VAT numbers.
                        $prefix = $country === 'GR' ? 'EL' : $country;
                        $vatId = preg_match('/^[A-Z]{2}/', $normalized) === 1 ? $normalized : $prefix . $normalized;
                    }
                } elseif ($taxId === '') {
                    $this->issue('MAP-TAXID', Lang::get('map_foreign_id_missing'));
                } else {
                    $legalId = $taxId;
                }
            } else {
                $legalId = Cnp::UNKNOWN;
            }
        }

        $street = Text::clean((string) $client->address1);
        if ($street === '') {
            $this->issue('MAP-ADDRESS', Lang::get('map_address_missing'));
        }
        if ($city === '') {
            $this->issue('MAP-CITY', Lang::get('map_city_missing'));
        }

        $party = new Party(
            name: $isCompany && $company !== '' ? $company : $person,
            street: $street,
            city: $city,
            country: $country,
            county: $county,
            postcode: Text::clean((string) $client->postcode) ?: null,
            additionalStreet: Text::clean((string) $client->address2) ?: null,
            vatId: $vatId,
            legalId: $legalId,
            contactName: $isCompany && $company !== '' && $person !== '' ? $person : null,
            phone: Text::clean((string) $client->phonenumber) ?: null,
            email: Text::clean((string) $client->email) ?: null,
        );

        return ['party' => $party, 'type' => $isCompany ? self::TYPE_B2B : self::TYPE_B2C, 'issues' => $this->issues];
    }

    private function issue(string $rule, string $message): void
    {
        $this->issues[] = ['rule' => $rule, 'message' => $message];
    }
}
