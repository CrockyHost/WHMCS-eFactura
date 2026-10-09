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

namespace WHMCS\Module\Addon\Efactura\Settings;

use InvalidArgumentException;
use WHMCS\Database\Capsule;

/**
 * Typed access to the addon settings stored in mod_efactura_settings.
 * Missing rows fall back to the defaults below.
 */
final class Settings
{
    public const TABLE = 'mod_efactura_settings';

    public const ENV_TEST = 'test';
    public const ENV_PROD = 'prod';

    /** Maximum delay before sending, in working days (legal deadline is 5). */
    public const MAX_SEND_DELAY_DAYS = 3;

    /**
     * name => [type, default]. Types: bool, int, string, list (of ints),
     * map (string => string).
     */
    private const DEFINITIONS = [
        // General
        'enabled' => ['bool', false],
        'environment' => ['string', self::ENV_TEST],
        'ui_language' => ['string', 'romanian'],

        // Seller (BG-4)
        'company_legal_name' => ['string', ''],
        'company_trade_name' => ['string', ''],
        'company_cui' => ['string', ''],
        'company_vat_payer' => ['bool', true],
        'company_vat_on_collection' => ['bool', false],
        'company_reg_com' => ['string', ''],
        'company_share_capital' => ['string', ''],
        'company_street' => ['string', ''],
        'company_city' => ['string', ''],
        'company_county' => ['string', ''],
        'company_postcode' => ['string', ''],
        'company_contact_name' => ['string', ''],
        'company_phone' => ['string', ''],
        'company_email' => ['string', ''],
        'bank_name' => ['string', ''],
        'bank_bic' => ['string', ''],
        'iban_ron' => ['string', ''],
        'iban_eur' => ['string', ''],

        // Sending
        'send_delay_days' => ['int', 1],

        // Early fiscal issue (number before payment)
        'early_issue_groups' => ['list', []],
        'early_issue_clients' => ['list', []],

        // Where the buyer data comes from: "tax_id", "state" or "cf:<custom field id>"
        'client_field_cui' => ['string', 'tax_id'],
        'client_field_regcom' => ['string', ''],
        'client_field_cnp' => ['string', ''],
        'client_field_county' => ['string', 'state'],

        // Invoices that are not reported to SPV
        'exclude_eu_reverse_charge' => ['bool', true],
        'exclude_non_eu' => ['bool', true],
        'exclude_zero_total' => ['bool', true],
        'exclude_add_funds' => ['bool', true],

        // Payment means code (UNCL 4461) by WHMCS gateway; unmapped gateways use a default
        'payment_means' => ['map', []],
    ];

    /** @var array<string, string>|null raw values loaded from the database */
    private static ?array $raw = null;

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function get(string $name): mixed
    {
        [$type, $default] = self::definition($name);
        $raw = self::load();

        return array_key_exists($name, $raw) ? self::decode($type, $raw[$name]) : $default;
    }

    public static function bool(string $name): bool
    {
        return (bool) self::get($name);
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    public static function string(string $name): string
    {
        return (string) self::get($name);
    }

    /**
     * @return list<int>
     */
    public static function intList(string $name): array
    {
        $value = self::get($name);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, string>
     */
    public static function map(string $name): array
    {
        $value = self::get($name);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed> every setting with its effective value
     */
    public static function all(): array
    {
        $values = [];
        foreach (self::names() as $name) {
            $values[$name] = self::get($name);
        }

        return $values;
    }

    /**
     * Saves several settings in one transaction. Unknown names are rejected.
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($values as $name => $value) {
            [$type] = self::definition((string) $name);
            $rows[] = ['name' => (string) $name, 'value' => self::encode($type, $value), 'updated_at' => $now];
        }

        Capsule::connection()->transaction(static function () use ($rows): void {
            foreach ($rows as $row) {
                Capsule::table(self::TABLE)->updateOrInsert(['name' => $row['name']], $row);
            }
        });

        self::$raw = null;
    }

    public static function environment(): string
    {
        return self::string('environment') === self::ENV_PROD ? self::ENV_PROD : self::ENV_TEST;
    }

    /**
     * Forgets the cached values (after a save from another code path).
     */
    public static function reset(): void
    {
        self::$raw = null;
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function definition(string $name): array
    {
        if (!isset(self::DEFINITIONS[$name])) {
            throw new InvalidArgumentException("Unknown WHMCS-eFactura setting: {$name}");
        }

        return self::DEFINITIONS[$name];
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        if (self::$raw === null) {
            self::$raw = Capsule::table(self::TABLE)->pluck('value', 'name')
                ->map(static fn ($value): string => (string) $value)
                ->all();
        }

        return self::$raw;
    }

    private static function decode(string $type, string $value): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'list' => array_values(array_map('intval', (array) (json_decode($value, true) ?: []))),
            'map' => array_map('strval', (array) (json_decode($value, true) ?: [])),
            default => $value,
        };
    }

    private static function encode(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            'list' => (string) json_encode(array_values(array_map('intval', (array) $value))),
            'map' => (string) json_encode((object) array_map('strval', (array) $value)),
            default => trim((string) $value),
        };
    }
}
