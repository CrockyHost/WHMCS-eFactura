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
use WHMCS\Module\Addon\Efactura\Fiscal\References;
use WHMCS\Module\Addon\Efactura\Fiscal\ReportingPolicy;
use WHMCS\Module\Addon\Efactura\Fiscal\Stornos;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;
use WHMCS\Module\Addon\Efactura\Support\Money;

/**
 * The e-Factura panel on the admin invoice page (AdminInvoicesControlsOutput):
 * the fiscal document of the invoice and its stornos, their files and the
 * actions allowed, the early issue of a proforma, the manual storno and the
 * references of the invoice (BT-11, BT-12, BT-13, BT-15, BT-19, BT-46).
 */
final class InvoicePanel
{
    public function __construct(private readonly string $modulelink = 'addonmodules.php?module=' . Addon::MODULE)
    {
    }

    public function render(int $invoiceId): string
    {
        return View::render('invoice_panel', $this->vars($invoiceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function vars(int $invoiceId): array
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'status', 'invoicenum', 'userid']);
        $presenter = new DocumentPresenter($this->modulelink);
        $document = $invoice !== null ? Addon::documents()->forInvoice($invoiceId) : null;
        $fiscal = $document !== null && $document->kind === Document::KIND_INVOICE;

        $vars = [
            'invoiceId' => $invoiceId,
            'document' => $fiscal ? $presenter->present($document) : null,
            'stornos' => [],
            'buyerMessages' => $fiscal ? (new InboxPage($this->modulelink))->forDocument((int) $document->id) : [],
            'storno' => null,
            'proforma' => false,
            'canIssueEarly' => false,
            'notFiscal' => '',
            'noDocument' => false,
            'references' => $invoice !== null ? self::references($invoiceId, (int) $invoice->userid, $document) : null,
        ];

        if ($fiscal) {
            foreach (Addon::documents()->stornosOf((int) $document->id) as $storno) {
                $vars['stornos'][] = $presenter->present(Addon::documents()->find((int) $storno->id));
            }
            [$net, $tax] = Addon::stornos()->rest($document);
            $vars['storno'] = [
                'possible' => $net + $tax > 0,
                'net' => Money::format(max(0, $net)),
                'tax' => Money::format(max(0, $tax)),
                'total' => Money::format(max(0, $net + $tax)) . ' ' . $document->currency,
                'currency' => (string) $document->currency,
                'rate' => Stornos::vatRate($document),
                'vatHint' => Stornos::vatRate($document) !== '0' ? Lang::get('panel_storno_vat_hint', Stornos::vatRate($document)) : '',
            ];
        } elseif ($invoice !== null) {
            $reason = ReportingPolicy::nonFiscalReason($invoiceId);
            if ($reason !== null) {
                $vars['notFiscal'] = Lang::get('notfiscal_' . $reason);
            } elseif (in_array($invoice->status, ['Unpaid', 'Draft'], true)) {
                $vars['proforma'] = true;
                $vars['canIssueEarly'] = $invoice->status === 'Unpaid' && Settings::bool('enabled');
            } else {
                $vars['noDocument'] = true;
            }
        }

        return $vars + [
            'lang' => Lang::all(),
            'modulelink' => $this->modulelink,
            'actionUrl' => $this->modulelink . '&view=action',
            'returnUrl' => 'invoices.php?action=edit&id=' . $invoiceId,
            'csrfToken' => function_exists('generate_token') ? generate_token('plain') : '',
            'flash' => Flash::pull(),
            'version' => Addon::VERSION,
            'assetBase' => '../modules/addons/' . Addon::MODULE . '/assets',
            'environment' => Settings::environment(),
            'enabled' => Settings::bool('enabled'),
        ];
    }

    /**
     * The references of the invoice: a form while they can change (the
     * client defaults as placeholders), then the values sent. Null when the
     * invoice is not reported to RO e-Factura.
     *
     * @return array{editable: bool, open: bool, count: int, fields: list<array<string, mixed>>}|null
     */
    private static function references(int $invoiceId, int $clientId, ?object $document): ?array
    {
        if (References::editable($invoiceId, $document)) {
            $own = References::ofInvoice($invoiceId);
            $defaults = References::defaults($clientId);
            $fields = [];
            foreach (References::TERMS as $term => $max) {
                $fields[] = [
                    'term' => $term,
                    'id' => 'efactura-' . str_replace('_', '-', References::langKey($term)),
                    'label' => Lang::get(References::langKey($term)),
                    'value' => $own[$term] ?? '',
                    'placeholder' => $defaults[$term] ?? '',
                    'max' => $max,
                ];
            }

            return ['editable' => true, 'open' => $own !== [], 'count' => count($own + $defaults), 'fields' => $fields];
        }
        if ($document === null || $document->kind !== Document::KIND_INVOICE || $document->state === Document::STATE_EXCLUDED) {
            return null;
        }
        $fields = [];
        foreach (References::frozen($document) as $term => $value) {
            $fields[] = ['term' => $term, 'label' => Lang::get(References::langKey($term)), 'value' => $value];
        }

        return ['editable' => false, 'open' => false, 'count' => count($fields), 'fields' => $fields];
    }
}
