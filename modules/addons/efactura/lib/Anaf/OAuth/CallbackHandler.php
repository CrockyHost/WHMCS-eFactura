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

namespace WHMCS\Module\Addon\Efactura\Anaf\OAuth;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Admin\View;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * Handles the browser coming back from ANAF (oauth_callback.php) with either
 * an authorization code or an error.
 */
final class CallbackHandler
{
    /**
     * @param array<string, mixed> $query
     */
    public static function handle(array $query): void
    {
        // The URL carries the authorization code: keep it out of caches and referrers.
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');

        if (!Capsule::schema()->hasTable(Connection::TABLE)) {
            self::page(false, 'WHMCS-eFactura is not activated.');

            return;
        }
        Lang::boot(Settings::string('ui_language'), AdminContext::id());

        $connection = Addon::connection();
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $error = is_string($query['error'] ?? null) ? trim($query['error'] . ' ' . (string) ($query['error_description'] ?? '')) : '';

        try {
            if ($error !== '') {
                try {
                    $connection->failAuthorization($state, $error);
                } catch (OAuthException) {
                    // Invalid state: the error is shown but not recorded.
                }
                self::page(false, Lang::get('callback_denied', $error));

                return;
            }
            $connection->completeAuthorization($state, $code);
        } catch (OAuthException $e) {
            self::page(false, $e->reason === OAuthException::INVALID_STATE
                ? Lang::get('callback_invalid_state')
                : Lang::get('callback_failed', $e->getMessage()));

            return;
        } catch (Throwable $e) {
            logActivity(Addon::NAME . ': ANAF callback failed: ' . $e->getMessage());
            self::page(false, Lang::get('callback_failed', get_class($e)));

            return;
        }

        if (AdminContext::id() !== null) {
            header('Location: ' . AdminContext::adminUrl('addonmodules.php?module=' . Addon::MODULE . '&view=anaf&connected=1'));

            return;
        }
        self::page(true, Lang::get('callback_success'));
    }

    private static function page(bool $success, string $message): void
    {
        if (!$success) {
            http_response_code(400);
        }
        echo View::render('oauth_callback', [
            'success' => $success,
            'message' => $message,
            'title' => Lang::get('callback_title'),
            'language' => Lang::language() === 'romanian' ? 'ro' : 'en',
            'attributionHtml' => Addon::attributionHtml(),
        ], 'public');
    }
}
