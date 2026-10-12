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
<div class="efactura-references">
    <a class="efactura-references-toggle" data-toggle="collapse" href="#efactura-references" role="button" aria-expanded="{if $references.open}true{else}false{/if}" aria-controls="efactura-references">
        <i class="fas fa-tags"></i> {$lang.panel_references_title}
        {if $references.count}<span class="badge">{$references.count}</span>{/if}
    </a>
    <div class="collapse{if $references.open} in{/if}" id="efactura-references">
        {if $references.editable}
            <form method="post" action="{$actionUrl}" class="form-horizontal">
                <p class="help-block">{$lang.panel_references_help}</p>
                <input type="hidden" name="token" value="{$csrfToken}">
                <input type="hidden" name="return" value="{$returnUrl}">
                <input type="hidden" name="efactura_action" value="references">
                <input type="hidden" name="invoice" value="{$invoiceId}">
                {foreach $references.fields as $field}
                    <div class="form-group form-group-sm">
                        <label for="{$field.id}" class="col-sm-5 control-label">{$field.label}</label>
                        <div class="col-sm-7">
                            <input type="text" class="form-control" id="{$field.id}" name="references[{$field.term}]" value="{$field.value}" placeholder="{$field.placeholder}" maxlength="{$field.max}">
                        </div>
                    </div>
                {/foreach}
                <div class="form-group form-group-sm">
                    <div class="col-sm-offset-5 col-sm-7">
                        <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-save"></i> {$lang.btn_save_references}</button>
                    </div>
                </div>
            </form>
        {else}
            <p class="help-block">{$lang.panel_references_frozen}</p>
            {if $references.fields}
                <table class="table table-condensed efactura-references-sent">
                    {foreach $references.fields as $field}
                        <tr><th scope="row">{$field.label}</th><td>{$field.value}</td></tr>
                    {/foreach}
                </table>
            {else}
                <p class="efactura-muted">{$lang.panel_references_none}</p>
            {/if}
        {/if}
    </div>
</div>
