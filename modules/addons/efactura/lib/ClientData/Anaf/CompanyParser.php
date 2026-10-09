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

use WHMCS\Module\Addon\Efactura\ClientData\CountyField;
use WHMCS\Module\Addon\Efactura\Romania\Counties;
use WHMCS\Module\Addon\Efactura\Romania\Cui;

/**
 * Reads one company of the ANAF PlatitorTvaRest v9 answer (research report
 * 02, 7.1). Observed live on 2026-10-09: no "cod/message" envelope,
 * perioade_TVA is a list, the cedilla forms of s and t (Ş, ţ), postal codes
 * without their leading zero, and a deregistered company only marked in
 * stare_inregistrare ("RADIERE din data ...").
 */
final class CompanyParser
{
    /** Romanian letters with a cedilla, as ANAF still writes them, and their correct form. */
    private const LETTERS = ['Ş' => 'Ș', 'ş' => 'ș', 'Ţ' => 'Ț', 'ţ' => 'ț'];

    /** Words in front of a locality name: "Loc. Balasan Mun. Băilești". */
    private const LOCALITY_WORDS = 'Loc\.|Localitatea|Sat|Satul|Mun\.|Municipiul|Oraş|Oraș|Ors\.|Com\.|Comuna';

    /**
     * The company for a CUI in the decoded answer, or null when it is not there.
     *
     * @param array<string, mixed> $answer
     */
    public static function company(array $answer, string $cui): ?CompanyRecord
    {
        $found = $answer['found'] ?? null;
        if (!is_array($found)) {
            return null;
        }
        foreach ($found as $item) {
            if (is_array($item) && Cui::normalize((string) ($item['date_generale']['cui'] ?? '')) === Cui::normalize($cui)) {
                return self::record($item);
            }
        }

        return null;
    }

    /**
     * Whether the answer lists the CUI as not found.
     *
     * @param array<string, mixed> $answer
     */
    public static function notFound(array $answer, string $cui): bool
    {
        $missing = $answer['notFound'] ?? null;
        if (!is_array($missing)) {
            return false;
        }
        foreach ($missing as $value) {
            if (is_scalar($value) && Cui::normalize((string) $value) === Cui::normalize($cui)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function record(array $item): CompanyRecord
    {
        $general = self::section($item, 'date_generale');
        $vat = self::section($item, 'inregistrare_scop_Tva');
        $onCollection = self::section($item, 'inregistrare_RTVAI');
        $inactive = self::section($item, 'stare_inactiv');

        // The registered office; the fiscal domicile when ANAF has no office address.
        $address = self::address(self::section($item, 'adresa_sediu_social'), 's')
            ?? self::address(self::section($item, 'adresa_domiciliu_fiscal'), 'd')
            ?? ['address1' => '', 'address2' => '', 'city' => '', 'county' => '', 'postcode' => ''];
        if ($address['postcode'] === '') {
            $address['postcode'] = self::postcode(self::text($general['codPostal'] ?? ''));
        }

        $registrationState = self::text($general['stare_inregistrare'] ?? '');
        $regCom = self::text($general['nrRegCom'] ?? '');

        return new CompanyRecord(
            cui: Cui::normalize((string) ($general['cui'] ?? '')),
            name: self::text($general['denumire'] ?? ''),
            regCom: preg_match('/^[\-\s]*$/', $regCom) === 1 ? '' : strtoupper(str_replace(' ', '', $regCom)),
            address1: $address['address1'],
            address2: $address['address2'],
            city: $address['city'],
            county: $address['county'],
            postcode: $address['postcode'],
            vatPayer: ($vat['scpTVA'] ?? false) === true,
            vatOnCollection: ($onCollection['statusTvaIncasare'] ?? false) === true,
            inactive: ($inactive['statusInactivi'] ?? false) === true,
            deregistered: self::text($inactive['dataRadiere'] ?? '') !== '' || preg_match('/^\s*RADIER/iu', $registrationState) === 1,
            eInvoiceRegistry: ($general['statusRO_e_Factura'] ?? false) === true,
            registrationState: $registrationState,
        );
    }

    /**
     * Text as it should appear in the client fields: comma-below s and t, single spaces.
     */
    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', strtr((string) $value, self::LETTERS)) ?? '');
    }

    /**
     * "Loc. Balasan Mun. Băileşti" -> "Balasan, Băilești"; "Mun. Craiova" -> "Craiova".
     */
    public static function locality(string $value): string
    {
        $value = self::text($value);
        $parts = preg_split('/\s+(?=(?:' . self::LOCALITY_WORDS . ')\s)/u', $value) ?: [$value];
        $names = [];
        foreach ($parts as $part) {
            $name = trim(preg_replace('/^(?:' . self::LOCALITY_WORDS . ')\s+/u', '', trim($part)) ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Romanian postal codes have 6 digits; ANAF drops the leading zero of Bucharest codes.
     */
    public static function postcode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return match (strlen($digits)) {
            6 => $digits,
            5 => '0' . $digits,
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $section
     * @return array{address1: string, address2: string, city: string, county: string, postcode: string}|null
     */
    private static function address(array $section, string $prefix): ?array
    {
        $street = self::text($section[$prefix . 'denumire_Strada'] ?? '');
        $number = self::text($section[$prefix . 'numar_Strada'] ?? '');
        $locality = self::text($section[$prefix . 'denumire_Localitate'] ?? '');
        if ($street === '' && $locality === '') {
            return null;
        }

        $code = 'RO-' . strtoupper(self::text($section[$prefix . 'cod_JudetAuto'] ?? ''));
        $countyCode = Counties::isValid($code) ? $code : Counties::fromText(self::text($section[$prefix . 'denumire_Judet'] ?? ''));
        $county = $countyCode !== null ? (string) Counties::name($countyCode) : '';
        $city = $countyCode === Counties::BUCHAREST
            ? (string) CountyField::sector($locality)
            : self::locality($locality);

        return [
            'address1' => trim($street . ($number !== '' ? ' nr. ' . $number : '')),
            'address2' => self::text($section[$prefix . 'detalii_Adresa'] ?? ''),
            'city' => $city,
            'county' => $county,
            'postcode' => self::postcode(self::text($section[$prefix . 'cod_Postal'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function section(array $item, string $name): array
    {
        return is_array($item[$name] ?? null) ? $item[$name] : [];
    }
}
