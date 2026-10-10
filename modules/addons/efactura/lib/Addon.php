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

namespace WHMCS\Module\Addon\Efactura;

use Throwable;
use WHMCS\Module\Addon\Efactura\Admin\AdminController;
use WHMCS\Module\Addon\Efactura\Anaf\ApiClient;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\Connection;
use WHMCS\Module\Addon\Efactura\Anaf\OAuth\OAuthClient;
use WHMCS\Module\Addon\Efactura\Database\Migrator;
use WHMCS\Module\Addon\Efactura\Exchange\BnrRates;
use WHMCS\Module\Addon\Efactura\Fiscal\DocumentRepository;
use WHMCS\Module\Addon\Efactura\Fiscal\Stornos;
use WHMCS\Module\Addon\Efactura\Http\CurlTransport;
use WHMCS\Module\Addon\Efactura\Inbox\InboxSync;
use WHMCS\Module\Addon\Efactura\Numbering\EarlyIssue;
use WHMCS\Module\Addon\Efactura\Numbering\FiscalNumbering;
use WHMCS\Module\Addon\Efactura\Numbering\PaymentNumbering;
use WHMCS\Module\Addon\Efactura\Numbering\SeriesCounter;
use WHMCS\Module\Addon\Efactura\Queue\DeadlineMonitor;
use WHMCS\Module\Addon\Efactura\Queue\Reconciler;
use WHMCS\Module\Addon\Efactura\Queue\Worker;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\RuntimeState;
use WHMCS\Module\Addon\Efactura\Ubl\DocumentBuilder;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * Module identity and the WHMCS lifecycle (config, activate, deactivate,
 * upgrade, output).
 */
final class Addon
{
    public const MODULE = 'efactura';
    public const NAME = 'WHMCS-eFactura';
    public const VERSION = '0.8.0';
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

    public static function connection(): Connection
    {
        return new Connection(new OAuthClient(new CurlTransport()));
    }

    public static function documents(): DocumentRepository
    {
        return new DocumentRepository();
    }

    public static function fiscalNumbering(): FiscalNumbering
    {
        return new FiscalNumbering(new SeriesCounter(new InvoicingConfig()));
    }

    public static function paymentNumbering(): PaymentNumbering
    {
        return new PaymentNumbering(self::fiscalNumbering(), self::documents());
    }

    public static function earlyIssue(): EarlyIssue
    {
        return new EarlyIssue(self::fiscalNumbering(), self::documents());
    }

    public static function inbox(): InboxSync
    {
        return new InboxSync(self::api(), new RuntimeState());
    }

    public static function stornos(): Stornos
    {
        return new Stornos(self::fiscalNumbering(), self::documents(), new RuntimeState());
    }

    public static function documentBuilder(): DocumentBuilder
    {
        return new DocumentBuilder(new BnrRates(new CurlTransport()));
    }

    /**
     * Client of the e-Factura API in the environment chosen in the settings.
     */
    public static function api(): ApiClient
    {
        return new ApiClient(new CurlTransport(), self::connection(), Settings::environment());
    }

    /**
     * The queue worker, with reconciliation and deadline alerts.
     */
    public static function worker(): Worker
    {
        $connection = self::connection();
        $api = new ApiClient(new CurlTransport(), $connection, Settings::environment());
        $state = new RuntimeState();

        return new Worker($api, $connection, self::documentBuilder(), self::documents(), $state, new Reconciler($api, self::documents(), $state), new DeadlineMonitor());
    }
}
