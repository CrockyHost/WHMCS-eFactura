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
$_ADDONLANG['cd_error_county'] = 'Alegeți județul din listă.';
$_ADDONLANG['cd_error_sector'] = 'Pentru o adresă din București, alegeți sectorul.';
$_ADDONLANG['cd_hint_state_invalid'] = '„%s” nu este un județ. Alegeți județul din listă.';
$_ADDONLANG['cd_hint_sector_missing'] = 'Alegeți sectorul adresei din București.';
$_ADDONLANG['cd_sector'] = 'Sector';
$_ADDONLANG['cd_sector_choose'] = 'Alegeți sectorul';

// Settings
$_ADDONLANG['section_client_forms'] = 'Formulare client';
$_ADDONLANG['section_client_forms_intro'] = 'Câmpurile pentru clienții din România la înregistrare, la comandă, în profil și în contacte.';
$_ADDONLANG['setting_client_forms'] = 'Extinde formularele clienților';
$_ADDONLANG['help_client_forms'] = 'Județul se alege din listă, iar pentru București și sectorul. Se aplică și paginilor clientului din admin.';
$_ADDONLANG['setting_client_validation_new'] = 'Validare la înregistrare și comandă';
$_ADDONLANG['setting_client_validation_profile'] = 'Validare în profil și în contacte';
$_ADDONLANG['help_client_validation_profile'] = 'Câmpurile pe care clientul nu le poate modifica nu blochează niciodată salvarea.';
$_ADDONLANG['client_validation_strict'] = 'Strictă: datele invalide nu se pot salva';
$_ADDONLANG['client_validation_warn'] = 'Doar avertizare';
