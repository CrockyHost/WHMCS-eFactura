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
{include file="_header.tpl"}

<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">{$lang.anaf_authorize_title}</h3>
    </div>
    <div class="panel-body">
        {if $forOtherPerson}
            <p>{$linkIntro}</p>
            <textarea class="form-control efactura-mono" rows="4" readonly onclick="this.select();">{$authorizeUrl}</textarea>
        {else}
            <p>{$lang.anaf_authorize_redirect}</p>
            <p>
                <a href="{$authorizeUrl}" class="btn btn-primary" id="efactura-authorize" rel="noreferrer">
                    <i class="fas fa-key"></i> {$lang.anaf_authorize_continue}
                </a>
            </p>
            <script>
                window.setTimeout(function () {
                    window.location.href = document.getElementById('efactura-authorize').href;
                }, 800);
            </script>
        {/if}
        <p class="efactura-back">
            <a href="{$modulelink}&amp;view=anaf">{$lang.anaf_authorize_back}</a>
        </p>
    </div>
</div>

{include file="_footer.tpl"}
