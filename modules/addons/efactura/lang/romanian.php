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
$_ADDONLANG['check_timezone_fix'] = 'Este %s. WHMCS datează facturile în acest fus orar, așa că o plată făcută imediat după miezul nopții în România primește ca dată a facturii fiscale ziua anterioară. Setați Europe/Bucharest:';
$_ADDONLANG['check_timezone_step_config'] = 'Recomandat: adăugați această linie la sfârșitul fișierului configuration.php, din folderul principal WHMCS. Se aplică paginilor web și cron-ului, care rulează PHP din linia de comandă.';
$_ADDONLANG['check_timezone_step_ini'] = 'În plus, pentru consecvență: schimbați date.timezone în php.ini sau .user.ini (în cPanel: MultiPHP INI Editor). Singură, această setare acoperă doar paginile web, nu și cron-ul.';
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

// Conectare ANAF
$_ADDONLANG['nav_anaf'] = 'Conectare ANAF';
$_ADDONLANG['check_anaf'] = 'Conexiunea ANAF';
$_ADDONLANG['check_anaf_not_configured'] = 'Neconfigurată: introduceți aplicația OAuth pe pagina Conectare ANAF.';
$_ADDONLANG['check_anaf_not_connected'] = 'Neautorizată încă: folosiți "Conectează la ANAF" cu certificatul calificat.';
$_ADDONLANG['check_anaf_reauthorize'] = 'Trebuie autorizată din nou cu certificatul calificat. %s';
$_ADDONLANG['check_anaf_expiring'] = 'Conectată. Reautorizați cu certificatul până la %s (mai sunt %d zile).';
$_ADDONLANG['check_anaf_ok'] = 'Conectată. Reautorizarea anuală este pe %s (mai sunt %d zile).';

$_ADDONLANG['anaf_status_title'] = 'Starea conexiunii';
$_ADDONLANG['anaf_state_not_configured'] = 'Neconfigurată';
$_ADDONLANG['anaf_state_not_connected'] = 'Neconectată';
$_ADDONLANG['anaf_state_connected'] = 'Conectată';
$_ADDONLANG['anaf_state_expiring'] = 'Reautorizare în curând';
$_ADDONLANG['anaf_state_reauthorize'] = 'Reautorizare necesară';
$_ADDONLANG['anaf_field_authorized'] = 'Autorizată';
$_ADDONLANG['anaf_authorized_value'] = '%s de %s';
$_ADDONLANG['anaf_field_certificate'] = 'Seria certificatului';
$_ADDONLANG['anaf_field_roles'] = 'Drepturile token-ului';
$_ADDONLANG['anaf_field_access'] = 'Token de acces valabil până la';
$_ADDONLANG['anaf_field_reauth'] = 'Reautorizare cu certificatul până la';
$_ADDONLANG['anaf_days_left'] = '%s (mai sunt %d zile)';
$_ADDONLANG['anaf_field_refreshed'] = 'Ultima reîmprospătare a token-ului';
$_ADDONLANG['anaf_never'] = 'încă nu';
$_ADDONLANG['anaf_field_environment'] = 'Mediul API';
$_ADDONLANG['anaf_field_error'] = 'Ultima eroare';
$_ADDONLANG['anaf_connect_help'] = 'Autorizarea se face într-un browser care are certificatul calificat (token USB) al unei persoane cu drepturi SPV pentru firmă: reprezentant legal, reprezentant desemnat sau împuternicit. Dacă persoana nu folosește acest WHMCS, generați un link și trimiteți-i-l: este valabil %d de minute și funcționează o singură dată.';
$_ADDONLANG['anaf_button_connect'] = 'Conectează la ANAF';
$_ADDONLANG['anaf_button_reconnect'] = 'Reautorizează';
$_ADDONLANG['anaf_button_link'] = 'Link pentru altă persoană';
$_ADDONLANG['anaf_button_check'] = 'Testează conexiunea';
$_ADDONLANG['anaf_button_refresh'] = 'Reîmprospătează token-ul';
$_ADDONLANG['anaf_button_disconnect'] = 'Deconectează';
$_ADDONLANG['anaf_disconnect_confirm'] = 'Ștergeți token-urile ANAF din WHMCS? Nu se mai poate trimite nimic în SPV până la o nouă autorizare.';
$_ADDONLANG['anaf_connected'] = 'Conectat la ANAF. Folosiți "Testează conexiunea" ca să verificați drepturile SPV pentru CUI-ul firmei.';
$_ADDONLANG['anaf_refreshed'] = 'Token-urile au fost reîmprospătate.';
$_ADDONLANG['anaf_disconnected'] = 'Token-urile ANAF au fost șterse din WHMCS.';
$_ADDONLANG['anaf_action_failed'] = 'Operațiunea a eșuat: %s';

