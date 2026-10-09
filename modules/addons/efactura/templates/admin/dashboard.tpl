{*
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 *}
{include file="_header.tpl"}

<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$lang.checks_title}</h3>
            </div>
            <table class="table efactura-checks">
                <tbody>
                    {foreach $checks as $check}
                        <tr class="efactura-check efactura-check-{$check.status}">
                            <td class="efactura-check-icon">
                                {if $check.status == 'ok'}
                                    <i class="fas fa-check-circle" title="{$lang.status_ok}"></i>
                                {elseif $check.status == 'info'}
                                    <i class="fas fa-info-circle" title="{$lang.status_info}"></i>
                                {elseif $check.status == 'warning'}
                                    <i class="fas fa-exclamation-triangle" title="{$lang.status_warning}"></i>
                                {else}
                                    <i class="fas fa-times-circle" title="{$lang.status_danger}"></i>
                                {/if}
                            </td>
                            <td>
                                <div class="efactura-check-label">{$check.label}</div>
                                <div class="efactura-check-detail">{$check.detail}</div>
                                {if $check.steps}
                                    <ol class="efactura-check-steps">
                                        {foreach $check.steps as $step}
                                            <li>{$step.text}<code>{$step.code}</code></li>
                                        {/foreach}
                                    </ol>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$lang.documents_title}</h3>
            </div>
            {if $states}
                <ul class="list-group">
                    {foreach $states as $state}
                        <li class="list-group-item">
                            <span class="badge">{$state.total}</span>
                            {$state.label}
                        </li>
                    {/foreach}
                </ul>
            {else}
                <div class="panel-body text-muted">{$lang.documents_none}</div>
            {/if}
        </div>
    </div>
</div>

{include file="_footer.tpl"}
