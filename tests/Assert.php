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

/**
 * Thrown to skip a test whose requirements are missing.
 */
final class SkipTest extends RuntimeException
{
}

/**
 * Assertions for the test runner.
 */
final class Assert
{
    public static function skip(string $reason): never
    {
        throw new SkipTest($reason);
    }

    public static function true(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function false(bool $condition, string $message = 'Expected false'): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(trim($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
        }
    }

    /**
     * @param class-string<Throwable> $class
     */
    public static function throws(string $class, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new RuntimeException('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        throw new RuntimeException('Expected ' . $class . ' to be thrown');
    }
}
