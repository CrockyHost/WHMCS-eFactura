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

namespace WHMCS\Module\Addon\Efactura\Admin;

use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * Configuration checks shown on the dashboard. "danger" checks block enabling
 * the processing.
 */
final class HealthChecks
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const DANGER = 'danger';

    private const REQUIRED_EXTENSIONS = ['curl', 'dom', 'libxml', 'openssl', 'zip', 'mbstring'];

    public function __construct(private readonly InvoicingConfig $invoicing)
    {
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function all(): array
    {
        $settings = Settings::all();

        return [
            $this->processing($settings),
            $this->environment(),
            $this->company($settings),
            ...$this->whmcsNumbering(),
            $this->extensions(),
        ];
    }

    /**
     * True when WHMCS numbers invoices the way the addon expects.
     */
    public function whmcsReady(): bool
    {
        foreach ($this->whmcsNumbering() as $check) {
            if ($check['status'] === self::DANGER) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{label: string, status: string, detail: string}
     */
    private function processing(array $settings): array
    {
        return $settings['enabled']
            ? self::check('check_processing', self::OK, Lang::get('check_processing_on'))
            : self::check('check_processing', self::WARNING, Lang::get('check_processing_off'));
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function environment(): array
    {
        return Settings::environment() === Settings::ENV_PROD
            ? self::check('check_environment', self::OK, Lang::get('env_prod'))
            : self::check('check_environment', self::INFO, Lang::get('check_environment_test'));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{label: string, status: string, detail: string}
     */
    private function company(array $settings): array
    {
        $problems = SettingsForm::companyProblems($settings);
        if ($problems === []) {
            return self::check('check_company', self::OK, (string) $settings['company_legal_name'] . ', CUI ' . (string) $settings['company_cui']);
        }
        $labels = array_map(static fn (string $name): string => Lang::get('setting_' . $name), $problems);

        return self::check('check_company', self::DANGER, Lang::get('check_company_missing', implode(', ', $labels)));
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    private function whmcsNumbering(): array
    {
        $checks = [];
        $flags = [
            'check_sequential' => [$this->invoicing->sequentialPaidNumbering(), true],
            'check_proforma' => [$this->invoicing->proformaInvoicing(), true],
            'check_date_on_payment' => [$this->invoicing->invoiceDateOnPayment(), true],
            'check_custom_numbering' => [$this->invoicing->customInvoiceNumbering(), false],
        ];
        foreach ($flags as $key => [$actual, $expected]) {
            $checks[] = $actual === $expected
                ? self::check($key, self::OK, Lang::get($expected ? 'value_enabled' : 'value_disabled'))
                : self::check($key, self::DANGER, Lang::get($key . '_fix'));
        }

        $format = $this->invoicing->numberFormat();
        if (InvoicingConfig::numberPattern($format) === null) {
            $checks[] = self::check('check_number_format', self::DANGER, Lang::get('check_number_format_fix', $format));

            return $checks;
        }

        $counter = $this->invoicing->nextNumberValue();
        $next = $this->invoicing->format($counter);
        $highest = $this->invoicing->highestIssuedNumber();
        if ($highest !== null && (!ctype_digit($counter) || (int) $counter <= $highest['value'])) {
            // Suggest the next value with the same zero padding as the series.
            $suggested = str_pad((string) ($highest['value'] + 1), strlen($highest['counter']), '0', STR_PAD_LEFT);
            $checks[] = self::check('check_counter', self::DANGER, Lang::get('check_counter_fix', $next, $highest['number'], $suggested));
        } else {
            $checks[] = self::check('check_counter', self::OK, Lang::get('check_counter_ok', $format, $next));
        }

        return $checks;
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function extensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext): bool => !extension_loaded($ext)));

        return $missing === []
            ? self::check('check_php', self::OK, 'PHP ' . PHP_VERSION . ': ' . implode(', ', self::REQUIRED_EXTENSIONS))
            : self::check('check_php', self::DANGER, Lang::get('check_php_missing', implode(', ', $missing)));
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private static function check(string $labelKey, string $status, string $detail): array
    {
        return ['label' => Lang::get($labelKey), 'status' => $status, 'detail' => $detail];
    }
}
