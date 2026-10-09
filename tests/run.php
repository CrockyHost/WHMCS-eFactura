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

/*
 * Minimal test runner, without dependencies (WHMCS ships no PHPUnit).
 *
 *   php tests/run.php            unit tests only (no WHMCS needed)
 *   php tests/run.php all        unit + integration tests
 *   php tests/run.php integration
 *
 * Integration tests boot the WHMCS installation this repository lives in and
 * run every test inside a database transaction that is rolled back.
 * Each test file returns an array of "description" => closure.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/modules/addons/efactura/bootstrap.php';
require __DIR__ . '/Assert.php';
require __DIR__ . '/FakeTransport.php';
require __DIR__ . '/AnafSimulator.php';
require __DIR__ . '/DevValidators.php';

$suite = $argv[1] ?? 'unit';
$suites = $suite === 'all' ? ['Unit', 'Integration'] : [ucfirst($suite)];

$passed = 0;
$failed = [];
$skipped = [];

foreach ($suites as $name) {
    $files = glob(__DIR__ . '/' . $name . '/*Test.php') ?: [];
    sort($files);
    if ($name === 'Integration' && $files !== []) {
        // WHMCS expects to be booted from the global scope.
        require_once dirname(__DIR__) . '/init.php';
    }

    foreach ($files as $file) {
        // Each test file gets its own scope, so its variables cannot clash with the runner's.
        $tests = (static fn (string $path): array => require $path)($file);
        foreach ($tests as $description => $test) {
            $label = $name . '/' . basename($file, '.php') . ': ' . $description;
            $connection = $name === 'Integration' ? \WHMCS\Database\Capsule::connection() : null;
            $connection?->beginTransaction();
            try {
                $test();
                $passed++;
                echo '.';
            } catch (SkipTest $e) {
                $skipped[] = $label . ': ' . $e->getMessage();
                echo 'S';
            } catch (Throwable $e) {
                $failed[] = $label . "\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine();
                echo 'F';
            } finally {
                $connection?->rollBack();
                if ($name === 'Integration') {
                    \WHMCS\Module\Addon\Efactura\Settings\Settings::reset();
                }
            }
        }
    }
}

echo "\n\n";
foreach ($failed as $failure) {
    echo 'FAIL ' . $failure . "\n\n";
}
foreach ($skipped as $skip) {
    echo 'SKIP ' . $skip . "\n";
}
printf("%d passed, %d failed, %d skipped\n", $passed, count($failed), count($skipped));
exit($failed === [] ? 0 : 1);
