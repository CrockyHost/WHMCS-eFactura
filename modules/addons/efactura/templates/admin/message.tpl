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

<p><a href="{$modulelink}&amp;view=inbox"><i class="fas fa-angle-left"></i> {$lang.nav_inbox}</a></p>

{if !$message}
    <div class="alert alert-warning">{$lang.inbox_message_missing}</div>
{else}
    <div class="row">
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">{$message.kindLabel}{if $message.number} {$message.number}{/if}</h3>
                </div>
                <div class="panel-body">
                    <dl class="efactura-facts">
                        <dt>{$lang.inbox_col_date}</dt><dd>{$message.date}</dd>
                        {if $message.issuer || $message.issuerCui}<dt>{$lang.inbox_col_from}</dt><dd>{$message.issuer} {if $message.issuerCui}<span class="text-muted">({$message.issuerCui})</span>{/if}</dd>{/if}
                        {if $message.document}<dt>{$lang.inbox_about}</dt><dd><a href="{$message.document.url}">{$message.document.number}</a></dd>{/if}
                        {if $message.requestId}<dt>{$lang.panel_upload_index}</dt><dd><code>{$message.requestId}</code></dd>{/if}
                        <dt>{$lang.inbox_details}</dt><dd>{$message.details}</dd>
                    </dl>
                    {if $message.text}
                        <div class="efactura-note efactura-note-warning"><strong>{$lang.inbox_buyer_says}</strong> {$message.text}</div>
                    {/if}
                    {if $message.downloadError}
                        <div class="efactura-note efactura-note-danger">{$lang.inbox_download_error} {$message.downloadError}</div>
                    {/if}
                    {if $message.fileLost}
                        <div class="efactura-note efactura-note-warning">{$lang.file_archive_lost}</div>
                    {/if}

                    {if $invoice}
                        <h4 class="efactura-subtitle">{$lang.inbox_invoice}</h4>
                        <dl class="efactura-facts">
                            <dt>{$lang.inbox_supplier}</dt><dd>{$invoice.supplier.name} ({$invoice.supplier.cui}){if $invoice.supplier.address}<div class="text-muted small">{$invoice.supplier.address}</div>{/if}</dd>
                            <dt>{$lang.inbox_buyer}</dt><dd>{$invoice.buyer.name} ({$invoice.buyer.cui})</dd>
                            <dt>{$lang.col_number}</dt><dd>{$invoice.number} <span class="text-muted">({$invoice.type})</span></dd>
                            <dt>{$lang.panel_issue_date}</dt><dd>{$invoice.date}</dd>
                            {if $invoice.due}<dt>{$lang.inbox_due}</dt><dd>{$invoice.due}</dd>{/if}
                        </dl>
                        <div class="table-responsive">
                            <table class="table table-condensed efactura-table">
                                <thead>
                                    <tr>
                                        <th>{$lang.inbox_line}</th>
                                        <th class="text-right">{$lang.inbox_quantity}</th>
                                        <th class="text-right">{$lang.inbox_price}</th>
                                        <th class="text-right">{$lang.inbox_vat}</th>
                                        <th class="text-right">{$lang.inbox_amount}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach $invoice.lines as $line}
                                        <tr>
                                            <td>{$line.name}</td>
                                            <td class="text-right">{$line.quantity} {$line.unit}</td>
                                            <td class="text-right">{$line.price}</td>
                                            <td class="text-right">{if $line.vat != ''}{$line.vat}%{/if}</td>
                                            <td class="text-right">{$line.amount}</td>
                                        </tr>
                                    {/foreach}
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="4" class="text-right">{$lang.inbox_net}</td><td class="text-right">{$invoice.net} {$invoice.currency}</td></tr>
                                    <tr><td colspan="4" class="text-right">{$lang.panel_storno_tax}</td><td class="text-right">{$invoice.tax} {$invoice.currency}</td></tr>
                                    <tr><td colspan="4" class="text-right"><strong>{$lang.col_total}</strong></td><td class="text-right"><strong>{$invoice.total} {$invoice.currency}</strong></td></tr>
                                    {if $invoice.payable != '' && $invoice.payable != $invoice.total}<tr><td colspan="4" class="text-right">{$lang.inbox_payable}</td><td class="text-right">{$invoice.payable} {$invoice.currency}</td></tr>{/if}
                                </tfoot>
                            </table>
                        </div>
                        {if $invoice.notes}
                            <ul class="efactura-notes small text-muted">{foreach $invoice.notes as $note}<li>{$note}</li>{/foreach}</ul>
                        {/if}
                    {/if}
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="panel panel-default">
                <div class="panel-body efactura-message-actions">
                    {if $message.fileUrl}
                        <p><a class="btn btn-default btn-block" href="{$message.fileUrl}"><i class="fas fa-file-archive"></i> {$lang.inbox_download}</a></p>
                    {/if}
                    <form method="post" action="{$actionUrl}">
                        <input type="hidden" name="token" value="{$csrfToken}">
                        <input type="hidden" name="return" value="{$returnUrl}">
                        <input type="hidden" name="message" value="{$message.id}">
                        {if $isProcessed}
                            <input type="hidden" name="efactura_action" value="message_unprocessed">
                            <button type="submit" class="btn btn-default btn-block"><i class="fas fa-undo"></i> {$lang.btn_message_unprocessed}</button>
                        {else}
                            <input type="hidden" name="efactura_action" value="message_processed">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-check"></i> {$lang.btn_message_processed}</button>
                        {/if}
                    </form>
                    {if $seen}<p class="text-muted small">{$seen}</p>{/if}
                    {if $processed}<p class="text-muted small">{$processed}</p>{/if}
                </div>
            </div>
        </div>
    </div>
{/if}

{include file="_footer.tpl"}