$_ADDONLANG['anaf_app_title'] = 'Aplicația OAuth ANAF';
$_ADDONLANG['anaf_app_intro'] = 'Fiecare instalare WHMCS folosește propria aplicație OAuth, înregistrată în contul de dezvoltator ANAF al firmei. Client ID-ul și secretul rămân în acest WHMCS; secretul și token-urile se păstrează criptat.';
$_ADDONLANG['anaf_app_step1'] = 'Pe anaf.ro deschideți Servicii Online > Înregistrare utilizatori > Dezvoltatori aplicații > Înregistrare pentru API-uri și autentificați-vă cu contul de dezvoltator (utilizator și parolă, fără certificat).';
$_ADDONLANG['anaf_app_step2'] = 'În Editare profil Oauth > Gestionare aplicații adăugați o aplicație: un nume fără spații (de exemplu WHMCSeFactura), serviciul E-Factura și callback URL-ul de mai jos.';
$_ADDONLANG['anaf_app_step3'] = 'Aplicația nu mai poate fi modificată ulterior. Adăugați de la început, cu butonul +, callback URL-ul fiecărui WHMCS care o va folosi (de exemplu producția și o copie de test).';
$_ADDONLANG['anaf_app_step4'] = 'Apăsați Generare Client ID și copiați Client ID-ul și Client Secret-ul în formularul de mai jos.';
$_ADDONLANG['anaf_callback_url'] = 'Callback URL de înregistrat la ANAF';
$_ADDONLANG['anaf_callback_not_https'] = 'ANAF acceptă doar callback URL-uri https://. Setați System URL din WHMCS la o adresă https.';
$_ADDONLANG['anaf_client_id'] = 'Client ID';
$_ADDONLANG['anaf_client_secret'] = 'Client Secret';
$_ADDONLANG['anaf_secret_saved'] = 'Salvat. Lăsați gol ca să îl păstrați.';
$_ADDONLANG['anaf_button_save'] = 'Salvează aplicația';
$_ADDONLANG['anaf_credentials_saved'] = 'Aplicația OAuth a fost salvată.';
$_ADDONLANG['anaf_error_client_id'] = 'Introduceți client ID-ul, fără spații.';
$_ADDONLANG['anaf_error_client_secret'] = 'Introduceți client secret-ul.';

$_ADDONLANG['anaf_authorize_title'] = 'Autorizare la ANAF';
$_ADDONLANG['anaf_authorize_redirect'] = 'Se deschide ANAF. Alegeți certificatul calificat când vi-l cere browserul.';
$_ADDONLANG['anaf_authorize_continue'] = 'Continuă la ANAF';
$_ADDONLANG['anaf_authorize_link_intro'] = 'Trimiteți acest link persoanei care are certificatul calificat. Este valabil %d de minute și funcționează o singură dată; după autorizare, conexiunea apare pe pagina Conectare ANAF.';
$_ADDONLANG['anaf_authorize_back'] = 'Înapoi la Conectare ANAF';

$_ADDONLANG['anaf_check_no_cui'] = 'Completați întâi CUI-ul firmei în Setări.';
$_ADDONLANG['anaf_check_token'] = 'Nu există un token utilizabil: %s';
$_ADDONLANG['anaf_check_unreachable'] = 'ANAF nu a putut fi contactat: %s';
$_ADDONLANG['anaf_check_rejected'] = 'ANAF a respins token-ul (HTTP %d). Reautorizați.';
$_ADDONLANG['anaf_check_unexpected'] = 'Răspuns neașteptat de la ANAF (HTTP %d): %s';
$_ADDONLANG['anaf_check_ok'] = 'Conexiunea funcționează: certificatul are drepturi SPV pentru CUI %s (%s, %d mesaje în ultima zi).';
$_ADDONLANG['anaf_check_no_rights'] = 'Token-ul este valid, dar certificatul nu are drepturi SPV pentru CUI %s: %s';
$_ADDONLANG['anaf_check_error'] = 'ANAF a răspuns: %s';

