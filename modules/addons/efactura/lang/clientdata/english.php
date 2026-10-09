<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

// Strings of the client forms (client area and the client pages of the admin
// area) and of their settings. Loaded after lang/<language>.php, so it only
// adds keys, all prefixed with "cd_" or named after the settings.

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Client area: county and sector
$_ADDONLANG['cd_error_county'] = 'Choose the county from the list.';
$_ADDONLANG['cd_error_sector'] = 'For an address in Bucharest, choose the sector.';
$_ADDONLANG['cd_hint_state_invalid'] = '"%s" is not a county. Choose the county from the list.';
$_ADDONLANG['cd_hint_sector_missing'] = 'Choose the sector of the Bucharest address.';
$_ADDONLANG['cd_sector'] = 'Sector';
$_ADDONLANG['cd_sector_choose'] = 'Choose the sector';

// Settings
$_ADDONLANG['section_client_forms'] = 'Client forms';
$_ADDONLANG['section_client_forms_intro'] = 'Fields for Romanian clients at registration, at checkout, in the profile and in contacts.';
$_ADDONLANG['setting_client_forms'] = 'Extend the client forms';
$_ADDONLANG['help_client_forms'] = 'The county is chosen from a list, and in Bucharest also the sector. Applies to the client pages of the admin area too.';
$_ADDONLANG['setting_client_validation_new'] = 'Validation at registration and checkout';
$_ADDONLANG['setting_client_validation_profile'] = 'Validation in the profile and contacts';
$_ADDONLANG['help_client_validation_profile'] = 'Fields the client cannot change never block saving.';
$_ADDONLANG['client_validation_strict'] = 'Strict: invalid data cannot be saved';
$_ADDONLANG['client_validation_warn'] = 'Warning only';
