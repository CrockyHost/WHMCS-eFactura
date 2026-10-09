<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$_ADDONLANG = [];

// Navigare și pagină
$_ADDONLANG['nav_dashboard'] = 'Panou';
$_ADDONLANG['nav_settings'] = 'Setări';
$_ADDONLANG['env_test'] = 'ANAF test';
$_ADDONLANG['env_prod'] = 'ANAF producție';
$_ADDONLANG['badge_processing_on'] = 'Procesare activă';
$_ADDONLANG['badge_processing_off'] = 'Procesare oprită';
$_ADDONLANG['footer_version'] = 'Versiunea';
$_ADDONLANG['value_yes'] = 'Da';
$_ADDONLANG['value_enabled'] = 'Activat';
$_ADDONLANG['value_disabled'] = 'Dezactivat';
$_ADDONLANG['button_save'] = 'Salvează setările';
$_ADDONLANG['select_choose'] = '(alegeți)';

// Panou
$_ADDONLANG['checks_title'] = 'Verificări de configurare';
$_ADDONLANG['status_ok'] = 'OK';
$_ADDONLANG['status_info'] = 'Informație';
$_ADDONLANG['status_warning'] = 'Atenție';
$_ADDONLANG['status_danger'] = 'Trebuie corectat';
$_ADDONLANG['documents_title'] = 'Documente';
$_ADDONLANG['documents_none'] = 'Nu există încă documente e-Factura.';

$_ADDONLANG['check_processing'] = 'Procesare';
$_ADDONLANG['check_processing_on'] = 'Activă: facturile fiscale și stornările sunt programate și trimise în SPV.';
$_ADDONLANG['check_processing_off'] = 'Oprită: addonul nu programează și nu trimite nimic. Activați-o din Setări după ce trec toate verificările.';
$_ADDONLANG['check_environment'] = 'Mediul ANAF';
$_ADDONLANG['check_environment_test'] = 'Test (api.anaf.ro/test). Documentele trimise aici nu au valoare legală.';
$_ADDONLANG['check_company'] = 'Datele firmei (emitent)';
$_ADDONLANG['check_company_missing'] = 'Lipsă sau invalide: %s.';
$_ADDONLANG['check_sequential'] = 'WHMCS: Sequential Paid Invoice Numbering';
$_ADDONLANG['check_sequential_fix'] = 'Trebuie activat (Configuration > System Settings > Tax Configuration > VAT Settings), ca WHMCS să dea numărul fiscal la plată.';
$_ADDONLANG['check_proforma'] = 'WHMCS: Enable Proforma Invoicing';
$_ADDONLANG['check_proforma_fix'] = 'Trebuie activat, ca facturile neplătite să rămână proforme, fără număr fiscal.';
$_ADDONLANG['check_date_on_payment'] = 'WHMCS: Set Invoice Date on Payment';
$_ADDONLANG['check_date_on_payment_fix'] = 'Trebuie activat, ca data facturii fiscale să fie data plății.';
$_ADDONLANG['check_series'] = 'WHMCS: seria proformelor și seria fiscală';
$_ADDONLANG['series_proforma'] = 'Proforme: %s (următoarea %s).';
$_ADDONLANG['series_proforma_none'] = 'Proforme: fără număr, doar ID-ul intern.';
$_ADDONLANG['series_fiscal'] = 'Facturi fiscale: %s.';
$_ADDONLANG['check_series_overlap'] = 'Seria proformelor (%s) și seria fiscală (%s) pot produce aceleași numere sau încep cu același text. Dați-le prefixe diferite, de exemplu CRP- pentru proforme și CRK- pentru facturile fiscale.';
$_ADDONLANG['check_number_format'] = 'WHMCS: Sequential Invoice Number Format';
$_ADDONLANG['check_number_format_fix'] = 'Formatul "%s" trebuie să conțină eticheta {NUMBER}.';
$_ADDONLANG['check_counter'] = 'WHMCS: Next Paid Invoice Number';
$_ADDONLANG['check_counter_ok'] = 'Următorul număr fiscal: %s.';
$_ADDONLANG['check_counter_fix'] = 'Următorul număr (%s) nu este mai mare decât ultimul număr deja emis (%s). Setați "Next Paid Invoice Number" la %s, altfel WHMCS ar emite numere duplicate.';
$_ADDONLANG['check_timezone'] = 'Fusul orar PHP';
$_ADDONLANG['check_timezone_fix'] = 'Este %s. WHMCS datează facturile în acest fus orar, așa că o plată făcută imediat după miezul nopții în România primește ca dată a facturii fiscale ziua anterioară. Setați Europe/Bucharest, de exemplu cu date_default_timezone_set(\'Europe/Bucharest\'); în configuration.php.';
$_ADDONLANG['check_php'] = 'Extensii PHP';
$_ADDONLANG['check_php_missing'] = 'Lipsesc: %s.';

