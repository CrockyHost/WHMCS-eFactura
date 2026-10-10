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

<div class="efactura-inbox-bar">
    <form method="get" action="addonmodules.php" class="form-inline efactura-filters">
        <input type="hidden" name="module" value="efactura">
        <input type="hidden" name="view" value="inbox">
        <div class="form-group">
            <label for="efactura-inbox-kind">{$lang.filter_kind}</label>
            <select class="form-control input-sm" id="efactura-inbox-kind" name="kind">
                {foreach $kindOptions as $option}
                    <option value="{$option.value}"{if $option.value == $filter.kind} selected{/if}>{$option.label}</option>
                {/foreach}
            </select>
        </div>
        <div class="form-group">
            <label for="efactura-inbox-show">{$lang.inbox_show}</label>
            <select class="form-control input-sm" id="efactura-inbox-show" name="show">
                <option value=""{if $filter.show == ''} selected{/if}>{$lang.filter_all}</option>
                <option value="unseen"{if $filter.show == 'unseen'} selected{/if}>{$lang.inbox_show_unseen}</option>
                <option value="unprocessed"{if $filter.show == 'unprocessed'} selected{/if}>{$lang.inbox_show_unprocessed}</option>
            </select>
        </div>
        <div class="form-group">
            <label for="efactura-inbox-q" class="sr-only">{$lang.inbox_search}</label>
            <input type="search" class="form-control input-sm" id="efactura-inbox-q" name="q" value="{$filter.q}" placeholder="{$lang.inbox_search}">
        </div>
        <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-search"></i> {$lang.btn_filter}</button>
        <span class="text-muted efactura-count">{$countText}</span>
    </form>
    <form method="post" action="{$actionUrl}" class="efactura-inbox-sync">
        <input type="hidden" name="token" value="{$csrfToken}">
        <input type="hidden" name="return" value="{$returnUrl}">
        <input type="hidden" name="efactura_action" value="inbox_sync">
        <span class="text-muted small">{$lang.inbox_last_sync} {if $lastSync}{$lastSync}{else}{$lang.queue_never}{/if}</span>
        <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-sync-alt"></i> {$lang.btn_inbox_sync}</button>
    </form>
</div>

{if $messages}
    <div class="table-responsive">
        <table class="table table-condensed table-hover efactura-table">
            <thead>
                <tr>
                    <th>{$lang.inbox_col_date}</th>
                    <th>{$lang.col_kind}</th>
                    <th>{$lang.inbox_col_from}</th>
                    <th>{$lang.inbox_col_document}</th>
                    <th class="text-right">{$lang.col_total}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach $messages as $message}
                    <tr{if $message.unseen} class="efactura-unseen"{/if}>
                        <td><a href="{$message.url}">{$message.date}</a></td>
                        <td>{$message.kindLabel}</td>
                        <td>
                            {if $message.issuer}{$message.issuer}{/if}
                            {if $message.issuerCui}<div class="text-muted small">{$message.issuerCui}</div>{/if}
                        </td>
                        <td>
                            {if $message.number}<a href="{$message.url}">{$message.number}</a>{if $message.invoiceDate} <span class="text-muted">/ {$message.invoiceDate}</span>{/if}{/if}
                            {if $message.document}<a href="{$message.document.url}">{$message.document.number}</a>{/if}
                            {if $message.text}<div class="small efactura-message-text">{$message.text}</div>{/if}
                            {if !$message.number && !$message.document && !$message.text}<span class="text-muted small">{$message.details}</span>{/if}
                        </td>
                        <td class="text-right">{$message.total}</td>
                        <td class="text-right">
                            {if $message.fileUrl}<a href="{$message.fileUrl}" title="{$lang.inbox_download}"><i class="fas fa-file-archive"></i></a>{/if}
                            {if $message.processed}<i class="fas fa-check text-success" title="{$lang.inbox_processed}"></i>{/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {if $pages > 1}
        <ul class="pager">
            {if $prevUrl}<li class="previous"><a href="{$prevUrl}">&larr;</a></li>{/if}
            <li><span>{$page} / {$pages}</span></li>
            {if $nextUrl}<li class="next"><a href="{$nextUrl}">&rarr;</a></li>{/if}
        </ul>
    {/if}
{else}
    <p class="text-muted">{$lang.inbox_none}</p>
{/if}

{include file="_footer.tpl"}
