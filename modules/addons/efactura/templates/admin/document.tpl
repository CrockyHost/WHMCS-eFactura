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

<p><a href="{$modulelink}&amp;view=documents"><i class="fas fa-angle-left"></i> {$lang.nav_documents}</a></p>

{if !$doc}
    <div class="alert alert-warning">{$lang.document_not_found}</div>
{else}
    <div class="row">
        <div class="col-md-7">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">{$doc.kindLabel} {$doc.number}</h3>
                </div>
                <div class="panel-body">
                    <dl class="efactura-facts">
                        <dt>{$lang.col_invoice}</dt><dd><a href="invoices.php?action=edit&amp;id={$doc.invoiceId}">#{$doc.invoiceId}</a></dd>
                        <dt>{$lang.col_client}</dt><dd><a href="clientssummary.php?userid={$clientId}">{$client}</a></dd>
                        {if $doc.original}<dt>{$lang.panel_corrects}</dt><dd><a href="{$modulelink}&amp;view=document&amp;id={$doc.original.id}">{$doc.original.number}</a></dd>{/if}
                    </dl>
                    {include file="_document.tpl" doc=$doc main=true showLink=false}
                </div>
            </div>

            {if $related}
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title">{$lang.panel_stornos}</h3></div>
                    <div class="panel-body">
                        {foreach $related as $storno}
                            {include file="_document.tpl" doc=$storno main=false showLink=true}
                        {/foreach}
                    </div>
                </div>
            {/if}
        </div>
        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading"><h3 class="panel-title">{$lang.document_history}</h3></div>
                {if $history}
                    <ul class="list-group efactura-history">
                        {foreach $history as $entry}
                            <li class="list-group-item">
                                <div class="efactura-history-head">
                                    <strong>{$entry.event}</strong>
                                    <span class="text-muted">{$entry.date}</span>
                                </div>
                                {if $entry.states}<div class="small">{$entry.states}</div>{/if}
                                {if $entry.message}<div class="small efactura-history-message">{$entry.message}</div>{/if}
                                {if $entry.admin}<div class="small text-muted">{$entry.admin}</div>{/if}
                            </li>
                        {/foreach}
                    </ul>
                {else}
                    <div class="panel-body text-muted">{$lang.history_none}</div>
                {/if}
            </div>
        </div>
    </div>
{/if}

{include file="_footer.tpl"}