// Stările documentelor
$_ADDONLANG['state_scheduled'] = 'Programată';
$_ADDONLANG['state_held'] = 'Ținută pe loc';
$_ADDONLANG['state_invalid'] = 'Date invalide';
$_ADDONLANG['state_sending'] = 'Se trimite';
$_ADDONLANG['state_unknown'] = 'Necunoscut (reconciliere)';
$_ADDONLANG['state_processing'] = 'În prelucrare la ANAF';
$_ADDONLANG['state_validated'] = 'Validată';
$_ADDONLANG['state_rejected'] = 'Respinsă';
$_ADDONLANG['state_retry'] = 'Eroare tehnică, se reîncearcă';
$_ADDONLANG['state_excluded'] = 'Neraportată';

// Setări
$_ADDONLANG['settings_saved'] = 'Setările au fost salvate.';
$_ADDONLANG['settings_not_saved'] = 'Setările nu au fost salvate. Corectați câmpurile marcate mai jos.';

$_ADDONLANG['section_general'] = 'General';
$_ADDONLANG['setting_enabled'] = 'Procesare activă';
$_ADDONLANG['help_enabled'] = 'Când este activă, facturile fiscale și stornările sunt programate și trimise în SPV. Necesită datele firmei complete și verificările de numerotare WHMCS din panou trecute.';
$_ADDONLANG['setting_environment'] = 'Mediul ANAF';
$_ADDONLANG['help_environment'] = 'Mediul de test (api.anaf.ro/test) acceptă aceleași documente, dar ele nu au valoare legală.';
$_ADDONLANG['setting_ui_language'] = 'Limba acestor pagini';
$_ADDONLANG['language_auto'] = 'Aceeași cu a administratorului';

$_ADDONLANG['section_company'] = 'Emitent';
$_ADDONLANG['section_company_intro'] = 'Datele vânzătorului (BG-4) scrise în fiecare XML e-Factura.';
$_ADDONLANG['setting_company_legal_name'] = 'Denumire legală';
$_ADDONLANG['help_company_legal_name'] = 'Exact ca la înregistrare (BT-27), de exemplu CROCKY S.R.L.';
$_ADDONLANG['setting_company_trade_name'] = 'Denumire comercială';
$_ADDONLANG['help_company_trade_name'] = 'Opțional (BT-28).';
$_ADDONLANG['setting_company_cui'] = 'CUI';
$_ADDONLANG['help_company_cui'] = 'Codul fiscal fără prefixul RO. Se verifică cifra de control.';
$_ADDONLANG['setting_company_vat_payer'] = 'Plătitor de TVA';
$_ADDONLANG['help_company_vat_payer'] = 'Plătitorii de TVA sunt identificați prin RO + CUI (BT-31).';
$_ADDONLANG['setting_company_vat_on_collection'] = 'TVA la încasare';
$_ADDONLANG['help_company_vat_on_collection'] = 'Adaugă mențiunea "TVA la încasare" pe toate facturile.';
$_ADDONLANG['setting_company_reg_com'] = 'Nr. Registrul Comerțului';
$_ADDONLANG['help_company_reg_com'] = 'De exemplu J16/1234/2020. Se scrie împreună cu capitalul social ca informații juridice suplimentare (BT-33).';
$_ADDONLANG['setting_company_share_capital'] = 'Capital social';
$_ADDONLANG['help_company_share_capital'] = 'Opțional, de exemplu 200 RON.';
$_ADDONLANG['setting_company_street'] = 'Strada și numărul';
$_ADDONLANG['setting_company_city'] = 'Localitatea';
$_ADDONLANG['help_company_city'] = 'Pentru București, sectorul: SECTOR1 până la SECTOR6.';
$_ADDONLANG['setting_company_county'] = 'Județul';
$_ADDONLANG['setting_company_postcode'] = 'Cod poștal';
$_ADDONLANG['setting_company_contact_name'] = 'Persoană de contact';
$_ADDONLANG['setting_company_phone'] = 'Telefon';
$_ADDONLANG['setting_company_email'] = 'E-mail facturare';

