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

/**
 * Hooks of the client forms (registration, checkout, profile, contacts and
 * the client pages of the admin area). A failure here must never break a
 * WHMCS page, so every hook falls back to doing nothing and logs the error.
 */
final class ClientDataHooks
{
    public static function register(): void
    {
        add_hook('ClientAreaHeadOutput', 10, static fn (array $vars): string => self::safely(
            static fn (): string => PageAssets::clientHead($vars),
            ''
        ));
        add_hook('ClientAreaFooterOutput', 10, static fn (array $vars): string => self::safely(
            static fn (): string => PageAssets::clientFooter($vars),
            ''
        ));
        add_hook('AdminAreaHeadOutput', 10, static fn (array $vars): string => self::safely(
            static fn (): string => PageAssets::adminHead($vars),
            ''
        ));
        add_hook('AdminAreaFooterOutput', 10, static fn (array $vars): string => self::safely(
            static fn (): string => PageAssets::adminFooter($vars),
            ''
        ));
        add_hook('ClientDetailsValidation', 10, static fn (array $vars): array => self::safely(
            static fn (): array => ClientValidation::client($vars),
            []
        ));
        add_hook('ContactDetailsValidation', 10, static fn (array $vars): array => self::safely(
            static fn (): array => ClientValidation::contact($vars),
            []
        ));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    private static function safely(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('WHMCS-eFactura client forms error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            }

            return $fallback;
        }
    }
}
