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

use WHMCS\Module\Addon\Efactura\Romania\Counties;

/**
 * The values the client forms save in the WHMCS State/Region and City
 * fields for Romania: the official county name ("Cluj", "București") and,
 * for Bucharest, "Sector 1" to "Sector 6" as the city. Both are read back by
 * Romania\Counties when the UBL buyer is built.
 */
final class CountyField
{
    public const SECTOR_COUNT = 6;

    /**
     * @return list<string> county names in alphabetical order, the dropdown values
     */
    public static function names(): array
    {
        return array_values(Counties::sortedByName());
    }

    /**
     * @return array<string, string> two-letter code ("CJ", "B") => county name
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (Counties::all() as $code => $name) {
            $codes[substr($code, 3)] = $name;
        }

        return $codes;
    }

    public static function bucharest(): string
    {
        return (string) Counties::name(Counties::BUCHAREST);
    }

    /**
     * The dropdown value for a stored text ("DOLJ", "Bucuresti", "Jud. Iasi"),
     * or null when the text is not a Romanian county.
     */
    public static function canonical(string $text): ?string
    {
        $code = Counties::fromText($text);

        return $code === null ? null : Counties::name($code);
    }

    public static function isBucharest(string $text): bool
    {
        return Counties::fromText($text) === Counties::BUCHAREST;
    }

    /**
     * @return list<string> "Sector 1" to "Sector 6"
     */
    public static function sectors(): array
    {
        return array_map(static fn (int $n): string => 'Sector ' . $n, range(1, self::SECTOR_COUNT));
    }

    /**
     * The sector named in any of the texts, as the city value ("Sector 3").
     */
    public static function sector(string ...$texts): ?string
    {
        $sector = Counties::sectorFromText(...$texts);

        return $sector === null ? null : 'Sector ' . substr($sector, strlen('SECTOR'));
    }
}