$_ADDONLANG['section_bank'] = 'Conturi bancare';
$_ADDONLANG['section_bank_intro'] = 'Conturile de plată scrise pe facturi (BG-17), câte unul pe monedă.';
$_ADDONLANG['setting_bank_name'] = 'Banca';
$_ADDONLANG['setting_iban_ron'] = 'IBAN pentru RON';
$_ADDONLANG['setting_iban_eur'] = 'IBAN pentru EUR';
$_ADDONLANG['setting_bank_bic'] = 'BIC / SWIFT';

$_ADDONLANG['section_numbering'] = 'Numerotare';
$_ADDONLANG['section_numbering_intro'] = 'Seria fiscală este Sequential Paid Invoice Number din WHMCS: WHMCS o atribuie la plată, în locul numărului de proformă. Addonul ia numere din același contor pentru facturile emise înainte de plată și pentru stornări. Proformele pot avea seria lor (Custom Invoice Numbering), cu contor separat. Ambele se setează din Configuration > System Settings > Tax Configuration > VAT Settings.';
$_ADDONLANG['setting_numbering_proforma'] = 'Seria proformelor';
$_ADDONLANG['numbering_proforma_none'] = 'Fără număr (doar ID-ul intern)';
$_ADDONLANG['setting_numbering_fiscal'] = 'Seria fiscală';
$_ADDONLANG['setting_numbering_next'] = 'Următorul număr fiscal';

$_ADDONLANG['section_sending'] = 'Trimiterea în SPV';
$_ADDONLANG['setting_send_delay_days'] = 'Așteptare înainte de trimitere';
$_ADDONLANG['help_send_delay_days'] = 'Zile lucrătoare după data documentului până la trimitere, ca datele clientului să mai poată fi corectate sau documentul ținut pe loc. Termenul legal este de 5 zile lucrătoare; aici se pot folosi cel mult 3. Se aplică facturilor și stornărilor.';
$_ADDONLANG['send_delay_one'] = '%d zi lucrătoare';
$_ADDONLANG['send_delay_many'] = '%d zile lucrătoare';

$_ADDONLANG['section_early_issue'] = 'Factură fiscală înainte de plată';
$_ADDONLANG['section_early_issue_intro'] = 'Implicit, o factură devine fiscală la plată. Pentru acești clienți numărul fiscal se atribuie imediat ce factura este emisă, de exemplu instituțiile publice care plătesc doar după ce primesc factura fiscală. Orice factură poate fi emisă anticipat și din pagina ei.';
$_ADDONLANG['setting_early_issue_groups'] = 'Grupuri de clienți';
$_ADDONLANG['setting_early_issue_clients'] = 'ID-uri de clienți';
$_ADDONLANG['help_early_issue_clients'] = 'Separate prin virgulă, de exemplu 12, 45.';
$_ADDONLANG['no_client_groups'] = 'Nu există grupuri de clienți în WHMCS.';

