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

namespace WHMCS\Module\Addon\Efactura\Support;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;

/**
 * Interface strings from lang/<language>.php ($_ADDONLANG), with English as
 * the fallback for missing keys.
 */
final class Lang
{
    public const LANGUAGES = ['romanian', 'english'];

    private static string $language = 'english';

    /** @var array<string, string> */
    private static array $strings = [];

    /** @var array<string, string> */
    private static array $fallback = [];

    /**
     * @param string $setting "auto", "romanian" or "english"
     */
    public static function boot(string $setting, ?int $adminId = null): void
    {
        $language = $setting === 'auto' ? self::adminLanguage($adminId) : $setting;
        if (!in_array($language, self::LANGUAGES, true)) {
            $language = 'english';
        }

        self::$language = $language;
        self::$fallback = self::read('english');
        self::$strings = $language === 'english' ? self::$fallback : self::read($language);
    }

    public static function language(): string
    {
        return self::$language;
    }

    public static function get(string $key, string|int|float ...$args): string
    {
        if (self::$fallback === []) {
            self::boot('english');
        }
        $text = self::$strings[$key] ?? self::$fallback[$key] ?? $key;

        return $args === [] ? $text : vsprintf($text, $args);
    }

    public static function has(string $key): bool
    {
        if (self::$fallback === []) {
            self::boot('english');
        }

        return isset(self::$strings[$key]) || isset(self::$fallback[$key]);
    }

    /**
     * @return array<string, string> every string of the active language
     */
    public static function all(): array
    {
        if (self::$fallback === []) {
            self::boot('english');
        }

        return self::$strings + self::$fallback;
    }

    private static function adminLanguage(?int $adminId): string
    {
        if ($adminId === null || $adminId <= 0) {
            return 'english';
        }
        $language = Capsule::table('tbladmins')->where('id', $adminId)->value('language');

        return strtolower((string) $language);
    }

    /**
     * @return array<string, string>
     */
    private static function read(string $language): array
    {
        $file = Addon::path('lang/' . $language . '.php');
        // The language files refuse to run outside WHMCS; unit tests then get the keys.
        if (!defined('WHMCS') || !is_file($file)) {
            return [];
        }
        $_ADDONLANG = [];
        include $file;

        return array_map('strval', $_ADDONLANG);
    }
}
