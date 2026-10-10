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
 *
 * One fiscal document: $doc (DocumentPresenter), $main (the invoice itself,
 * not a storno), $showLink (link to the detail page).
 *}
<div class="efactura-doc{if !$main} efactura-doc-storno{/if}">
    <div class="efactura-doc-head">
        <span class="efactura-doc-number">{$doc.number}</span>
        {if $doc.kind == 'storno'}<span class="efactura-doc-reason">{$doc.reasonLabel}</span>{/if}
        <span class="label label-{$doc.stateClass}">{$doc.stateLabel}</span>
        {if $showLink}<a class="efactura-doc-link" href="{$doc.detailUrl}">{$lang.btn_details} <i class="fas fa-angle-right"></i></a>{/if}
    </div>

    <dl class="efactura-facts">
        <dt>{$lang.panel_issue_date}</dt><dd>{$doc.issueDate}</dd>
        {if $doc.total}<dt>{$lang.panel_total}</dt><dd>{$doc.total}</dd>{/if}
        {if $doc.original}<dt>{$lang.panel_corrects}</dt><dd>{$doc.original.number}</dd>{/if}
        {if $doc.sendAfter}<dt>{$lang.panel_send_after}</dt><dd>{$doc.sendAfter}</dd>{/if}
        {if $doc.deadline}<dt>{$lang.panel_deadline}</dt><dd>{$doc.deadline}</dd>{/if}
        {if $doc.uploadIndex}<dt>{$lang.panel_upload_index}</dt><dd><code>{$doc.uploadIndex}</code>{if $doc.environment} <span class="text-muted">({$doc.environment})</span>{/if}</dd>{/if}
        {if $doc.anafState}<dt>{$lang.panel_anaf_state}</dt><dd>{$doc.anafState}</dd>{/if}
        {if $doc.uploadedAt}<dt>{$lang.panel_uploaded_at}</dt><dd>{$doc.uploadedAt}</dd>{/if}
        {if $doc.validatedAt}<dt>{$lang.panel_validated_at}</dt><dd>{$doc.validatedAt}</dd>{/if}
        {if $doc.attempts > 1}<dt>{$lang.panel_attempts}</dt><dd>{$doc.attempts}</dd>{/if}
        {if $doc.nextAttempt}<dt>{$lang.panel_next_attempt}</dt><dd>{$doc.nextAttempt}</dd>{/if}
        {if $doc.issuedBy}<dt>{$lang.panel_issued_by}</dt><dd>{$doc.issuedBy}</dd>{/if}
    </dl>

    {if $doc.waitsForOriginal}
        <div class="efactura-note efactura-note-info"><i class="fas fa-info-circle"></i> {$lang.panel_waits_for_original}</div>
    {/if}
    {if $doc.review}
        <div class="efactura-note efactura-note-warning"><i class="fas fa-exclamation-triangle"></i> {$lang.panel_review} {$doc.review}</div>
    {/if}
    {if $doc.held}
        <div class="efactura-note efactura-note-warning"><i class="fas fa-pause-circle"></i> {$doc.held}</div>
    {/if}
    {if $doc.excluded}
        <div class="efactura-note efactura-note-info"><i class="fas fa-info-circle"></i> {$lang.panel_excluded} {$doc.excluded}</div>
    {/if}
    {if $doc.errors}
        <div class="efactura-note efactura-note-danger">
            <strong>{$lang.panel_errors}</strong>
            <ul>{foreach $doc.errors as $error}<li>{$error}</li>{/foreach}</ul>
        </div>
    {elseif $doc.lastError}
        <div class="efactura-note efactura-note-danger"><strong>{$lang.panel_last_error}</strong> {$doc.lastError}</div>
    {/if}
    {if $doc.archiveLost}
        <div class="efactura-note efactura-note-warning"><i class="fas fa-exclamation-triangle"></i> {$lang.file_archive_lost}</div>
    {/if}

    {if $doc.files}
        <ul class="efactura-files">
            {foreach $doc.files as $file}
                <li><a href="{$file.url}"><i class="fas {$file.icon}"></i> {$file.label}</a> <span class="text-muted">{$file.date}</span></li>
            {/foreach}
        </ul>
    {/if}

    {if $doc.actions || $doc.checkable}
        <div class="efactura-actions">
            {foreach $doc.actions as $action}
                <form method="post" action="{$actionUrl}"{if $action.confirm} data-efactura-confirm="{$action.confirm}"{/if}>
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <input type="hidden" name="return" value="{$returnUrl}">
                    <input type="hidden" name="efactura_action" value="{$action.action}">
                    <input type="hidden" name="document" value="{$doc.id}">
                    <button type="submit" class="btn btn-{$action.style}{if $main && $action@first} efactura-main-action{/if}{if !$main} btn-sm{/if}">
                        {if $action.action == 'send_now'}<i class="fas fa-paper-plane"></i> {elseif $action.action == 'hold'}<i class="fas fa-pause"></i> {elseif $action.action == 'release'}<i class="fas fa-play"></i> {/if}{$action.label}
                    </button>
                </form>
            {/foreach}
            {if $doc.checkable}
                <form method="post" action="{$actionUrl}">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <input type="hidden" name="return" value="{$returnUrl}">
                    <input type="hidden" name="efactura_action" value="check">
                    <input type="hidden" name="document" value="{$doc.id}">
                    <button type="submit" class="btn btn-link"><i class="fas fa-clipboard-check"></i> {$lang.btn_check}</button>
                </form>
            {/if}
        </div>
    {/if}
</div>