$_ADDONLANG['alert_reauth_soon_subject'] = 'Reautorizare ANAF necesară în %d zile';
$_ADDONLANG['alert_reauth_soon_body'] = "Conexiunea WHMCS la ANAF e-Factura trebuie autorizată din nou cu certificatul calificat până la %s (mai sunt %d zile). După această dată nu se mai poate trimite nimic în SPV până la reautorizare.\n\n%s";
$_ADDONLANG['alert_reauth_now_subject'] = 'Reautorizare ANAF necesară acum';
$_ADDONLANG['alert_reauth_now_body'] = "Conexiunea WHMCS la ANAF e-Factura nu mai funcționează și trebuie autorizată din nou cu certificatul calificat. Până atunci nu se trimite nimic în SPV.\nUltima eroare: %s\n\n%s";

$_ADDONLANG['callback_title'] = 'e-Factura: autorizare ANAF';
$_ADDONLANG['callback_success'] = 'Conexiunea la ANAF a fost salvată. Puteți închide această fereastră.';
$_ADDONLANG['callback_invalid_state'] = 'Acest link de autorizare este invalid, expirat sau deja folosit. Generați unul nou din WHMCS (Addons > WHMCS-eFactura > Conectare ANAF).';
$_ADDONLANG['callback_denied'] = 'ANAF nu a autorizat accesul (%s). Verificați că certificatul calificat este conectat și înrolat în SPV pentru firmă, apoi încercați din nou.';
$_ADDONLANG['callback_failed'] = 'Autorizarea nu a putut fi finalizată: %s';

// Numerotare fiscală
$_ADDONLANG['review_lock_timeout'] = 'plătită cât timp numerotarea fiscală era ocupată';
$_ADDONLANG['review_counter_gap'] = 'posibil gol în seria fiscală';
$_ADDONLANG['review_duplicate_number'] = 'număr deja folosit';
$_ADDONLANG['excluded_eu_reverse_charge'] = 'firmă din UE, taxare inversă';
$_ADDONLANG['excluded_non_eu'] = 'client din afara UE';
$_ADDONLANG['alert_review_subject'] = 'Factura fiscală %s trebuie verificată manual';
$_ADDONLANG['alert_review_body'] = "Factura #%d a primit numărul fiscal %s, dar trebuie verificată manual: %s. Documentul e-Factura este ținut pe loc până verificați că numărul este unic și că seria nu are goluri, apoi îl eliberați.\n\n%s";
$_ADDONLANG['alert_gap_subject'] = 'Posibil gol în seria fiscală la %s';
$_ADDONLANG['alert_gap_body'] = "WHMCS a dat numărul fiscal %s facturii #%d, care nu trebuie să-l păstreze, iar numărul nu a putut fi returnat contorului pentru că între timp s-au mai luat numere. Verificați seria fiscală și contorul Next Paid Invoice Number.\n\n%s";
$_ADDONLANG['alert_duplicate_subject'] = 'Număr fiscal duplicat %s corectat';
$_ADDONLANG['alert_duplicate_body'] = "WHMCS a dat facturii #%d numărul fiscal %s, care era deja folosit. Addonul l-a înlocuit cu %s.\n\n%s";
$_ADDONLANG['alert_hook_failed_subject'] = 'Eroare la procesarea facturii #%d';
$_ADDONLANG['alert_hook_failed_body'] = "Pasul e-Factura \"%s\" a eșuat pentru factura #%d: %s\nPlata în sine nu a fost afectată. Verificați factura și numărul ei fiscal.";

// Modalități de plată (BT-81)
$_ADDONLANG['section_payment_means'] = 'Modalități de plată';
$_ADDONLANG['section_payment_means_intro'] = 'Codul modalității de plată (UNCL 4461) scris pe facturile fiecărui gateway de plată din WHMCS. Codurile 30, 42 și 58 adaugă IBAN-ul emitentului pentru moneda facturii; 30 și 58 îl cer obligatoriu.';
$_ADDONLANG['no_gateways'] = 'Nu există gateway-uri de plată active în WHMCS.';
$_ADDONLANG['pm_1'] = 'Instrument nedefinit';
$_ADDONLANG['pm_10'] = 'Numerar';
$_ADDONLANG['pm_30'] = 'Transfer credit (ordin de plată)';
$_ADDONLANG['pm_42'] = 'Plată în cont bancar';
$_ADDONLANG['pm_48'] = 'Card bancar';
$_ADDONLANG['pm_54'] = 'Card de credit';
$_ADDONLANG['pm_55'] = 'Card de debit';
$_ADDONLANG['pm_58'] = 'Transfer SEPA';
$_ADDONLANG['pm_68'] = 'Serviciu de plată online';
$_ADDONLANG['pm_97'] = 'Compensare între parteneri';
$_ADDONLANG['pm_none'] = '(nu se scrie pe factură)';

