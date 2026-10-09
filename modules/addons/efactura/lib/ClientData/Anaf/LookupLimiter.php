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

namespace WHMCS\Module\Addon\Efactura\ClientData\Anaf;

use WHMCS\Database\Capsule;

/**
 * Rate limits of the company lookup, which visitors of the public
 * registration form can use: per IP address, per session and per admin.
 * Only hashes of the keys are stored, for a day at most.
 */
final class LookupLimiter
{
    /** kind => [maximum lookups, window in seconds] */
    public const LIMITS = [
        'ip' => [20, 600],
        'session' => [10, 600],
        'admin' => [120, 600],
    ];

    private const RETENTION = 86400;

    /**
     * Records one lookup for the keys, unless one of them is over its limit.
     *
     * @param array<string, string> $keys kind => key (an IP, a session id, an admin id)
     */
    public static function allow(array $keys): bool
    {
        $now = time();
        $buckets = [];
        foreach ($keys as $kind => $key) {
            if ($key === '' || !isset(self::LIMITS[$kind])) {
                continue;
            }
            [$max, $window] = self::LIMITS[$kind];
            $bucket = self::bucket($kind, $key);
            $count = Capsule::table(CompanyLookup::LOG_TABLE)
                ->where('bucket', $bucket)
                ->where('created_at', '>', date('Y-m-d H:i:s', $now - $window))
                ->count();
            if ($count >= $max) {
                return false;
            }
            $buckets[] = $bucket;
        }

        $at = CompanyLookup::microDate();
        foreach ($buckets as $bucket) {
            Capsule::table(CompanyLookup::LOG_TABLE)->insert(['bucket' => $bucket, 'created_at' => $at]);
        }
        Capsule::table(CompanyLookup::LOG_TABLE)
            ->where('created_at', '<', date('Y-m-d H:i:s', $now - self::RETENTION))
            ->limit(500)
            ->delete();

        return true;
    }

    public static function bucket(string $kind, string $key): string
    {
        return hash('sha256', 'efactura-company-lookup|' . $kind . '|' . $key);
    }
}
