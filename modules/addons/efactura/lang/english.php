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

// Navigation and layout
$_ADDONLANG['nav_dashboard'] = 'Dashboard';
$_ADDONLANG['nav_settings'] = 'Settings';
$_ADDONLANG['env_test'] = 'ANAF test';
$_ADDONLANG['env_prod'] = 'ANAF production';
$_ADDONLANG['badge_processing_on'] = 'Processing on';
$_ADDONLANG['badge_processing_off'] = 'Processing off';
$_ADDONLANG['footer_version'] = 'Version';
$_ADDONLANG['value_yes'] = 'Yes';
$_ADDONLANG['value_enabled'] = 'Enabled';
$_ADDONLANG['value_disabled'] = 'Disabled';
$_ADDONLANG['button_save'] = 'Save settings';
$_ADDONLANG['select_choose'] = '(choose)';

// Dashboard
$_ADDONLANG['checks_title'] = 'Configuration checks';
$_ADDONLANG['status_ok'] = 'OK';
$_ADDONLANG['status_info'] = 'Information';
$_ADDONLANG['status_warning'] = 'Warning';
$_ADDONLANG['status_danger'] = 'Must be fixed';
$_ADDONLANG['documents_title'] = 'Documents';
$_ADDONLANG['documents_none'] = 'No e-Factura documents yet.';

$_ADDONLANG['check_processing'] = 'Processing';
$_ADDONLANG['check_processing_on'] = 'Enabled: fiscal invoices and stornos are scheduled and sent to SPV.';
$_ADDONLANG['check_processing_off'] = 'Disabled: the addon does not schedule or send anything. Enable it in Settings once all checks pass.';
$_ADDONLANG['check_environment'] = 'ANAF environment';
$_ADDONLANG['check_environment_test'] = 'Test (api.anaf.ro/test). Documents sent there have no legal value.';
$_ADDONLANG['check_company'] = 'Seller details';
$_ADDONLANG['check_company_missing'] = 'Missing or invalid: %s.';
$_ADDONLANG['check_sequential'] = 'WHMCS: Sequential Paid Invoice Numbering';
$_ADDONLANG['check_sequential_fix'] = 'Must be enabled (Configuration > System Settings > Tax Configuration > VAT Settings), so that WHMCS assigns the fiscal number at payment.';
$_ADDONLANG['check_proforma'] = 'WHMCS: Enable Proforma Invoicing';
$_ADDONLANG['check_proforma_fix'] = 'Must be enabled, so that unpaid invoices stay proformas without a fiscal number.';
$_ADDONLANG['check_date_on_payment'] = 'WHMCS: Set Invoice Date on Payment';
$_ADDONLANG['check_date_on_payment_fix'] = 'Must be enabled, so that the date of the fiscal invoice is the payment date.';
$_ADDONLANG['check_series'] = 'WHMCS: proforma and fiscal series';
$_ADDONLANG['series_proforma'] = 'Proformas: %s (next %s).';
$_ADDONLANG['series_proforma_none'] = 'Proformas: no number, internal ID only.';
$_ADDONLANG['series_fiscal'] = 'Fiscal invoices: %s.';
$_ADDONLANG['check_series_overlap'] = 'The proforma series (%s) and the fiscal series (%s) can produce the same numbers or start with the same text. Give them different prefixes, for example CRP- for proformas and CRK- for fiscal invoices.';
$_ADDONLANG['check_number_format'] = 'WHMCS: Sequential Invoice Number Format';
$_ADDONLANG['check_number_format_fix'] = 'The format "%s" must contain the {NUMBER} tag.';
$_ADDONLANG['check_counter'] = 'WHMCS: Next Paid Invoice Number';
$_ADDONLANG['check_counter_ok'] = 'Next fiscal number: %s.';
$_ADDONLANG['check_counter_fix'] = 'The next number (%s) is not higher than the last number already issued (%s). Set "Next Paid Invoice Number" to %s, otherwise WHMCS would issue duplicate numbers.';
$_ADDONLANG['check_timezone'] = 'PHP time zone';
$_ADDONLANG['check_timezone_fix'] = 'It is %s. WHMCS dates invoices in this time zone, so a payment made shortly after midnight in Romania gets the previous day as fiscal invoice date. Set Europe/Bucharest, for example with date_default_timezone_set(\'Europe/Bucharest\'); in configuration.php.';
$_ADDONLANG['check_php'] = 'PHP extensions';
$_ADDONLANG['check_php_missing'] = 'Missing: %s.';

