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

use Throwable;
use WHMCS\Module\Addon\Efactura\Addon;

/**
 * Strings of the client forms from lang/clientdata/<language>.php, in the
 * language of the visitor (client area) or of the administrator. Languages
 * without a file fall back to English.
 */
final class Texts
{
    public const LANGUAGES = ['romanian', 'english'];

    /** @var array<string, array<string, string>> */
    private static array $files = [];

    /**
     * @param array<string, string> $strings
     */
    private function __construct(private readonly string $language, private readonly array $strings)
    {
    }

    public static function for(string $language): self
    {
        $language = strtolower(trim($language));
        if (!in_array($language, self::LANGUAGES, true)) {
            $language = 'english';
        }
        $strings = self::file($language) + self::file('english');

        return new self($language, $strings);
    }

    /**
     * The language of the current client area visitor.
     */
    public static function client(): self
    {
        $language = 'english';
        try {
            if (class_exists('Lang', false)) {
                $language = (string) \Lang::self()->getName();
            }
        } catch (Throwable) {
            // Falls back to English.
        }

        return self::for($language);
    }

    public function language(): string
    {
        return $this->language;
    }

    public function get(string $key, string|int ...$args): string
    {
        $text = $this->strings[$key] ?? $key;

        return $args === [] ? $text : vsprintf($text, $args);
    }

    /**
     * @param list<string> $keys
     * @return array<string, string> key => text, for the browser
     */
    public function pick(array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $this->get($key);
        }

        return $picked;
    }

    /**
     * @return array<string, string>
     */
    private static function file(string $language): array
    {
        if (!isset(self::$files[$language])) {
            $path = Addon::path('lang/clientdata/' . $language . '.php');
            $_ADDONLANG = [];
            // The language files refuse to run outside WHMCS; unit tests then get the keys.
            if (defined('WHMCS') && is_file($path)) {
                include $path;
            }
            self::$files[$language] = array_map('strval', $_ADDONLANG);
        }

        return self::$files[$language];
    }
}
