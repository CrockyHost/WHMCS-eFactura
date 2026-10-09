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

// Client area: billing details (client type, CUI, Reg. Com., CNP)
$_ADDONLANG['cd_billing_title'] = 'Date de facturare';
$_ADDONLANG['cd_type_label'] = 'Tip client';
$_ADDONLANG['cd_type_person'] = 'Persoană fizică';
$_ADDONLANG['cd_type_person_hint'] = 'factura pe numele dumneavoastră';
$_ADDONLANG['cd_type_company'] = 'Persoană juridică';
$_ADDONLANG['cd_type_company_hint'] = 'firmă, PFA, II, ONG sau instituție';
$_ADDONLANG['cd_cui'] = 'CUI (cod fiscal)';
$_ADDONLANG['cd_cui_placeholder'] = 'ex. 12345678';
$_ADDONLANG['cd_company_name'] = 'Denumirea firmei';
$_ADDONLANG['cd_regcom'] = 'Nr. Reg. Com.';
$_ADDONLANG['cd_regcom_placeholder'] = 'ex. J40/1234/2020 sau J2024012345008';
$_ADDONLANG['cd_optional'] = 'opțional';
$_ADDONLANG['cd_vat_payer'] = 'Firma este plătitoare de TVA';
$_ADDONLANG['cd_vat_payer_hint'] = 'Codul de TVA (RO urmat de CUI) apare pe factură.';
$_ADDONLANG['cd_vat_code'] = 'Cod de TVA: %s';
$_ADDONLANG['cd_vat_number'] = 'Cod de TVA';
$_ADDONLANG['cd_cnp'] = 'CNP';
$_ADDONLANG['cd_cnp_help'] = 'Dacă nu îl completați, pe factura electronică (e-Factura) se trec 13 zerouri, cum permite legea.';
$_ADDONLANG['cd_missing'] = 'necompletat';
$_ADDONLANG['cd_yes'] = 'da';
$_ADDONLANG['cd_no'] = 'nu';
$_ADDONLANG['cd_lock_note'] = 'Datele de facturare nu se pot modifica din cont.';
$_ADDONLANG['cd_lock_link'] = 'Cereți modificarea printr-un tichet';
$_ADDONLANG['cd_error_cui_required'] = 'Pentru o firmă din România, completați CUI-ul.';
$_ADDONLANG['cd_error_cui_invalid'] = 'CUI-ul nu este valid: cifra de control nu se potrivește.';
$_ADDONLANG['cd_error_company_name'] = 'Completați denumirea firmei.';
$_ADDONLANG['cd_error_regcom'] = 'Nr. Reg. Com. nu are un format valid (ex. J40/1234/2020 sau J2024012345008).';
$_ADDONLANG['cd_error_cnp'] = 'CNP-ul nu este valid.';
$_ADDONLANG['cd_error_vat_mismatch'] = 'Codul de TVA trebuie să fie RO urmat de CUI-ul firmei.';
$_ADDONLANG['cd_error_person_company'] = 'Pentru o persoană fizică, lăsați goale denumirea firmei și CUI-ul.';
$_ADDONLANG['cd_error_person_vat'] = 'O persoană fizică nu are cod de TVA.';
$_ADDONLANG['cd_error_locked'] = 'Datele de facturare (tip client, CUI, Nr. Reg. Com., CNP, cod de TVA) nu se pot modifica din cont. Pentru modificare, deschideți un tichet.';

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
$_ADDONLANG['setting_client_profile_lock'] = 'Datele de facturare sunt doar pentru citire în profil';
$_ADDONLANG['help_client_profile_lock'] = 'Tipul clientului, denumirea firmei, CUI, Nr. Reg. Com., CNP și codul de TVA le modifică doar adminul. O firmă din România fără CUI nu este acceptată niciodată.';

// Client area and admin: ANAF company lookup
$_ADDONLANG['cd_lookup_button'] = 'Preia datele de la ANAF';
$_ADDONLANG['cd_lookup_address_button'] = 'Actualizează adresa din ANAF';
$_ADDONLANG['cd_lookup_busy'] = 'Se caută la ANAF...';
$_ADDONLANG['cd_lookup_filled'] = 'Datele firmei au fost completate din ANAF. Verificați-le înainte de a continua.';
$_ADDONLANG['cd_lookup_same'] = 'Datele completate sunt aceleași ca la ANAF.';
$_ADDONLANG['cd_lookup_review'] = 'ANAF are alte valori pentru câmpuri pe care le-ați completat deja. Alegeți ce înlocuiți:';
$_ADDONLANG['cd_lookup_apply'] = 'Înlocuiește valorile bifate';
$_ADDONLANG['cd_lookup_keep'] = 'Păstrează datele mele';
$_ADDONLANG['cd_lookup_now'] = 'Acum';
$_ADDONLANG['cd_lookup_anaf'] = 'ANAF';
$_ADDONLANG['cd_lookup_locked'] = 'Diferă de ANAF, dar nu se poate modifica din cont';
$_ADDONLANG['cd_lookup_error'] = 'Căutarea nu a reușit. Completați datele manual.';
$_ADDONLANG['cd_lookup_error_token'] = 'Sesiunea a expirat. Reîncărcați pagina și încercați din nou.';
$_ADDONLANG['cd_lookup_error_rate'] = 'Prea multe căutări într-un timp scurt. Încercați din nou peste câteva minute.';
$_ADDONLANG['cd_lookup_not_found'] = 'ANAF nu are o firmă cu acest CUI.';
$_ADDONLANG['cd_lookup_unavailable'] = 'Serviciul ANAF nu răspunde acum. Completați datele manual sau încercați mai târziu.';
$_ADDONLANG['cd_lookup_need_cui'] = 'Completați întâi un CUI valid.';
$_ADDONLANG['cd_field_address1'] = 'Adresa';
$_ADDONLANG['cd_field_address2'] = 'Adresa (continuare)';
$_ADDONLANG['cd_field_city'] = 'Localitatea';
$_ADDONLANG['cd_field_state'] = 'Județul';
$_ADDONLANG['cd_field_postcode'] = 'Codul poștal';
$_ADDONLANG['cd_status_vat'] = 'Plătitor de TVA';
$_ADDONLANG['cd_status_novat'] = 'Neplătitor de TVA';
$_ADDONLANG['cd_status_vat_collection'] = 'TVA la încasare';
$_ADDONLANG['cd_status_inactive'] = 'Inactivă fiscal';
$_ADDONLANG['cd_status_deregistered'] = 'Radiată';
$_ADDONLANG['cd_status_einvoice'] = 'Înscrisă în Registrul RO e-Factura';
