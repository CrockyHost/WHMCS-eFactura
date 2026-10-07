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

namespace WHMCS\Module\Addon\Efactura\Database;

use RuntimeException;
use WHMCS\Database\Capsule;

/**
 * Applies the files in migrations/ in name order and records each one in
 * mod_efactura_migrations. Runs on activate, on upgrade and when an admin
 * opens the addon, so a code update never waits for a version bump.
 */
final class Migrator
{
    public const TABLE = 'mod_efactura_migrations';
    private const LOCK = 'migrate';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<string> names of the migrations that are not applied yet
     */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * @return list<string> names of the migrations applied by this call
     */
    public function migrate(): array
    {
        if (!Lock::acquire(self::LOCK, 30)) {
            throw new RuntimeException('Another request is running the WHMCS-eFactura migrations.');
        }

        try {
            $db = Capsule::connection();
            $db->statement(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `migration` VARCHAR(191) NOT NULL,
                    `applied_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `migration` (`migration`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $applied = [];
            foreach ($this->pending() as $name) {
                $migration = require $this->directory . '/' . $name . '.php';
                if (!$migration instanceof Migration) {
                    throw new RuntimeException("Migration {$name} does not return a Migration instance.");
                }
                $migration->up($db);
                $db->table(self::TABLE)->insert([
                    'migration' => $name,
                    'applied_at' => date('Y-m-d H:i:s'),
                ]);
                $applied[] = $name;
            }

            return $applied;
        } finally {
            Lock::release(self::LOCK);
        }
    }

    /**
     * @return list<string>
     */
    private function available(): array
    {
        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob($this->directory . '/[0-9][0-9][0-9][0-9]_*.php') ?: []
        );
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function applied(): array
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            return [];
        }

        return Capsule::table(self::TABLE)->pluck('migration')->map(static fn ($name): string => (string) $name)->all();
    }
}