// XML e-Factura: probleme afișate adminului
$_ADDONLANG['ubl_party_seller'] = 'Emitent (Setările addonului)';
$_ADDONLANG['ubl_party_buyer'] = 'Client';
$_ADDONLANG['ubl_line'] = 'Linia %s';
$_ADDONLANG['ubl_field_number'] = 'Numărul facturii';
$_ADDONLANG['ubl_field_name'] = 'denumirea';
$_ADDONLANG['ubl_field_trade_name'] = 'denumirea comercială';
$_ADDONLANG['ubl_field_street'] = 'adresa (strada)';
$_ADDONLANG['ubl_field_additional_street'] = 'a doua linie a adresei';
$_ADDONLANG['ubl_field_city'] = 'localitatea';
$_ADDONLANG['ubl_field_postcode'] = 'codul poștal';
$_ADDONLANG['ubl_field_contact'] = 'persoana de contact';
$_ADDONLANG['ubl_field_phone'] = 'telefonul';
$_ADDONLANG['ubl_field_email'] = 'e-mailul';
$_ADDONLANG['ubl_field_legal_form'] = 'informațiile juridice suplimentare';
$_ADDONLANG['ubl_field_item_name'] = 'numele articolului';
$_ADDONLANG['ubl_field_item_description'] = 'descrierea articolului';
$_ADDONLANG['ubl_field_line_note'] = 'nota liniei';
$_ADDONLANG['ubl_field_note'] = 'Nota facturii';
$_ADDONLANG['ubl_field_exemption'] = 'Motivul scutirii de TVA';
$_ADDONLANG['ubl_required'] = '%s: lipsește %s.';
$_ADDONLANG['ubl_too_long'] = '%s are %d caractere; sunt permise cel mult %d.';
$_ADDONLANG['ubl_number_digit'] = 'Numărul facturii "%s" trebuie să conțină cel puțin o cifră.';
$_ADDONLANG['ubl_notes_count'] = 'Sunt permise cel mult 20 de note pe factură.';
$_ADDONLANG['ubl_county'] = '%s: "%s" nu este un cod de județ (ISO 3166-2:RO).';
$_ADDONLANG['ubl_sector'] = '%s: în București localitatea trebuie să fie sectorul (SECTOR1 până la SECTOR6), nu "%s".';
$_ADDONLANG['ubl_vat_prefix'] = '%s: codul de TVA "%s" trebuie să înceapă cu codul țării.';
$_ADDONLANG['ubl_cui'] = '%s: "%s" nu este un CUI valid (cifra de control nu se potrivește).';
$_ADDONLANG['ubl_cnp'] = '%s: CNP-ul %s nu este valid (cifra de control nu se potrivește).';
$_ADDONLANG['ubl_seller_id'] = 'Emitent: lipsește CUI-ul (Setările addonului).';
$_ADDONLANG['ubl_buyer_id'] = 'Client: lipsește identificatorul. O firmă are nevoie de CUI sau cod de TVA; o persoană fizică se identifică prin CNP sau 13 zerouri.';
$_ADDONLANG['ubl_no_lines'] = 'Documentul nu are linii.';
$_ADDONLANG['ubl_line_sign'] = 'Linia %s: cantitatea trebuie să fie 1 sau -1, cu semnul valorii.';
$_ADDONLANG['ubl_tax_base'] = 'TVA %s %s%%: baza de impozitare %s diferă de suma liniilor ei, %s.';
$_ADDONLANG['ubl_tax_rate'] = 'TVA-ul %s pe baza %s la %s%% diferă de %s cu 1,00 sau mai mult, ceea ce ANAF respinge [BR-S-09]. Sumele vin din WHMCS și nu sunt modificate: corectați factura în WHMCS (storno și factură nouă).';
$_ADDONLANG['ubl_exempt_forbidden'] = 'O defalcare cu cota standard nu poate avea motiv de scutire.';
$_ADDONLANG['ubl_exempt_tax'] = 'Categoria de TVA %s trebuie să aibă TVA 0.';
$_ADDONLANG['ubl_exempt_reason'] = 'Categoria de TVA %s are nevoie de motivul scutirii.';
$_ADDONLANG['ubl_missing_category'] = 'TVA %s %s%% are linii, dar nu are defalcare.';
$_ADDONLANG['ubl_tax_total'] = 'Defalcarea TVA însumează %s, dar totalul TVA este %s.';
$_ADDONLANG['ubl_ron_vat'] = 'Lipsește TVA-ul în lei pentru un document în %s.';
$_ADDONLANG['ubl_due_date'] = 'Factura nu este plătită integral, deci are nevoie de dată scadentă.';
$_ADDONLANG['ubl_iban'] = 'Codul de plată %s are nevoie de IBAN-ul contului în %s din Setările addonului.';
$_ADDONLANG['map_client_missing'] = 'Clientul #%d nu există.';
$_ADDONLANG['map_invoice_missing'] = 'Factura WHMCS #%d nu există.';
$_ADDONLANG['map_original_missing'] = 'Factura corectată de această stornare nu are număr fiscal.';
$_ADDONLANG['map_note_missing'] = 'Nota de credit WHMCS #%d nu există.';
$_ADDONLANG['map_currency_missing'] = 'Clientul nu are monedă.';
$_ADDONLANG['map_company_without_cui'] = 'Clientul "%s" este firmă, dar nu are CUI (câmpul: %s). Adăugați CUI-ul în profilul clientului.';
$_ADDONLANG['map_cui_invalid'] = 'CUI-ul clientului "%s" nu este valid (cifra de control nu se potrivește). Corectați-l în profilul clientului.';
$_ADDONLANG['map_cnp_invalid'] = 'CNP-ul clientului nu este valid (cifra de control nu se potrivește). Corectați-l sau lăsați-l gol.';
$_ADDONLANG['map_county_missing'] = 'Clientul nu are județ (câmpul: %s).';
$_ADDONLANG['map_county_unknown'] = 'Județul clientului "%s" nu este un județ din România (câmpul: %s). Corectați-l în profilul clientului.';
$_ADDONLANG['map_sector_missing'] = 'Clientul este în București, dar sectorul (Sector 1 până la Sector 6) nu apare în localitate, adresă sau județ. Adăugați-l în profilul clientului.';
$_ADDONLANG['map_address_missing'] = 'Lipsește adresa clientului (strada).';
$_ADDONLANG['map_city_missing'] = 'Lipsește localitatea clientului.';
$_ADDONLANG['map_eu_vat_missing'] = 'Clientul firmă din UE nu are cod de TVA.';
$_ADDONLANG['map_foreign_id_missing'] = 'Clientul firmă din afara UE nu are identificator fiscal.';
$_ADDONLANG['map_subtotal'] = 'Liniile facturii însumează %s, dar subtotalul din WHMCS este %s.';
$_ADDONLANG['map_tax2'] = 'Factura are un al doilea nivel de taxă (%s), pe care e-Factura nu îl acceptă.';
$_ADDONLANG['map_total'] = 'Subtotalul %s plus TVA-ul %s nu dă totalul din WHMCS, %s.';
$_ADDONLANG['map_untaxed_line'] = 'Linia "%s" nu are TVA, iar clientul este din România: categoria de TVA nu poate fi stabilită. Verificați setarea de taxă a produsului.';
$_ADDONLANG['map_exchange_rate'] = 'Nu există curs BNR pentru %s: %s';