// Document states
$_ADDONLANG['state_scheduled'] = 'Scheduled';
$_ADDONLANG['state_held'] = 'On hold';
$_ADDONLANG['state_invalid'] = 'Invalid data';
$_ADDONLANG['state_sending'] = 'Sending';
$_ADDONLANG['state_unknown'] = 'Unknown (reconciling)';
$_ADDONLANG['state_processing'] = 'Processing at ANAF';
$_ADDONLANG['state_validated'] = 'Validated';
$_ADDONLANG['state_rejected'] = 'Rejected';
$_ADDONLANG['state_retry'] = 'Technical error, will retry';
$_ADDONLANG['state_excluded'] = 'Not reported';

// Settings page
$_ADDONLANG['settings_saved'] = 'Settings saved.';
$_ADDONLANG['settings_not_saved'] = 'The settings were not saved. Fix the fields marked below.';

$_ADDONLANG['section_general'] = 'General';
$_ADDONLANG['setting_enabled'] = 'Processing enabled';
$_ADDONLANG['help_enabled'] = 'When enabled, fiscal invoices and stornos are scheduled and sent to SPV. Requires complete seller details and the WHMCS numbering checks on the dashboard to pass.';
$_ADDONLANG['setting_environment'] = 'ANAF environment';
$_ADDONLANG['help_environment'] = 'The test environment (api.anaf.ro/test) accepts the same documents, but they have no legal value.';
$_ADDONLANG['setting_ui_language'] = 'Language of these pages';
$_ADDONLANG['language_auto'] = 'Same as the administrator';

$_ADDONLANG['section_company'] = 'Seller';
$_ADDONLANG['section_company_intro'] = 'Seller details (BG-4) written in every e-Factura XML.';
$_ADDONLANG['setting_company_legal_name'] = 'Legal name';
$_ADDONLANG['help_company_legal_name'] = 'Exactly as registered (BT-27), for example CROCKY S.R.L.';
$_ADDONLANG['setting_company_trade_name'] = 'Trade name';
$_ADDONLANG['help_company_trade_name'] = 'Optional (BT-28).';
$_ADDONLANG['setting_company_cui'] = 'CUI';
$_ADDONLANG['help_company_cui'] = 'Fiscal code without the RO prefix. The check digit is verified.';
$_ADDONLANG['setting_company_vat_payer'] = 'VAT payer';
$_ADDONLANG['help_company_vat_payer'] = 'VAT payers are identified as RO + CUI (BT-31).';
$_ADDONLANG['setting_company_vat_on_collection'] = 'VAT on collection';
$_ADDONLANG['help_company_vat_on_collection'] = 'Adds the mention "TVA la încasare" to every invoice.';
$_ADDONLANG['setting_company_reg_com'] = 'Trade register number';
$_ADDONLANG['help_company_reg_com'] = 'For example J16/1234/2020. Written together with the share capital as additional legal information (BT-33).';
$_ADDONLANG['setting_company_share_capital'] = 'Share capital';
$_ADDONLANG['help_company_share_capital'] = 'Optional, for example 200 RON.';
$_ADDONLANG['setting_company_street'] = 'Street and number';
$_ADDONLANG['setting_company_city'] = 'City';
$_ADDONLANG['help_company_city'] = 'For Bucharest, the sector: SECTOR1 to SECTOR6.';
$_ADDONLANG['setting_company_county'] = 'County';
$_ADDONLANG['setting_company_postcode'] = 'Postal code';
$_ADDONLANG['setting_company_contact_name'] = 'Contact person';
$_ADDONLANG['setting_company_phone'] = 'Phone';
$_ADDONLANG['setting_company_email'] = 'Billing e-mail';

$_ADDONLANG['section_bank'] = 'Bank accounts';
$_ADDONLANG['section_bank_intro'] = 'Payment accounts written on the invoices (BG-17), one per currency.';
$_ADDONLANG['setting_bank_name'] = 'Bank';
$_ADDONLANG['setting_iban_ron'] = 'IBAN for RON';
$_ADDONLANG['setting_iban_eur'] = 'IBAN for EUR';
$_ADDONLANG['setting_bank_bic'] = 'BIC / SWIFT';

$_ADDONLANG['section_numbering'] = 'Numbering';
$_ADDONLANG['section_numbering_intro'] = 'The fiscal series is the WHMCS Sequential Paid Invoice Number: WHMCS assigns it at payment, replacing the proforma number. The addon takes numbers from the same counter for invoices issued before payment and for stornos. Proformas may have their own series (Custom Invoice Numbering) with a separate counter. Both are set in Configuration > System Settings > Tax Configuration > VAT Settings.';
$_ADDONLANG['setting_numbering_proforma'] = 'Proforma series';
$_ADDONLANG['numbering_proforma_none'] = 'No number (internal ID only)';
$_ADDONLANG['setting_numbering_fiscal'] = 'Fiscal series';
$_ADDONLANG['setting_numbering_next'] = 'Next fiscal number';

