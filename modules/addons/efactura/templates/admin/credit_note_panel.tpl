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
 *
 * Rendered in the page footer; admin.js moves it next to the status of the
 * credit note.
 *}
<link rel="stylesheet" href="{$assetBase}/admin.css?v={$assetVersion}">
<script src="{$assetBase}/admin.js?v={$assetVersion}" defer></script>
<div class="efactura efactura-panel panel panel-default" id="efactura-panel" data-efactura-place="credit-note">
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
        {if $stornos}
            {foreach $stornos as $storno}
                {include file="_document.tpl" doc=$storno main=true showLink=true}
            {/foreach}
        {else}
            <p class="efactura-muted"><i class="fas fa-info-circle"></i> {$lang.note_no_storno}</p>
        {/if}
        {if $original}
            <p class="efactura-muted">{$lang.note_corrects} <a href="{$adminBase}invoices.php?action=edit&amp;id={$original.invoiceId}">{$original.number}</a></p>
        {/if}
    </div>
</div>