$_ADDONLANG['section_client_fields'] = 'Datele clientului';
$_ADDONLANG['section_client_fields_intro'] = 'De unde se citesc datele cumpărătorului în WHMCS.';
$_ADDONLANG['setting_client_field_cui'] = 'CUI / cod TVA';
$_ADDONLANG['help_client_field_cui'] = 'CUI românesc (cu sau fără RO) sau codul de TVA al unei firme din UE.';
$_ADDONLANG['setting_client_field_regcom'] = 'Nr. Registrul Comerțului';
$_ADDONLANG['setting_client_field_cnp'] = 'CNP';
$_ADDONLANG['help_client_field_cnp'] = 'Opțional. Persoanele fizice fără CNP sunt raportate cu 13 zerouri, cum permite legea.';
$_ADDONLANG['setting_client_field_county'] = 'Județul';
$_ADDONLANG['help_client_field_county'] = 'Valorile scrise liber sunt potrivite cu codurile de județ ISO 3166-2:RO.';
$_ADDONLANG['field_none'] = '(nefolosit)';
$_ADDONLANG['field_native_tax_id'] = 'Câmpul Tax ID din WHMCS';
$_ADDONLANG['field_native_state'] = 'Câmpul State/Region din WHMCS';
$_ADDONLANG['field_custom'] = 'Câmp personalizat: %s';

$_ADDONLANG['section_exclusions'] = 'Facturi neraportate';
$_ADDONLANG['section_exclusions_intro'] = 'Aceste facturi nu se trimit în SPV. Apar totuși în listă, cu motivul.';
$_ADDONLANG['setting_exclude_eu_reverse_charge'] = 'Firme din UE (taxare inversă)';
$_ADDONLANG['help_exclude_eu_reverse_charge'] = 'Firme din alte țări UE cu cod de TVA valid, facturate fără TVA.';
$_ADDONLANG['setting_exclude_non_eu'] = 'Clienți din afara UE';
$_ADDONLANG['setting_exclude_zero_total'] = 'Facturi cu total 0';
$_ADDONLANG['setting_exclude_add_funds'] = 'Facturi Add Funds';
$_ADDONLANG['help_exclude_add_funds'] = 'Alimentări de credit în cont. Serviciile plătite din credit se facturează și se raportează oricum. Verificați cu contabilul tratamentul avansurilor.';
$_ADDONLANG['setting_exclude_mass_pay'] = 'Facturi Mass Pay';
$_ADDONLANG['exclude_mass_pay_always'] = 'Excluse întotdeauna: doar grupează alte facturi, care se raportează fiecare în parte.';

// Validare
$_ADDONLANG['error_csrf'] = 'Formularul a expirat. Reîncărcați pagina și încercați din nou.';
$_ADDONLANG['error_too_long'] = 'Cel mult %d caractere.';
$_ADDONLANG['error_cui'] = 'CUI invalid: cifra de control nu se potrivește.';
$_ADDONLANG['error_county'] = 'Alegeți un județ din listă.';
$_ADDONLANG['error_sector'] = 'Pentru București localitatea trebuie să fie sectorul: SECTOR1 până la SECTOR6.';
$_ADDONLANG['error_email'] = 'Adresă de e-mail invalidă.';
$_ADDONLANG['error_iban'] = 'IBAN invalid.';
$_ADDONLANG['error_bic'] = 'Cod BIC / SWIFT invalid.';
$_ADDONLANG['error_send_delay'] = 'Alegeți între 0 și %d zile lucrătoare.';
$_ADDONLANG['error_unknown_groups'] = 'Grupuri de clienți necunoscute: %s.';
$_ADDONLANG['error_unknown_clients'] = 'Nu există clienți cu aceste ID-uri: %s.';
$_ADDONLANG['error_invalid_choice'] = 'Opțiune invalidă.';
$_ADDONLANG['error_required_to_enable'] = 'Obligatoriu înainte de activarea procesării.';
$_ADDONLANG['error_whmcs_not_ready'] = 'Numerotarea facturilor din WHMCS nu trece încă verificările din panou.';
