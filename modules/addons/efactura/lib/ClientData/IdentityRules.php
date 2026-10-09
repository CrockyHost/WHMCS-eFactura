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

use WHMCS\Module\Addon\Efactura\Romania\Cnp;
use WHMCS\Module\Addon\Efactura\Romania\Cui;

/**
 * Who a Romanian client is: an individual (CNP optional) or a legal person
 * (company, PFA, II, institution: CUI required). The type is not stored on
 * its own; like the UBL buyer mapping, a client with a company name or a CUI
 * is a legal person, so the rules keep those fields consistent with the
 * chosen type.
 */
final class IdentityRules
{
    public const PERSON = 'person';
    public const COMPANY = 'company';

    /**
     * The type the stored or submitted data describes. A 13-digit CNP typed
     * into the CUI field without a company name (older data) is a person.
     */
    public static function infer(string $companyName, string $cui, string $taxId = ''): string
    {
        if (trim($companyName) !== '' || trim($taxId) !== '') {
            return self::COMPANY;
        }
        $cui = trim($cui);
        if ($cui === '' || Cnp::isValid($cui)) {
            return self::PERSON;
        }

        return self::COMPANY;
    }

    /**
     * @param array<string, string> $data type (chosen, may be empty), country,
     *                                    companyname, cui, regcom, cnp, tax_id
     * @param list<string> $locked fields the client cannot change
     * @return list<Issue>
     */
    public static function check(array $data, array $locked = []): array
    {
        if (strtoupper(trim($data['country'] ?? '')) !== 'RO') {
            return [];
        }

        $companyName = trim($data['companyname'] ?? '');
        $cui = trim($data['cui'] ?? '');
        $regCom = trim($data['regcom'] ?? '');
        $cnp = trim($data['cnp'] ?? '');
        $taxId = trim($data['tax_id'] ?? '');
        $type = in_array($data['type'] ?? '', [self::PERSON, self::COMPANY], true)
            ? (string) $data['type']
            : self::infer($companyName, $cui, $taxId);
        $editable = static fn (string $field): bool => !in_array($field, $locked, true);

        $issues = [];
        if ($type === self::COMPANY) {
            if ($cui === '') {
                // A Romanian company is never accepted without its CUI.
                if ($editable('cui')) {
                    $issues[] = new Issue('cui', 'cd_error_cui_required', Issue::REQUIRED);
                }
            } elseif (!Cui::isValid($cui) && $editable('cui')) {
                $issues[] = new Issue('cui', 'cd_error_cui_invalid');
            }
            if ($companyName === '' && $editable('companyname')) {
                $issues[] = new Issue('companyname', 'cd_error_company_name');
            }
            if ($regCom !== '' && !RegCom::isValid($regCom) && $editable('regcom')) {
                $issues[] = new Issue('regcom', 'cd_error_regcom');
            }
            if ($taxId !== '' && $editable('tax_id') && !self::isVatNumberOf($taxId, $cui)) {
                $issues[] = new Issue('tax_id', 'cd_error_vat_mismatch');
            }

            return $issues;
        }

        if (($companyName !== '' && $editable('companyname')) || ($cui !== '' && !Cnp::isValid($cui) && $editable('cui'))) {
            $issues[] = new Issue('companyname', 'cd_error_person_company');
        }
        if ($taxId !== '' && $editable('tax_id')) {
            $issues[] = new Issue('tax_id', 'cd_error_person_vat');
        }
        if ($cnp !== '' && $cnp !== Cnp::UNKNOWN && !Cnp::isValid($cnp) && $editable('cnp')) {
            $issues[] = new Issue('cnp', 'cd_error_cnp');
        }

        return $issues;
    }

    /**
     * Whether a VAT number is RO followed by the company CUI.
     */
    public static function isVatNumberOf(string $taxId, string $cui): bool
    {
        $taxId = strtoupper(preg_replace('/[\s.\-]+/', '', $taxId) ?? '');

        return str_starts_with($taxId, 'RO') && $cui !== '' && Cui::normalize($taxId) === Cui::normalize($cui);
    }
}
