{*
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 *}
<link rel="stylesheet" href="{$assetBase}/admin.css?v={$assetVersion}">
<script src="{$assetBase}/admin.js?v={$assetVersion}" defer></script>
<div class="efactura efactura-panel panel panel-default" id="efactura-panel">
    <div class="panel-heading">
        <h3 class="panel-title">
            <i class="fas fa-file-invoice"></i> {$lang.panel_title}
            {if $environment == 'prod'}
                <span class="label label-success">{$lang.env_prod}</span>
            {else}
                <span class="label label-warning">{$lang.env_test}</span>
            {/if}
            {if !$enabled}<span class="label label-default">{$lang.badge_processing_off}</span>{/if}
        </h3>
    </div>
    <div class="panel-body">
        {if $flash}
            <div class="alert alert-{$flash.type} efactura-flash">
                {$flash.text}
                {if $flash.details}<ul>{foreach $flash.details as $detail}<li>{$detail}</li>{/foreach}</ul>{/if}
            </div>
        {/if}

        {if $document}
            {include file="_document.tpl" doc=$document main=true showLink=true}

            {if $stornos}
                <h4 class="efactura-subtitle">{$lang.panel_stornos}</h4>
                {foreach $stornos as $storno}
                    {include file="_document.tpl" doc=$storno main=false showLink=true}
                {/foreach}
            {/if}

            {if $storno.possible}
                <div class="efactura-storno">
                    <a class="efactura-storno-toggle" data-toggle="collapse" href="#efactura-storno-form" role="button" aria-expanded="false" aria-controls="efactura-storno-form">
                        <i class="fas fa-undo-alt"></i> {$lang.panel_storno_title}
                    </a>
                    <div class="collapse" id="efactura-storno-form">
                        <form method="post" action="{$actionUrl}" class="form-inline" data-efactura-confirm="{$lang.panel_storno_confirm}">
                            <p class="help-block">{$lang.panel_storno_intro} <strong>{$storno.total}</strong></p>
                            <input type="hidden" name="token" value="{$csrfToken}">
                            <input type="hidden" name="return" value="{$returnUrl}">
                            <input type="hidden" name="efactura_action" value="storno">
                            <input type="hidden" name="invoice" value="{$invoiceId}">
                            <div class="form-group">
                                <label for="efactura-storno-net">{$lang.panel_storno_net}</label>
                                <input type="text" class="form-control input-sm" id="efactura-storno-net" name="net" value="{$storno.net}" size="10" inputmode="decimal">
                            </div>
                            <div class="form-group">
                                <label for="efactura-storno-tax">{$lang.panel_storno_tax}</label>
                                <input type="text" class="form-control input-sm" id="efactura-storno-tax" name="tax" value="{$storno.tax}" size="10" inputmode="decimal">
                            </div>
                            <span class="text-muted">{$storno.currency}</span>
                            <button type="submit" class="btn btn-warning btn-sm">{$lang.btn_storno}</button>
                        </form>
                    </div>
                </div>
            {/if}
        {elseif $proforma}
            <p class="efactura-muted"><i class="fas fa-info-circle"></i> {$lang.panel_proforma}</p>
            {if $canIssueEarly}
                <form method="post" action="{$actionUrl}" data-efactura-confirm="{$lang.panel_issue_early_confirm}">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <input type="hidden" name="return" value="{$returnUrl}">
                    <input type="hidden" name="efactura_action" value="issue_early">
                    <input type="hidden" name="invoice" value="{$invoiceId}">
                    <button type="submit" class="btn btn-default"><i class="fas fa-stamp"></i> {$lang.btn_issue_early}</button>
                </form>
            {/if}
        {elseif $notFiscal}
            <p class="efactura-muted"><i class="fas fa-info-circle"></i> {$lang.panel_not_fiscal} {$notFiscal}</p>
        {elseif $noDocument}
            <p class="efactura-muted"><i class="fas fa-info-circle"></i> {$lang.panel_no_document}</p>
        {/if}
    </div>
</div>
