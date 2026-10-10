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

<form method="get" action="addonmodules.php" class="form-inline efactura-filters">
    <input type="hidden" name="module" value="efactura">
    <input type="hidden" name="view" value="documents">
    <div class="form-group">
        <label for="efactura-filter-state">{$lang.filter_state}</label>
        <select class="form-control input-sm" id="efactura-filter-state" name="state">
            {foreach $stateOptions as $option}
                <option value="{$option.value}"{if $option.value == $filter.state} selected{/if}>{$option.label}</option>
            {/foreach}
        </select>
    </div>
    <div class="form-group">
        <label for="efactura-filter-kind">{$lang.filter_kind}</label>
        <select class="form-control input-sm" id="efactura-filter-kind" name="kind">
            <option value=""{if $filter.kind == ''} selected{/if}>{$lang.filter_all}</option>
            <option value="invoice"{if $filter.kind == 'invoice'} selected{/if}>{$lang.kind_invoice}</option>
            <option value="storno"{if $filter.kind == 'storno'} selected{/if}>{$lang.kind_storno}</option>
        </select>
    </div>
    <div class="form-group">
        <label for="efactura-filter-q" class="sr-only">{$lang.filter_search}</label>
        <input type="search" class="form-control input-sm" id="efactura-filter-q" name="q" value="{$filter.q}" placeholder="{$lang.filter_search}">
    </div>
    <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-search"></i> {$lang.btn_filter}</button>
    <span class="text-muted efactura-count">{$countText}</span>
</form>

{if $documents}
    <div class="table-responsive">
        <table class="table table-condensed table-hover efactura-table">
            <thead>
                <tr>
                    <th>{$lang.col_number}</th>
                    <th>{$lang.col_kind}</th>
                    <th>{$lang.col_invoice}</th>
                    <th>{$lang.col_client}</th>
                    <th>{$lang.col_issue_date}</th>
                    <th class="text-right">{$lang.col_total}</th>
                    <th>{$lang.col_state}</th>
                    <th>{$lang.col_deadline}</th>
                    <th>{$lang.col_upload_index}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $documents as $document}
                    <tr>
                        <td><a href="{$document.url}"><strong>{$document.number}</strong></a></td>
                        <td>{$document.kindLabel}{if $document.reasonLabel}<div class="text-muted small">{$document.reasonLabel}</div>{/if}</td>
                        <td><a href="invoices.php?action=edit&amp;id={$document.invoiceId}">#{$document.invoiceId}</a></td>
                        <td><a href="clientssummary.php?userid={$document.clientId}">{$document.client}</a></td>
                        <td>{$document.issueDate}</td>
                        <td class="text-right">{$document.total}</td>
                        <td><span class="label label-{$document.stateClass}">{$document.stateLabel}</span></td>
                        <td{if $document.late} class="text-danger"{/if}>{$document.deadline}</td>
                        <td><code>{$document.uploadIndex}</code></td>
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
    <p class="text-muted">{$lang.documents_none_found}</p>
{/if}

{include file="_footer.tpl"}
