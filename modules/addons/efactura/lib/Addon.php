<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

namespace WHMCS\Module\Addon\Efactura;

use Throwable;
use WHMCS\Module\Addon\Efactura\Admin\AdminController;
use WHMCS\Module\Addon\Efactura\Database\Migrator;

/**
 * Module identity and the WHMCS lifecycle (config, activate, deactivate,
 * upgrade, output).
 */
final class Addon
{
    public const MODULE = 'efactura';
    public const NAME = 'WHMCS-eFactura';
    public const VERSION = '0.1.0';
    public const SOURCE_URL = 'https://github.com/CrockyHost/WHMCS-eFactura';

    /**
     * Attribution notice required by section B.1 of ADDITIONAL-TERMS.md. It is
     * shown unaltered on every admin page of the addon.
     */
    public const ATTRIBUTION = [
        'WHMCS-eFactura by CrockyHost. Free software under the GNU GPL v3.',
        'Source code: https://github.com/CrockyHost/WHMCS-eFactura',
    ];

    public static function config(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'RO e-Factura (ANAF) for WHMCS: UBL 2.1 / CIUS-RO invoices and stornos sent to the Romanian SPV, with an SPV inbox.',
            'author' => 'CrockyHost',
            'language' => 'english',
            'version' => self::VERSION,
            'fields' => [],
        ];
    }

    public static function activate(): array
    {
        try {
            $applied = self::migrator()->migrate();
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'description' => 'WHMCS-eFactura could not prepare its database tables: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'description' => sprintf(
                'WHMCS-eFactura %s activated (%d database migrations applied). Open Addons > %s to configure it.',
                self::VERSION,
                count($applied),
                self::NAME
            ),
        ];
    }

    /**
     * Deactivation never drops tables: they hold fiscal documents and the
     * archive of signed ANAF responses.
     */
    public static function deactivate(): array
    {
        return [
            'status' => 'success',
            'description' => 'WHMCS-eFactura deactivated. Its documents, archive and settings were kept in the mod_efactura_* tables.',
        ];
    }

    public static function upgrade(array $vars): void
    {
        self::migrator()->migrate();
    }

    public static function output(array $vars): void
    {
        (new AdminController($vars))->handle();
    }

    /**
     * The attribution notice as HTML: the exact text, with the URL as a link.
     */
    public static function attributionHtml(): string
    {
        $url = htmlspecialchars(self::SOURCE_URL, ENT_QUOTES);
        $lines = array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES), self::ATTRIBUTION);
        $lines[1] = str_replace($url, '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>', $lines[1]);

        return implode('<br>', $lines);
    }

    public static function path(string $relative = ''): string
    {
        $base = dirname(__DIR__);

        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    public static function migrator(): Migrator
    {
        return new Migrator(self::path('migrations'));
    }
}
