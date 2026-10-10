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
<div class="efactura">
    <div class="efactura-header">
        <div class="efactura-badges">
            {if $environment == 'prod'}
                <span class="label label-success">{$lang.env_prod}</span>
            {else}
                <span class="label label-warning">{$lang.env_test}</span>
            {/if}
            {if $enabled}
                <span class="label label-success">{$lang.badge_processing_on}</span>
            {else}
                <span class="label label-default">{$lang.badge_processing_off}</span>
            {/if}
        </div>
        <ul class="nav nav-tabs">
            {foreach $nav as $item}
                <li{if $item.active} class="active"{/if}><a href="{$modulelink}&amp;view={$item.view}">{$item.label}{if $item.badge} <span class="badge efactura-nav-badge">{$item.badge}</span>{/if}</a></li>
            {/foreach}
        </ul>
    </div>
    <div class="efactura-body">
        {if $flash}
            <div class="alert alert-{$flash.type} efactura-flash">
                {$flash.text}
                {if $flash.details}<ul>{foreach $flash.details as $detail}<li>{$detail}</li>{/foreach}</ul>{/if}
            </div>
        {/if}
