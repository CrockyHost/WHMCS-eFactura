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

<div class="row">
    <div class="col-md-7">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    {$lang.anaf_status_title}
                    <span class="label label-{$stateClass} efactura-state">{$stateLabel}</span>
                </h3>
            </div>
            <table class="table efactura-details">
                <tbody>
                    {foreach $details as $row}
                        <tr>
                            <th>{$row[0]}</th>
                            <td>{$row[1]}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
            <div class="panel-footer">
                {if $status.configured}
                    <p class="text-muted efactura-intro">{$connectHelp}</p>
                    <form method="post" action="{$modulelink}&amp;view=anaf" class="efactura-inline">
                        <input type="hidden" name="token" value="{$csrfToken}">
                        <input type="hidden" name="action" value="authorize">
                        <button type="submit" name="mode" value="self" class="btn btn-primary">
                            <i class="fas fa-key"></i>
                            {if $status.connected}{$lang.anaf_button_reconnect}{else}{$lang.anaf_button_connect}{/if}
                        </button>
                        <button type="submit" name="mode" value="link" class="btn btn-default">
                            <i class="fas fa-link"></i> {$lang.anaf_button_link}
                        </button>
                    </form>
                    {if $status.connected}
                        <form method="post" action="{$modulelink}&amp;view=anaf" class="efactura-inline">
                            <input type="hidden" name="token" value="{$csrfToken}">
                            <button type="submit" name="action" value="check" class="btn btn-default">
                                <i class="fas fa-plug"></i> {$lang.anaf_button_check}
                            </button>
                            <button type="submit" name="action" value="refresh" class="btn btn-default">
                                <i class="fas fa-sync"></i> {$lang.anaf_button_refresh}
                            </button>
                        </form>
                        <form method="post" action="{$modulelink}&amp;view=anaf" class="efactura-inline efactura-right"
                              onsubmit="return confirm('{$lang.anaf_disconnect_confirm|escape:'javascript'}');">
                            <input type="hidden" name="token" value="{$csrfToken}">
                            <button type="submit" name="action" value="disconnect" class="btn btn-link text-danger">
                                {$lang.anaf_button_disconnect}
                            </button>
                        </form>
                    {/if}
                {else}
                    <span class="text-muted">{$lang.check_anaf_not_configured}</span>
                {/if}
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">{$lang.anaf_app_title}</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted">{$lang.anaf_app_intro}</p>
                <ol class="efactura-steps">
                    {foreach $appSteps as $step}
                        <li>{$step}</li>
                    {/foreach}
                </ol>

                <div class="form-group">
                    <label for="efactura-callback">{$lang.anaf_callback_url}</label>
                    <input type="text" class="form-control efactura-mono" id="efactura-callback" value="{$callbackUrl}" readonly onclick="this.select();">
                    {if !$callbackHttps}
                        <span class="help-block efactura-error text-danger">{$lang.anaf_callback_not_https}</span>
                    {/if}
                </div>

                <form method="post" action="{$modulelink}&amp;view=anaf">
                    <input type="hidden" name="token" value="{$csrfToken}">
                    <input type="hidden" name="action" value="credentials">
                    <div class="form-group{if isset($errors.client_id)} has-error{/if}">
                        <label for="efactura-client-id">{$lang.anaf_client_id}</label>
                        <input type="text" class="form-control efactura-mono" id="efactura-client-id" name="client_id" value="{$clientId}" autocomplete="off">
                        {if isset($errors.client_id)}<span class="help-block efactura-error">{$errors.client_id}</span>{/if}
                    </div>
                    <div class="form-group{if isset($errors.client_secret)} has-error{/if}">
                        <label for="efactura-client-secret">{$lang.anaf_client_secret}</label>
                        <input type="password" class="form-control efactura-mono" id="efactura-client-secret" name="client_secret" value="" autocomplete="new-password"{if $status.has_secret} placeholder="{$lang.anaf_secret_saved}"{/if}>
                        {if isset($errors.client_secret)}<span class="help-block efactura-error">{$errors.client_secret}</span>{/if}
                    </div>
                    <button type="submit" class="btn btn-default">{$lang.anaf_button_save}</button>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="_footer.tpl"}