// Alertele cozii
$_ADDONLANG['alert_invalid_subject'] = 'Documentul e-Factura %s nu poate fi generat';
$_ADDONLANG['alert_invalid_body'] = "XML-ul documentului %s (factura #%d) nu poate fi generat din datele actuale:\n%s\n\nCorectați datele; documentul se reîncearcă automat la fiecare 15 minute.\n%s";
$_ADDONLANG['alert_rejected_subject'] = 'Documentul e-Factura %s a fost respins';
$_ADDONLANG['alert_rejected_body'] = "ANAF nu a acceptat documentul %s (factura #%d):\n%s\n\nCorectați datele și retrimiteți-l (același număr).\n%s";
$_ADDONLANG['alert_archive_lost_subject'] = 'Răspunsul ANAF pentru %s nu mai poate fi descărcat';
$_ADDONLANG['alert_archive_lost_body'] = "Răspunsul semnat al ANAF pentru documentul %s (factura #%d) nu a putut fi arhivat: %s\n%s";
$_ADDONLANG['alert_auth_subject'] = 'ANAF refuză apelurile e-Factura';
$_ADDONLANG['alert_auth_body'] = "ANAF a răspuns: %s\nTrimiterea în SPV este oprită o oră, apoi se reîncearcă. Verificați conexiunea ANAF și drepturile SPV ale certificatului: %s";

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
$_ADDONLANG['error_system_not_ready'] = 'Verificările din panou (numerotarea facturilor WHMCS, fusul orar PHP, extensiile PHP) nu trec încă.';
