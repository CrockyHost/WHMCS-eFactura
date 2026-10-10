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
                <h3 class="panel-title">{$lang.queue_title}</h3>
            </div>
            <ul class="list-group efactura-queue">
                <li class="list-group-item{if $queue.workerLate} list-group-item-warning{/if}">
                    {$lang.queue_last_run} <strong>{if $queue.lastRun}{$queue.lastRun}{else}{$lang.queue_never}{/if}</strong>
                    {if $queue.workerLate}<div class="small">{$lang.queue_worker_late}</div>{/if}
                </li>
                {if $queue.breakerUntil}
                    <li class="list-group-item list-group-item-warning">{$lang.queue_breaker} <strong>{$queue.breakerUntil}</strong></li>
                {/if}
                {if $queue.authPausedUntil}
                    <li class="list-group-item list-group-item-danger">{$lang.queue_auth_paused} <strong>{$queue.authPausedUntil}</strong></li>
                {/if}
                {if $queue.pendingStornos}
                    <li class="list-group-item list-group-item-warning"><span class="badge">{$queue.pendingStornos}</span>{$lang.queue_pending_stornos}</li>
                {/if}
                <li class="list-group-item{if $queue.nearDeadline} list-group-item-danger{/if}">
                    <span class="badge">{$queue.nearDeadline}</span>{$lang.queue_near_deadline}
                </li>
                <li class="list-group-item{if $queue.attention} list-group-item-warning{/if}">
                    <span class="badge">{$queue.attention}</span><a href="{$queue.attentionUrl}">{$lang.queue_attention}</a>
                </li>
            </ul>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$lang.documents_title}</h3>
            </div>
            {if $states}
                <div class="list-group">
                    {foreach $states as $state}
                        <a class="list-group-item" href="{$state.url}">
                            <span class="badge">{$state.total}</span>
                            {$state.label}
                        </a>
                    {/foreach}
                </div>
            {else}
                <div class="panel-body text-muted">{$lang.documents_none}</div>
            {/if}
        </div>
    </div>
</div>

{include file="_footer.tpl"}
