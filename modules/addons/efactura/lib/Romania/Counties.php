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

namespace WHMCS\Module\Addon\Efactura\Romania;

/**
 * Romanian counties as ISO 3166-2:RO codes, the values CIUS-RO accepts in
 * CountrySubentity (BT-39, BT-54). Bucharest is RO-B and needs a sector
 * (SECTOR1..SECTOR6) as the city name.
 */
final class Counties
{
    public const BUCHAREST = 'RO-B';

    public const SECTORS = ['SECTOR1', 'SECTOR2', 'SECTOR3', 'SECTOR4', 'SECTOR5', 'SECTOR6'];

    private const NAMES = [
        'RO-AB' => 'Alba',
        'RO-AR' => 'Arad',
        'RO-AG' => 'Argeș',
        'RO-BC' => 'Bacău',
        'RO-BH' => 'Bihor',
        'RO-BN' => 'Bistrița-Năsăud',
        'RO-BT' => 'Botoșani',
        'RO-BR' => 'Brăila',
        'RO-BV' => 'Brașov',
        'RO-B' => 'București',
        'RO-BZ' => 'Buzău',
        'RO-CL' => 'Călărași',
        'RO-CS' => 'Caraș-Severin',
        'RO-CJ' => 'Cluj',
        'RO-CT' => 'Constanța',
        'RO-CV' => 'Covasna',
        'RO-DB' => 'Dâmbovița',
        'RO-DJ' => 'Dolj',
        'RO-GL' => 'Galați',
        'RO-GR' => 'Giurgiu',
        'RO-GJ' => 'Gorj',
        'RO-HR' => 'Harghita',
        'RO-HD' => 'Hunedoara',
        'RO-IL' => 'Ialomița',
        'RO-IS' => 'Iași',
        'RO-IF' => 'Ilfov',
        'RO-MM' => 'Maramureș',
        'RO-MH' => 'Mehedinți',
        'RO-MS' => 'Mureș',
        'RO-NT' => 'Neamț',
        'RO-OT' => 'Olt',
        'RO-PH' => 'Prahova',
        'RO-SJ' => 'Sălaj',
        'RO-SM' => 'Satu Mare',
        'RO-SB' => 'Sibiu',
        'RO-SV' => 'Suceava',
        'RO-TR' => 'Teleorman',
        'RO-TM' => 'Timiș',
        'RO-TL' => 'Tulcea',
        'RO-VL' => 'Vâlcea',
        'RO-VS' => 'Vaslui',
        'RO-VN' => 'Vrancea',
    ];

    /**
     * @return array<string, string> code => name
     */
    public static function all(): array
    {
        return self::NAMES;
    }

    /**
     * @return array<string, string> code => name, in alphabetical order of the names
     */
    public static function sortedByName(): array
    {
        $names = self::NAMES;
        uasort($names, static fn (string $a, string $b): int => strcmp(Text::fold($a), Text::fold($b)));

        return $names;
    }

    public static function isValid(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }

    public static function name(string $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }

    public static function isSector(string $city): bool
    {
        return in_array($city, self::SECTORS, true);
    }
}
