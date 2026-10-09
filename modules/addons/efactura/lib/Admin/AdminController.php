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

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Whmcs\ClientDirectory;
use WHMCS\Module\Addon\Efactura\Whmcs\InvoicingConfig;

/**
 * Admin pages of the addon (addonmodules.php?module=efactura&view=...).
 */
final class AdminController
{
    private const VIEWS = ['dashboard', 'anaf', 'settings'];

    /**
     * @param array<string, mixed> $vars parameters WHMCS passes to efactura_output()
     */
    public function __construct(private readonly array $vars)
    {
    }

    public function handle(): void
    {
        try {
            if (Addon::migrator()->pending() !== []) {
                Addon::migrator()->migrate();
            }
        } catch (Throwable $e) {
            echo '<div class="alert alert-danger">WHMCS-eFactura: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</div>';

            return;
        }

        Lang::boot(Settings::string('ui_language'), AdminContext::id());

        $view = (string) ($_GET['view'] ?? 'dashboard');
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'dashboard';
        }

        echo match ($view) {
            'anaf' => $this->anaf(),
            'settings' => $this->settings(),
            default => $this->dashboard(),
        };
    }

    private function dashboard(): string
    {
        $checks = new HealthChecks(new InvoicingConfig(), Addon::connection());

        $counts = Capsule::table('mod_efactura_documents')
            ->select('state', Capsule::raw('COUNT(*) AS total'))
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();
        $states = [];
        foreach ($counts as $state => $total) {
            $states[] = ['label' => Lang::get('state_' . $state), 'state' => (string) $state, 'total' => (int) $total];
        }

        return $this->page('dashboard', [
            'checks' => $checks->all(),
            'states' => $states,
        ]);
    }

    private function anaf(): string
    {
        $result = (new AnafPage(Addon::connection()))->handle(
            $_POST,
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST',
            $this->validToken(),
            isset($_GET['connected'])
        );

        return $this->page($result['view'], $result['vars'], 'anaf');
    }

    private function settings(): string
    {
        $directory = new ClientDirectory();
        $form = new SettingsForm($directory);
        $healthChecks = new HealthChecks(new InvoicingConfig(), Addon::connection());

        $values = Settings::all();
        $errors = [];
        $alert = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->validToken()) {
                $alert = ['type' => 'danger', 'text' => Lang::get('error_csrf')];
            } else {
                $values = $form->read($_POST);
                $errors = $form->validate($values, $healthChecks->systemReady());
                if ($errors === []) {
                    Settings::save($values);
                    $values = Settings::all();
                    Lang::boot(Settings::string('ui_language'), AdminContext::id());
                    $alert = ['type' => 'success', 'text' => Lang::get('settings_saved')];
                    logActivity('WHMCS-eFactura: settings updated');
                } else {
                    $alert = ['type' => 'danger', 'text' => Lang::get('settings_not_saved')];
                }
            }
        }

        $page = new SettingsPage($values, $errors, $form, $directory, new InvoicingConfig());

        return $this->page('settings', [
            'alert' => $alert,
            'sections' => $page->sections(),
        ]);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function page(string $template, array $vars, ?string $tab = null): string
    {
        $tab ??= $template;
        $nav = [];
        foreach (self::VIEWS as $item) {
            $nav[] = ['view' => $item, 'label' => Lang::get('nav_' . $item), 'active' => $item === $tab];
        }

        return View::render($template, $vars + [
            'lang' => Lang::all(),
            'modulelink' => (string) ($this->vars['modulelink'] ?? 'addonmodules.php?module=' . Addon::MODULE),
            'view' => $tab,
            'nav' => $nav,
            'csrfToken' => generate_token('plain'),
            'version' => Addon::VERSION,
            'attributionHtml' => Addon::attributionHtml(),
            'assetBase' => '../modules/addons/' . Addon::MODULE . '/assets',
            'environment' => Settings::environment(),
            'enabled' => Settings::bool('enabled'),
        ]);
    }

    private function validToken(): bool
    {
        $submitted = (string) ($_POST['token'] ?? '');

        return $submitted !== '' && hash_equals((string) generate_token('plain'), $submitted);
    }
}
