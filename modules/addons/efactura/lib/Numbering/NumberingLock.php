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

namespace WHMCS\Module\Addon\Efactura\Numbering;

use WHMCS\Module\Addon\Efactura\Database\Lock;

/**
 * The lock that serializes every allocation of fiscal numbers, by WHMCS at
 * payment and by the addon. It is held by at most one flow per request: the
 * owner is the invoice whose payment took it (0 for addon operations), and
 * only the owner releases it. MySQL releases it anyway when the request
 * ends.
 */
final class NumberingLock
{
    private const NAME = 'numbering';

    /** Seconds a payment waits for the lock before continuing without it. */
    public const PAYMENT_TIMEOUT = 5;
    /** Seconds an admin action waits for the lock. */
    public const ADMIN_TIMEOUT = 15;

    private static bool $held = false;
    private static ?int $owner = null;
    private static bool $shutdownRegistered = false;
    private static ?int $paymentTimeoutOverride = null;

    /**
     * Seconds a payment waits for the lock; tests can shorten it.
     */
    public static function paymentTimeout(): int
    {
        return self::$paymentTimeoutOverride ?? self::PAYMENT_TIMEOUT;
    }

    public static function overridePaymentTimeout(?int $seconds): void
    {
        self::$paymentTimeoutOverride = $seconds;
    }

    public static function held(): bool
    {
        return self::$held;
    }

    public static function owner(): ?int
    {
        return self::$owner;
    }

    /**
     * Takes the lock for $owner, waiting at most $timeoutSeconds. Returns
     * true at once when this request already holds it (the owner stays).
     */
    public static function acquire(int $owner, int $timeoutSeconds): bool
    {
        if (self::$held) {
            return true;
        }
        if (!Lock::acquire(self::NAME, $timeoutSeconds)) {
            return false;
        }
        self::$held = true;
        self::$owner = $owner;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                self::release();
            });
        }

        return true;
    }

    public static function release(): void
    {
        if (!self::$held) {
            return;
        }
        self::$held = false;
        self::$owner = null;
        Lock::release(self::NAME);
    }

    public static function releaseIfOwner(int $owner): void
    {
        if (self::$held && self::$owner === $owner) {
            self::release();
        }
    }
}
