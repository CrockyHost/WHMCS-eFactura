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
     * @return list<array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}>
     */
    public function all(): array
    {
        $settings = Settings::all();

        return [
            $this->processing($settings),
            $this->environment(),
            $this->company($settings),
            ...$this->whmcsNumbering(),
            $this->timezone(),
            $this->extensions(),
        ];
    }

    /**
     * True when the system checks that block processing pass: WHMCS numbers
     * invoices the way the addon expects, the PHP time zone gives correct
     * invoice dates and the PHP extensions are present. The seller details
     * are validated by the settings form.
     */
    public function systemReady(): bool
    {
        foreach ([...$this->whmcsNumbering(), $this->timezone(), $this->extensions()] as $check) {
            if ($check['status'] === self::DANGER) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private function processing(array $settings): array
    {
        return $settings['enabled']
            ? self::check('check_processing', self::OK, Lang::get('check_processing_on'))
            : self::check('check_processing', self::WARNING, Lang::get('check_processing_off'));
    }

    /**
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private function environment(): array
    {
        return Settings::environment() === Settings::ENV_PROD
            ? self::check('check_environment', self::OK, Lang::get('env_prod'))
            : self::check('check_environment', self::INFO, Lang::get('check_environment_test'));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
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
     * @return list<array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}>
     */
    private function whmcsNumbering(): array
    {
        $checks = [];
        $flags = [
            'check_sequential' => $this->invoicing->sequentialPaidNumbering(),
            'check_proforma' => $this->invoicing->proformaInvoicing(),
            'check_date_on_payment' => $this->invoicing->invoiceDateOnPayment(),
        ];
        foreach ($flags as $key => $enabled) {
            $checks[] = $enabled
                ? self::check($key, self::OK, Lang::get('value_enabled'))
                : self::check($key, self::DANGER, Lang::get($key . '_fix'));
        }

        $fiscal = $this->invoicing->fiscalFormat();
        if (InvoicingConfig::numberPattern($fiscal) === null) {
            $checks[] = self::check('check_number_format', self::DANGER, Lang::get('check_number_format_fix', $fiscal));

            return $checks;
        }
        $checks[] = $this->series($fiscal);

        $counter = $this->invoicing->fiscalCounter();
        $next = InvoicingConfig::format($fiscal, $counter);
        $highest = $this->invoicing->highestIssuedNumber();
        if ($highest !== null && (!ctype_digit($counter) || (int) $counter <= $highest['value'])) {
            // Suggest the next value with the same zero padding as the series.
            $suggested = str_pad((string) ($highest['value'] + 1), strlen($highest['counter']), '0', STR_PAD_LEFT);
            $checks[] = self::check('check_counter', self::DANGER, Lang::get('check_counter_fix', $next, $highest['number'], $suggested));
        } else {
            $checks[] = self::check('check_counter', self::OK, Lang::get('check_counter_ok', $next));
        }

        return $checks;
    }

    /**
     * Shows the proforma and fiscal series; they must never overlap.
     *
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private function series(string $fiscal): array
    {
        $fiscalText = Lang::get('series_fiscal', $fiscal);
        if (!$this->invoicing->proformaNumbering()) {
            return self::check('check_series', self::OK, Lang::get('series_proforma_none') . ' ' . $fiscalText);
        }

        $proforma = $this->invoicing->proformaFormat();
        $proformaText = Lang::get('series_proforma', $proforma, InvoicingConfig::format($proforma, $this->invoicing->proformaCounter()));
        if (InvoicingConfig::formatsOverlap($fiscal, $proforma)) {
            return self::check('check_series', self::DANGER, Lang::get('check_series_overlap', $proforma, $fiscal));
        }

        return self::check('check_series', self::OK, $proformaText . ' ' . $fiscalText);
    }

    /**
     * WHMCS dates invoices with the PHP time zone; at payment that date
     * becomes the fiscal invoice date, so a wrong zone blocks processing.
     *
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private function timezone(): array
    {
        $zone = date_default_timezone_get();

        if ($zone === 'Europe/Bucharest') {
            return self::check('check_timezone', self::OK, $zone);
        }

        return self::check('check_timezone', self::DANGER, Lang::get('check_timezone_fix', $zone), [
            ['text' => Lang::get('check_timezone_step_config'), 'code' => "date_default_timezone_set('Europe/Bucharest');"],
            ['text' => Lang::get('check_timezone_step_ini'), 'code' => 'date.timezone = Europe/Bucharest'],
        ]);
    }

    /**
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private function extensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext): bool => !extension_loaded($ext)));

        return $missing === []
            ? self::check('check_php', self::OK, 'PHP ' . PHP_VERSION . ': ' . implode(', ', self::REQUIRED_EXTENSIONS))
            : self::check('check_php', self::DANGER, Lang::get('check_php_missing', implode(', ', $missing)));
    }

    /**
     * @param list<array{text: string, code: string}> $steps instructions to fix the problem, each with a line to copy
     * @return array{label: string, status: string, detail: string, steps: list<array{text: string, code: string}>}
     */
    private static function check(string $labelKey, string $status, string $detail, array $steps = []): array
    {
        return ['label' => Lang::get($labelKey), 'status' => $status, 'detail' => $detail, 'steps' => $steps];
    }
}