$_ADDONLANG['section_sending'] = 'Sending to SPV';
$_ADDONLANG['setting_send_delay_days'] = 'Wait before sending';
$_ADDONLANG['help_send_delay_days'] = 'Working days after the document date before it is sent, so that client data can still be fixed or the document held. The legal deadline is 5 working days; at most 3 can be used here. Applies to invoices and stornos.';
$_ADDONLANG['send_delay_one'] = '%d working day';
$_ADDONLANG['send_delay_many'] = '%d working days';

$_ADDONLANG['section_early_issue'] = 'Fiscal invoice before payment';
$_ADDONLANG['section_early_issue_intro'] = 'By default an invoice becomes fiscal when it is paid. For these clients the fiscal number is assigned as soon as the invoice is issued, for example public institutions that pay only after receiving the fiscal invoice. Any invoice can also be issued early from its page.';
$_ADDONLANG['setting_early_issue_groups'] = 'Client groups';
$_ADDONLANG['setting_early_issue_clients'] = 'Client IDs';
$_ADDONLANG['help_early_issue_clients'] = 'Separated by commas, for example 12, 45.';
$_ADDONLANG['no_client_groups'] = 'There are no client groups in WHMCS.';

$_ADDONLANG['section_client_fields'] = 'Client data';
$_ADDONLANG['section_client_fields_intro'] = 'Where the buyer details are read from in WHMCS.';
$_ADDONLANG['setting_client_field_cui'] = 'CUI / VAT number';
$_ADDONLANG['help_client_field_cui'] = 'Romanian CUI (with or without RO) or the VAT number of an EU company.';
$_ADDONLANG['setting_client_field_regcom'] = 'Trade register number';
$_ADDONLANG['setting_client_field_cnp'] = 'CNP';
$_ADDONLANG['help_client_field_cnp'] = 'Optional. Individuals without a CNP are reported with 13 zeros, as the law allows.';
$_ADDONLANG['setting_client_field_county'] = 'County';
$_ADDONLANG['help_client_field_county'] = 'Free text values are matched to the ISO 3166-2:RO county codes.';
$_ADDONLANG['field_none'] = '(not used)';
$_ADDONLANG['field_native_tax_id'] = 'WHMCS Tax ID field';
$_ADDONLANG['field_native_state'] = 'WHMCS State/Region field';
$_ADDONLANG['field_custom'] = 'Custom field: %s';

$_ADDONLANG['section_exclusions'] = 'Invoices not reported';
$_ADDONLANG['section_exclusions_intro'] = 'These invoices are not sent to SPV. They are still listed, with the reason.';
$_ADDONLANG['setting_exclude_eu_reverse_charge'] = 'EU companies (reverse charge)';
$_ADDONLANG['help_exclude_eu_reverse_charge'] = 'Companies from other EU countries with a valid VAT number, invoiced without VAT.';
$_ADDONLANG['setting_exclude_non_eu'] = 'Clients outside the EU';
$_ADDONLANG['setting_exclude_zero_total'] = 'Invoices with a total of 0';
$_ADDONLANG['setting_exclude_add_funds'] = 'Add Funds invoices';
$_ADDONLANG['help_exclude_add_funds'] = 'Account credit top-ups. The services paid from the credit are invoiced and reported anyway. Check the treatment of prepayments with your accountant.';
$_ADDONLANG['setting_exclude_mass_pay'] = 'Mass Pay invoices';
$_ADDONLANG['exclude_mass_pay_always'] = 'Always excluded: they only group other invoices, which are reported one by one.';

// Validation
$_ADDONLANG['error_csrf'] = 'The form has expired. Reload the page and try again.';
$_ADDONLANG['error_too_long'] = 'At most %d characters.';
$_ADDONLANG['error_cui'] = 'Invalid CUI: the check digit does not match.';
$_ADDONLANG['error_county'] = 'Choose a county from the list.';
$_ADDONLANG['error_sector'] = 'For Bucharest the city must be the sector: SECTOR1 to SECTOR6.';
$_ADDONLANG['error_email'] = 'Invalid e-mail address.';
$_ADDONLANG['error_iban'] = 'Invalid IBAN.';
$_ADDONLANG['error_bic'] = 'Invalid BIC / SWIFT code.';
$_ADDONLANG['error_send_delay'] = 'Choose between 0 and %d working days.';
$_ADDONLANG['error_unknown_groups'] = 'Unknown client groups: %s.';
$_ADDONLANG['error_unknown_clients'] = 'There is no client with these IDs: %s.';
$_ADDONLANG['error_invalid_choice'] = 'Invalid choice.';
$_ADDONLANG['error_required_to_enable'] = 'Required before processing can be enabled.';
$_ADDONLANG['error_whmcs_not_ready'] = 'The WHMCS invoice numbering does not pass the checks on the dashboard yet.';
