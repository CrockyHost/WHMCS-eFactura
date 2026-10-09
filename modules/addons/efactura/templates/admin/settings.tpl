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

{if $alert}
    <div class="alert alert-{$alert.type}">{$alert.text}</div>
{/if}

<form method="post" action="{$modulelink}&amp;view=settings" class="form-horizontal efactura-settings">
    <input type="hidden" name="token" value="{$csrfToken}">

    {foreach $sections as $section}
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$section.title}</h3>
            </div>
            <div class="panel-body">
                {if $section.intro}
                    <p class="text-muted efactura-intro">{$section.intro}</p>
                {/if}
                {foreach $section.fields as $field}
                    <div class="form-group{if $field.error} has-error{/if}">
                        <label class="col-sm-4 col-md-3 control-label" for="{$field.id}">{$field.label}</label>
                        <div class="col-sm-8 col-md-6">
                            {if $field.type == 'text'}
                                <input type="text" class="form-control" id="{$field.id}" name="{$field.name}" value="{$field.value}"{if $field.maxlength} maxlength="{$field.maxlength}"{/if}>
                            {elseif $field.type == 'checkbox'}
                                <input type="hidden" name="{$field.name}" value="0">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" id="{$field.id}" name="{$field.name}" value="1"{if $field.checked} checked{/if}>
                                        {$lang.value_yes}
                                    </label>
                                </div>
                            {elseif $field.type == 'select'}
                                <select class="form-control" id="{$field.id}" name="{$field.name}">
                                    {foreach $field.options as $option}
                                        <option value="{$option.value}"{if $option.selected} selected{/if}>{$option.label}</option>
                                    {/foreach}
                                </select>
                            {elseif $field.type == 'radio'}
                                {foreach $field.options as $option}
                                    <label class="radio-inline">
                                        <input type="radio" name="{$field.name}" value="{$option.value}"{if $option.selected} checked{/if}>
                                        {$option.label}
                                    </label>
                                {/foreach}
                            {elseif $field.type == 'checklist'}
                                {foreach $field.options as $option}
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="{$field.name}[]" value="{$option.value}"{if $option.selected} checked{/if}>
                                            {$option.label}
                                        </label>
                                    </div>
                                {/foreach}
                            {elseif $field.type == 'info'}
                                <p class="form-control-static" id="{$field.id}">{$field.value}</p>
                            {/if}
                            {if $field.error}
                                <span class="help-block efactura-error">{$field.error}</span>
                            {/if}
                            {if $field.help}
                                <span class="help-block">{$field.help}</span>
                            {/if}
                        </div>
                    </div>
                {/foreach}
            </div>
        </div>
    {/foreach}

    <div class="efactura-actions">
        <button type="submit" class="btn btn-primary">{$lang.button_save}</button>
    </div>
</form>

{include file="_footer.tpl"}
