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
 * Text from WHMCS made fit for the XML: no HTML, no control characters,
 * normal spaces, and cut to the CIUS-RO lengths at a word boundary.
 */
final class Text
{
    /**
     * One line of plain text.
     */
    public static function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::plain((string) $value)) ?? '');
    }

    /**
     * Plain text lines, without empty ones.
     *
     * @return list<string>
     */
    public static function lines(?string $value): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::plain((string) $value));
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Cuts $text to at most $max characters, at a word boundary when
     * possible, ending with "...".
     *
     * @return array{0: string, 1: string} the cut text and the rest
     */
    public static function cut(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text, ''];
        }
        $head = mb_substr($text, 0, $max - 3);
        $space = mb_strrpos($head, ' ');
        if ($space !== false && $space > ($max - 3) / 2) {
            $head = mb_substr($head, 0, $space);
        }
        $rest = ltrim(mb_substr($text, mb_strlen($head)));

        return [rtrim($head, " ,;:.-") . '...', $rest];
    }

    /**
     * Item name (BT-153, 100), description (BT-154, 200) and line note
     * (BT-127, 300) from a WHMCS line description: the first line is the
     * name, the whole text the description; what does not fit continues in
     * the note.
     *
     * @return array{name: string, description: ?string, note: ?string}
     */
    public static function item(string $description): array
    {
        $lines = self::lines($description);
        if ($lines === []) {
            return ['name' => '', 'description' => null, 'note' => null];
        }
        [$name] = self::cut($lines[0], 100);
        $full = implode('; ', $lines);
        if ($full === $name) {
            return ['name' => $name, 'description' => null, 'note' => null];
        }
        [$text, $rest] = self::cut($full, 200);
        $note = null;
        if ($rest !== '') {
            [$note] = self::cut($rest, 300);
        }

        return ['name' => $name, 'description' => $text, 'note' => $note];
    }

    /** HTML elements removed from descriptions (other text in angle brackets stays). */
    private const HTML_TAGS = 'a|abbr|b|big|blockquote|br|center|code|del|div|em|font|h[1-6]|hr|i|img|ins|li|ol|p|pre|s|small|span|strike|strong|sub|sup|table|tbody|td|th|thead|tr|tt|u|ul';

    private static function plain(string $value): string
    {
        // WHMCS stores text HTML-escaped ("&amp;", "&lt;b&gt;"): decode first, then drop HTML elements.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $value = preg_replace('/<\/?(?:' . self::HTML_TAGS . ')(?:\s[^<>]*)?\/?>/i', '', $value) ?? $value;
        // Control characters are not allowed in XML 1.0 (tab and newline are kept).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
