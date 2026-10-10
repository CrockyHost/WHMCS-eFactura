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
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
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
    private const VIEWS = ['dashboard', 'documents', 'anaf', 'settings'];

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
        if ($view === 'action') {
            $this->action();

            return;
        }
        if ($view === 'download') {
            $this->download((int) ($_GET['archive'] ?? 0));

            return;
        }
        if ($view === 'document') {
            echo $this->document((int) ($_GET['id'] ?? 0));

            return;
        }
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'dashboard';
        }

        echo match ($view) {
            'documents' => $this->page('documents', (new DocumentsPage($this->modulelink()))->list($_GET)),
            'anaf' => $this->anaf(),
            'settings' => $this->settings(),
            default => $this->dashboard(),
        };
    }

    /**
     * An action posted from the invoice panel or the addon pages; the result
     * is shown on the page the admin came from.
     */
    private function action(): void
    {
        $return = self::returnUrl((string) ($_POST['return'] ?? ''), $this->modulelink());
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !$this->validToken()) {
            Flash::set('danger', Lang::get('error_csrf'));
        } else {
            $result = (new AdminActions())->run((string) ($_POST['efactura_action'] ?? ''), $_POST, AdminContext::id());
            Flash::set($result['type'], $result['text'], $result['details']);
        }
        self::redirect($return);
    }

    /**
     * Sends an archived file (the XML sent, the signed ZIP of ANAF or its
     * errors) as a download.
     */
    private function download(int $archiveId): void
    {
        $archive = Capsule::table('mod_efactura_archive')->where('id', $archiveId)->first();
        if ($archive === null) {
            echo '<div class="alert alert-danger">' . htmlspecialchars(Lang::get('download_missing'), ENT_QUOTES) . '</div>';

            return;
        }
        $number = (string) Capsule::table(Document::TABLE)->where('id', $archive->document_id)->value('number');
        $filename = self::downloadName($number, (string) $archive->kind, (string) $archive->filename);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ((string) $archive->mime !== '' ? $archive->mime : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen((string) $archive->content));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $archive->content;
        exit;
    }

    public static function downloadName(string $number, string $kind, string $filename): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $number !== '' ? $number : pathinfo($filename, PATHINFO_FILENAME));

        return match ($kind) {
            'xml_sent' => $base . '.xml',
            'anaf_zip' => $base . '_semnat_ANAF.zip',
            'anaf_errors_zip' => $base . '_erori_ANAF.zip',
            default => $base . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename),
        };
    }

    /**
     * Where to go back after an action: only pages of the admin area that
     * show e-Factura data.
     */
    public static function returnUrl(string $url, string $fallback): string
    {
        $allowed = [
            '#^invoices\.php\?action=edit&id=\d+$#',
            '#^billing/billingnote/credit/\d+$#',
            '#^addonmodules\.php\?module=efactura(?:&[A-Za-z0-9_]+=[A-Za-z0-9_.,%-]*)*$#',
        ];
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return $url;
            }
        }

        return $fallback;
    }

    private static function redirect(string $url): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Location: ' . $url, true, 303);
        } else {
            echo '<script>window.location.href = ' . json_encode($url) . ';</script><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a>';
        }
        exit;
    }

    private function modulelink(): string
    {
        return (string) ($this->vars['modulelink'] ?? 'addonmodules.php?module=' . Addon::MODULE);
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
            $states[] = ['label' => Lang::get('state_' . $state), 'state' => (string) $state, 'total' => (int) $total, 'url' => $this->modulelink() . '&view=documents&state=' . rawurlencode((string) $state)];
        }

        return $this->page('dashboard', [
            'checks' => $checks->all(),
            'states' => $states,
            'queue' => (new DocumentsPage($this->modulelink()))->queue(),
        ]);
    }

    private function document(int $documentId): string
    {
        $detail = (new DocumentsPage($this->modulelink()))->detail($documentId);

        return $this->page('document', ($detail ?? ['doc' => null]) + [
            'actionUrl' => $this->modulelink() . '&view=action',
            'returnUrl' => $this->modulelink() . '&view=document&id=' . $documentId,
        ], 'documents');
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
            'modulelink' => $this->modulelink(),
            'flash' => Flash::pull(),
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
