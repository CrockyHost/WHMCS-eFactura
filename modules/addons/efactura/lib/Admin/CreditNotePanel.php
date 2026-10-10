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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Fiscal\Document;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\AdminContext;
use WHMCS\Module\Addon\Efactura\Support\Lang;

/**
 * The e-Factura panel on the admin page of a WHMCS credit note
 * (billing/billingnote/credit/{id}): the storno issued from it, or why there
 * is none.
 */
final class CreditNotePanel
{
    /** The admin route of a credit note page in WHMCS 9.0. */
    private const ROUTE = '~/billing/billingnote/credit/(\d+)(?:[/?#]|$)~';

    private readonly string $modulelink;

    /**
     * The page sits deeper than the admin folder, so every link is absolute.
     */
    public function __construct(?string $modulelink = null)
    {
        $this->modulelink = $modulelink ?? AdminContext::adminUrl('addonmodules.php?module=' . Addon::MODULE);
    }

    /**
     * The credit note shown by the current admin page, if any.
     */
    public static function noteFromRequest(string $uri, string $route = ''): ?int
    {
        foreach ([$uri, $route] as $candidate) {
            if (preg_match(self::ROUTE, $candidate, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /**
     * The panel, or '' when the credit note has nothing to do with a fiscal
     * invoice.
     */
    public function render(int $noteId): string
    {
        $vars = $this->vars($noteId);

        return $vars === null ? '' : View::render('credit_note_panel', $vars);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vars(int $noteId): ?array
    {
        $presenter = new DocumentPresenter($this->modulelink);
        $stornos = [];
        $rows = Capsule::table(Document::TABLE)->where('kind', Document::KIND_STORNO)->where('billing_note_id', $noteId)->orderBy('id')->pluck('id');
        foreach ($rows as $id) {
            $stornos[] = $presenter->present(Addon::documents()->find((int) $id));
        }
        $invoiceIds = Capsule::table('tblaccounts')->where('billingnoteid', $noteId)->where('invoiceid', '>', 0)->pluck('invoiceid')->map(static fn ($id): int => (int) $id)->all();
        $original = null;
        foreach ($invoiceIds as $invoiceId) {
            $document = Addon::documents()->forInvoice($invoiceId);
            if ($document !== null && $document->kind === Document::KIND_INVOICE) {
                $original = ['id' => (int) $document->id, 'number' => (string) $document->number, 'invoiceId' => $invoiceId];
                break;
            }
        }
        if ($stornos === [] && $original === null) {
            return null;
        }

        return [
            'stornos' => $stornos,
            'original' => $original,
            'lang' => Lang::all(),
            'modulelink' => $this->modulelink,
            'actionUrl' => $this->modulelink . '&view=action',
            'returnUrl' => 'billing/billingnote/credit/' . $noteId,
            'csrfToken' => function_exists('generate_token') ? generate_token('plain') : '',
            'flash' => Flash::pull(),
            'assetBase' => rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/' . Addon::MODULE . '/assets',
            'adminBase' => AdminContext::adminUrl(''),
            'environment' => Settings::environment(),
            'enabled' => Settings::bool('enabled'),
        ];
    }
}
