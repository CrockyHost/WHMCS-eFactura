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

use WHMCS\Module\Addon\Efactura\Addon;

/**
 * Alerts for the WHMCS administrators: an e-mail to the admins who receive
 * system notifications, plus an Activity Log entry.
 */
final class AdminNotifier
{
    public static function send(string $subject, string $message): void
    {
        $subject = Addon::NAME . ': ' . $subject;
        if (function_exists('logActivity')) {
            logActivity($subject . ' - ' . $message);
        }
        if (function_exists('localAPI')) {
            localAPI('SendAdminEmail', [
                'customsubject' => $subject,
                'custommessage' => nl2br(htmlspecialchars($message, ENT_QUOTES)),
                'type' => 'system',
            ]);
        }
    }

    /**
     * Alert for the yearly re-authorization with the qualified certificate.
     *
     * @param array<string, mixed> $status Connection::status()
     */
    public static function reauthorization(int $threshold, int $daysLeft, array $status): void
    {
        $link = AdminContext::adminUrl('addonmodules.php?module=' . Addon::MODULE . '&view=anaf');
        if ($threshold === 0) {
            $subject = Lang::get('alert_reauth_now_subject');
            $message = Lang::get('alert_reauth_now_body', (string) $status['last_error'], $link);
        } else {
            $date = $status['refresh_expires_at'] instanceof \DateTimeInterface ? $status['refresh_expires_at']->format('Y-m-d') : '';
            $subject = Lang::get('alert_reauth_soon_subject', $daysLeft);
            $message = Lang::get('alert_reauth_soon_body', $date, $daysLeft, $link);
        }
        self::send($subject, $message);
        Audit::log('oauth_alert_sent', $subject, ['threshold' => $threshold, 'days_left' => $daysLeft]);
    }
}
