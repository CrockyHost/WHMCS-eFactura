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
$_ADDONLANG['check_timezone_fix'] = 'It is %s. WHMCS dates invoices in this time zone, so a payment made shortly after midnight in Romania gets the previous day as fiscal invoice date. Set Europe/Bucharest:';
$_ADDONLANG['check_timezone_step_config'] = 'Recommended: add this line at the end of configuration.php, in the WHMCS root folder. It applies to the web pages and to the cron, which runs PHP from the command line.';
$_ADDONLANG['check_timezone_step_ini'] = 'Also, for consistency: change date.timezone in php.ini or .user.ini (in cPanel: MultiPHP INI Editor). On its own this setting covers only the web pages, not the cron.';
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

$_ADDONLANG['field_native_state'] = 'WHMCS State/Region field';

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

// ANAF connection
$_ADDONLANG['nav_anaf'] = 'ANAF connection';
$_ADDONLANG['check_anaf'] = 'ANAF connection';
$_ADDONLANG['check_anaf_not_configured'] = 'Not set up: enter the OAuth application on the ANAF connection page.';
$_ADDONLANG['check_anaf_not_connected'] = 'Not authorized yet: use "Connect to ANAF" with the qualified certificate.';
$_ADDONLANG['check_anaf_reauthorize'] = 'Must be authorized again with the qualified certificate. %s';
$_ADDONLANG['check_anaf_expiring'] = 'Connected. Authorize again with the certificate by %s (%d days left).';
$_ADDONLANG['check_anaf_ok'] = 'Connected. Yearly re-authorization due on %s (%d days left).';

$_ADDONLANG['anaf_status_title'] = 'Connection status';
$_ADDONLANG['anaf_state_not_configured'] = 'Not set up';
$_ADDONLANG['anaf_state_not_connected'] = 'Not connected';
$_ADDONLANG['anaf_state_connected'] = 'Connected';
$_ADDONLANG['anaf_state_expiring'] = 'Re-authorization due soon';
$_ADDONLANG['anaf_state_reauthorize'] = 'Re-authorization needed';
$_ADDONLANG['anaf_field_authorized'] = 'Authorized';
$_ADDONLANG['anaf_authorized_value'] = '%s by %s';
$_ADDONLANG['anaf_field_certificate'] = 'Certificate serial';
$_ADDONLANG['anaf_field_roles'] = 'Token rights';
$_ADDONLANG['anaf_field_access'] = 'Access token valid until';
$_ADDONLANG['anaf_field_reauth'] = 'Authorize again with the certificate by';
$_ADDONLANG['anaf_days_left'] = '%s (%d days left)';
$_ADDONLANG['anaf_field_refreshed'] = 'Last token refresh';
$_ADDONLANG['anaf_never'] = 'not yet';
$_ADDONLANG['anaf_field_environment'] = 'API environment';
$_ADDONLANG['anaf_field_error'] = 'Last error';
$_ADDONLANG['anaf_connect_help'] = 'The authorization is done in a browser that has the qualified certificate (USB token) of a person with SPV rights for the company: legal representative, designated representative or authorized person. If that person does not use this WHMCS, create a link and send it to them: it is valid for %d minutes and works once.';
$_ADDONLANG['anaf_button_connect'] = 'Connect to ANAF';
$_ADDONLANG['anaf_button_reconnect'] = 'Authorize again';
$_ADDONLANG['anaf_button_link'] = 'Link for another person';
$_ADDONLANG['anaf_button_check'] = 'Test connection';
$_ADDONLANG['anaf_button_refresh'] = 'Refresh token now';
$_ADDONLANG['anaf_button_disconnect'] = 'Disconnect';
$_ADDONLANG['anaf_disconnect_confirm'] = 'Delete the ANAF tokens from WHMCS? Nothing can be sent to SPV until a new authorization.';
$_ADDONLANG['anaf_connected'] = 'Connected to ANAF. Use "Test connection" to check the SPV rights for the company CUI.';
$_ADDONLANG['anaf_refreshed'] = 'The tokens were refreshed.';
$_ADDONLANG['anaf_disconnected'] = 'The ANAF tokens were deleted from WHMCS.';
$_ADDONLANG['anaf_action_failed'] = 'The operation failed: %s';

$_ADDONLANG['anaf_app_title'] = 'ANAF OAuth application';
$_ADDONLANG['anaf_app_intro'] = 'Each WHMCS installation uses its own OAuth application, registered on the ANAF developer account of the company. The client ID and secret stay in this WHMCS; the secret and the tokens are stored encrypted.';
$_ADDONLANG['anaf_app_step1'] = 'On anaf.ro open Servicii Online > Înregistrare utilizatori > Dezvoltatori aplicații > Înregistrare pentru API-uri and log in with the developer account (user name and password, no certificate).';
$_ADDONLANG['anaf_app_step2'] = 'In Editare profil Oauth > Gestionare aplicații add an application: a name without spaces (for example WHMCSeFactura), the service E-Factura and the callback URL below.';
$_ADDONLANG['anaf_app_step3'] = 'The application cannot be edited later. Add from the start, with the + button, the callback URL of every WHMCS that will use it (for example production and a test copy).';
$_ADDONLANG['anaf_app_step4'] = 'Click Generare Client ID and copy the Client ID and the Client Secret into the form below.';
$_ADDONLANG['anaf_callback_url'] = 'Callback URL to register at ANAF';
$_ADDONLANG['anaf_callback_not_https'] = 'ANAF accepts only https:// callback URLs. Set the WHMCS System URL to an https address.';
$_ADDONLANG['anaf_client_id'] = 'Client ID';
$_ADDONLANG['anaf_client_secret'] = 'Client Secret';
$_ADDONLANG['anaf_secret_saved'] = 'Saved. Leave empty to keep it.';
$_ADDONLANG['anaf_button_save'] = 'Save application';
$_ADDONLANG['anaf_credentials_saved'] = 'The OAuth application was saved.';
$_ADDONLANG['anaf_error_client_id'] = 'Enter the client ID, without spaces.';
$_ADDONLANG['anaf_error_client_secret'] = 'Enter the client secret.';

$_ADDONLANG['anaf_authorize_title'] = 'Authorization at ANAF';
$_ADDONLANG['anaf_authorize_redirect'] = 'Opening ANAF. Choose the qualified certificate when the browser asks for it.';
$_ADDONLANG['anaf_authorize_continue'] = 'Continue to ANAF';
$_ADDONLANG['anaf_authorize_link_intro'] = 'Send this link to the person who has the qualified certificate. It is valid for %d minutes and works once; after the authorization the connection appears on the ANAF connection page.';
$_ADDONLANG['anaf_authorize_back'] = 'Back to the ANAF connection';

$_ADDONLANG['anaf_check_no_cui'] = 'Fill in the company CUI in Settings first.';
$_ADDONLANG['anaf_check_token'] = 'There is no usable token: %s';
$_ADDONLANG['anaf_check_unreachable'] = 'ANAF could not be reached: %s';
$_ADDONLANG['anaf_check_rejected'] = 'ANAF rejected the token (HTTP %d). Authorize again.';
$_ADDONLANG['anaf_check_unexpected'] = 'Unexpected answer from ANAF (HTTP %d): %s';
$_ADDONLANG['anaf_check_ok'] = 'The connection works: the certificate has SPV rights for CUI %s (%s, %d messages in the last day).';
$_ADDONLANG['anaf_check_no_rights'] = 'The token is valid, but the certificate has no SPV rights for CUI %s: %s';
$_ADDONLANG['anaf_check_error'] = 'ANAF answered: %s';

$_ADDONLANG['alert_reauth_soon_subject'] = 'ANAF re-authorization needed in %d days';
$_ADDONLANG['alert_reauth_soon_body'] = "The WHMCS connection to ANAF e-Factura must be authorized again with the qualified certificate by %s (%d days left). After that date nothing can be sent to SPV until it is authorized again.\n\n%s";
$_ADDONLANG['alert_reauth_now_subject'] = 'ANAF re-authorization needed now';
$_ADDONLANG['alert_reauth_now_body'] = "The WHMCS connection to ANAF e-Factura no longer works and must be authorized again with the qualified certificate. Nothing is sent to SPV until then.\nLast error: %s\n\n%s";

$_ADDONLANG['callback_title'] = 'e-Factura: ANAF authorization';
$_ADDONLANG['callback_success'] = 'The connection to ANAF was saved. You can close this window.';
$_ADDONLANG['callback_invalid_state'] = 'This authorization link is invalid, expired or already used. Create a new one in WHMCS (Addons > WHMCS-eFactura > ANAF connection).';
$_ADDONLANG['callback_denied'] = 'ANAF did not authorize the access (%s). Check that the qualified certificate is connected and enrolled in SPV for the company, then try again.';
$_ADDONLANG['callback_failed'] = 'The authorization could not be completed: %s';

// Fiscal numbering
$_ADDONLANG['review_lock_timeout'] = 'paid while the fiscal numbering was busy';
$_ADDONLANG['review_counter_gap'] = 'possible gap in the fiscal series';
$_ADDONLANG['review_duplicate_number'] = 'number already used';
$_ADDONLANG['excluded_eu_reverse_charge'] = 'EU company, reverse charge';
$_ADDONLANG['excluded_non_eu'] = 'client outside the EU';
$_ADDONLANG['alert_review_subject'] = 'Fiscal invoice %s needs a manual check';
$_ADDONLANG['alert_review_body'] = "Invoice #%d received the fiscal number %s, but it needs a manual check: %s. Its e-Factura document is on hold until you check that the number is unique and that the series has no gap, then release it.\n\n%s";
$_ADDONLANG['alert_gap_subject'] = 'Possible gap in the fiscal series at %s';
$_ADDONLANG['alert_gap_body'] = "WHMCS gave the fiscal number %s to invoice #%d, which must not keep it, and the number could not be returned to the counter because other numbers were taken in the meantime. Check the fiscal series and the Next Paid Invoice Number counter.\n\n%s";
$_ADDONLANG['alert_duplicate_subject'] = 'Duplicate fiscal number %s corrected';
$_ADDONLANG['alert_duplicate_body'] = "WHMCS gave invoice #%d the fiscal number %s, which was already in use. The addon replaced it with %s.\n\n%s";
$_ADDONLANG['alert_hook_failed_subject'] = 'Error while processing invoice #%d';
$_ADDONLANG['alert_hook_failed_body'] = "The e-Factura step \"%s\" failed for invoice #%d: %s\nThe payment itself was not affected. Check the invoice and its fiscal number.";

// Payment means (BT-81)
$_ADDONLANG['section_payment_means'] = 'Payment means';
$_ADDONLANG['section_payment_means_intro'] = 'The payment means code (UNCL 4461) written on the invoices of each WHMCS payment gateway. Codes 30, 42 and 58 add the seller IBAN for the invoice currency; 30 and 58 require it.';
$_ADDONLANG['no_gateways'] = 'There are no active payment gateways in WHMCS.';
$_ADDONLANG['pm_1'] = 'Instrument not defined';
$_ADDONLANG['pm_10'] = 'Cash';
$_ADDONLANG['pm_30'] = 'Credit transfer';
$_ADDONLANG['pm_42'] = 'Payment to bank account';
$_ADDONLANG['pm_48'] = 'Bank card';
$_ADDONLANG['pm_54'] = 'Credit card';
$_ADDONLANG['pm_55'] = 'Debit card';
$_ADDONLANG['pm_58'] = 'SEPA credit transfer';
$_ADDONLANG['pm_68'] = 'Online payment service';
$_ADDONLANG['pm_97'] = 'Clearing between partners';
$_ADDONLANG['pm_none'] = '(not written on the invoice)';

// e-Factura XML: problems shown to the admin
$_ADDONLANG['ubl_party_seller'] = 'Seller (addon Settings)';
$_ADDONLANG['ubl_party_buyer'] = 'Client';
$_ADDONLANG['ubl_line'] = 'Line %s';
$_ADDONLANG['ubl_field_number'] = 'Invoice number';
$_ADDONLANG['ubl_field_name'] = 'the name';
$_ADDONLANG['ubl_field_trade_name'] = 'trade name';
$_ADDONLANG['ubl_field_street'] = 'the street address';
$_ADDONLANG['ubl_field_additional_street'] = 'second address line';
$_ADDONLANG['ubl_field_city'] = 'the city';
$_ADDONLANG['ubl_field_postcode'] = 'postal code';
$_ADDONLANG['ubl_field_contact'] = 'contact name';
$_ADDONLANG['ubl_field_phone'] = 'phone';
$_ADDONLANG['ubl_field_email'] = 'e-mail';
$_ADDONLANG['ubl_field_legal_form'] = 'additional legal information';
$_ADDONLANG['ubl_field_item_name'] = 'item name';
$_ADDONLANG['ubl_field_item_description'] = 'item description';
$_ADDONLANG['ubl_field_line_note'] = 'line note';
$_ADDONLANG['ubl_field_note'] = 'Invoice note';
$_ADDONLANG['ubl_field_exemption'] = 'VAT exemption reason';
$_ADDONLANG['ubl_required'] = '%s: %s is missing.';
$_ADDONLANG['ubl_too_long'] = '%s has %d characters; at most %d are allowed.';
$_ADDONLANG['ubl_number_digit'] = 'The invoice number "%s" must contain at least one digit.';
$_ADDONLANG['ubl_notes_count'] = 'At most 20 invoice notes are allowed.';
$_ADDONLANG['ubl_county'] = '%s: "%s" is not a Romanian county code (ISO 3166-2:RO).';
$_ADDONLANG['ubl_sector'] = '%s: in Bucharest the city must be the sector (SECTOR1 to SECTOR6), not "%s".';
$_ADDONLANG['ubl_vat_prefix'] = '%s: the VAT number "%s" must start with the country code.';
$_ADDONLANG['ubl_cui'] = '%s: "%s" is not a valid CUI (the check digit does not match).';
$_ADDONLANG['ubl_cnp'] = '%s: the CNP %s is not valid (the check digit does not match).';
$_ADDONLANG['ubl_seller_id'] = 'Seller: the CUI is missing (addon Settings).';
$_ADDONLANG['ubl_buyer_id'] = 'Client: there is no identifier. A company needs its CUI or VAT number; an individual is identified by CNP or 13 zeros.';
$_ADDONLANG['ubl_no_lines'] = 'The document has no lines.';
$_ADDONLANG['ubl_line_sign'] = 'Line %s: the quantity must be 1 or -1, with the sign of the amount.';
$_ADDONLANG['ubl_tax_base'] = 'VAT %s %s%%: the taxable amount %s differs from the sum of its lines, %s.';
$_ADDONLANG['ubl_tax_rate'] = 'The VAT %s on %s at %s%% differs from %s by 1.00 or more, which ANAF rejects [BR-S-09]. The amounts come from WHMCS and are not changed: correct the invoice in WHMCS (storno and a new invoice).';
$_ADDONLANG['ubl_exempt_forbidden'] = 'A standard-rate VAT breakdown must not have an exemption reason.';
$_ADDONLANG['ubl_exempt_tax'] = 'VAT category %s must have a VAT amount of 0.';
$_ADDONLANG['ubl_exempt_reason'] = 'VAT category %s needs an exemption reason.';
$_ADDONLANG['ubl_missing_category'] = 'VAT %s %s%% has lines but no VAT breakdown.';
$_ADDONLANG['ubl_tax_total'] = 'The VAT breakdown adds up to %s, but the VAT total is %s.';
$_ADDONLANG['ubl_ron_vat'] = 'The VAT in RON is missing for a document in %s.';
$_ADDONLANG['ubl_due_date'] = 'The invoice is not fully paid, so it needs a due date.';
$_ADDONLANG['ubl_iban'] = 'The payment means code %s needs the IBAN of the %s account in the addon Settings.';
$_ADDONLANG['map_client_missing'] = 'Client #%d does not exist.';
$_ADDONLANG['map_invoice_missing'] = 'WHMCS invoice #%d does not exist.';
$_ADDONLANG['map_original_missing'] = 'The invoice this storno corrects has no fiscal number.';
$_ADDONLANG['map_note_missing'] = 'WHMCS credit note #%d does not exist.';
$_ADDONLANG['map_storno_amounts'] = 'The storno has no fixed amounts.';
$_ADDONLANG['map_currency_missing'] = 'The client has no currency.';
$_ADDONLANG['map_company_without_cui'] = 'The client "%s" is a company but has no CUI (field: %s). Add the CUI to the client profile.';
$_ADDONLANG['map_cui_invalid'] = 'The client CUI "%s" is not valid (the check digit does not match). Correct it in the client profile.';
$_ADDONLANG['map_cnp_invalid'] = 'The client CNP is not valid (the check digit does not match). Correct it or leave it empty.';
$_ADDONLANG['map_county_missing'] = 'The client has no county (field: %s).';
$_ADDONLANG['map_county_unknown'] = 'The client county "%s" is not a Romanian county (field: %s). Correct it in the client profile.';
$_ADDONLANG['map_sector_missing'] = 'The client is in Bucharest, but the sector (Sector 1 to Sector 6) is in none of city, address or county. Add it to the client profile.';
$_ADDONLANG['map_address_missing'] = 'The client street address is missing.';
$_ADDONLANG['map_city_missing'] = 'The client city is missing.';
$_ADDONLANG['map_eu_vat_missing'] = 'The EU company client has no VAT number.';
$_ADDONLANG['map_foreign_id_missing'] = 'The company client outside the EU has no tax identifier.';
$_ADDONLANG['map_subtotal'] = 'The invoice lines add up to %s, but the WHMCS subtotal is %s.';
$_ADDONLANG['map_tax2'] = 'The invoice has a second-level tax (%s), which e-Factura does not support.';
$_ADDONLANG['map_total'] = 'Subtotal %s plus VAT %s is not the WHMCS total %s.';
$_ADDONLANG['map_untaxed_line'] = 'The line "%s" has no VAT, and the client is in Romania: its VAT category cannot be determined. Check the product tax setting.';
$_ADDONLANG['map_exchange_rate'] = 'No BNR exchange rate for %s: %s';

// Queue alerts
$_ADDONLANG['alert_invalid_subject'] = 'e-Factura document %s cannot be generated';
$_ADDONLANG['alert_invalid_body'] = "The XML of document %s (invoice #%d) cannot be generated from the current data:\n%s\n\nCorrect the data; the document is retried automatically every 15 minutes.\n%s";
$_ADDONLANG['alert_rejected_subject'] = 'e-Factura document %s rejected';
$_ADDONLANG['alert_rejected_body'] = "ANAF did not accept document %s (invoice #%d):\n%s\n\nCorrect the data and send it again (same number).\n%s";
$_ADDONLANG['alert_archive_lost_subject'] = 'The ANAF answer for %s can no longer be downloaded';
$_ADDONLANG['alert_archive_lost_body'] = "The signed answer of ANAF for document %s (invoice #%d) could not be archived: %s\n%s";
$_ADDONLANG['alert_auth_subject'] = 'ANAF refuses the e-Factura calls';
$_ADDONLANG['alert_auth_body'] = "ANAF answered: %s\nSending to SPV is paused for an hour and retried. Check the ANAF connection and the SPV rights of the certificate: %s";
$_ADDONLANG['alert_technical_subject'] = 'ANAF keeps answering with a technical error for %s';
$_ADDONLANG['alert_technical_body'] = "Each upload of document %s (invoice #%d) gets this answer from ANAF: %s\nThe addon checks whether ANAF registered the file anyway and sends the same file again every hour until ANAF accepts it. If this goes on and the deadline gets close, contact ANAF support.\n%s";
$_ADDONLANG['alert_deadline_soon_subject'] = 'Documents close to the e-Factura deadline (%d)';
$_ADDONLANG['alert_deadline_today_subject'] = 'Last day to send documents to e-Factura (%d)';
$_ADDONLANG['alert_deadline_overdue_subject'] = 'URGENT: documents past the e-Factura deadline (%d)';
$_ADDONLANG['alert_deadline_body'] = "These documents have not reached ANAF yet, and the legal deadline (5 working days from issue) is close or has passed:\n\n%s\n\nFor each one: correct the data if it is invalid or rejected, release it if it is on hold, or check the ANAF connection.\n%s";
$_ADDONLANG['alert_deadline_line'] = '%s (invoice #%d): %s, deadline %s, %s';
$_ADDONLANG['alert_deadline_left'] = '%d working day(s) left';
$_ADDONLANG['alert_deadline_last_day'] = 'last day';
$_ADDONLANG['alert_deadline_late'] = '%d working day(s) late';
$_ADDONLANG['alert_processing_subject'] = 'ANAF has been processing documents for over %d hours (%d)';
$_ADDONLANG['alert_processing_body'] = "ANAF has not given a verdict yet for these uploads:\n\n%s\n\nThe status is checked automatically. Delays of over 2 days have been reported; if it persists, contact ANAF support with the upload index.\n%s";
$_ADDONLANG['alert_processing_line'] = '%s (invoice #%d): upload index %s, uploaded at %s';
$_ADDONLANG['alert_storno_waiting_subject'] = 'The storno of %s has not been issued yet';
$_ADDONLANG['alert_storno_waiting_body'] = "Fiscal invoice %s (invoice #%d) was cancelled or refunded, but its storno could not be issued for more than 30 minutes (the fiscal numbering was busy, or an error occurred). It is retried at every cron run.\n%s\n%s";
$_ADDONLANG['alert_storno_overflow_subject'] = 'Refund on %s not reversed automatically';
$_ADDONLANG['alert_storno_overflow_body'] = "A refund on fiscal invoice %s (invoice #%d) would reverse more than the invoice, together with the stornos already issued (WHMCS credit note #%s). No storno was issued for it: check the invoice and its stornos.\n%s";
$_ADDONLANG['alert_storno_no_note_subject'] = 'No credit note for a refund on %s';
$_ADDONLANG['alert_storno_no_note_body'] = "A refund on fiscal invoice %s (invoice #%d) was recorded a week ago, but WHMCS has not created a credit note for it, so no storno was issued. Check the invoice.%s\n%s";
$_ADDONLANG['alert_masspay_partial_subject'] = 'Partial refund of Mass Pay invoice #%d: stornos needed by hand';
$_ADDONLANG['alert_masspay_partial_body'] = "Mass Pay invoice #%d was refunded in part (%s). It paid these fiscal invoices: %s. A partial refund is not split between them automatically: issue the storno of each invoice concerned from its e-Factura panel.\n%s";
$_ADDONLANG['alert_masspay_reversed_subject'] = 'Mass Pay invoice #%d refunded: an invoice already has stornos';
$_ADDONLANG['alert_masspay_reversed_body'] = "Mass Pay invoice #%d was refunded in full, but fiscal invoice %s already has stornos, so it was not reversed automatically (the invoices paid were: %s). Check it and issue what is left by hand.\n%s";

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
$_ADDONLANG['error_system_not_ready'] = 'The configuration checks on the dashboard (WHMCS invoice numbering, PHP time zone, PHP extensions) do not pass yet.';

// Invoice panel and documents
$_ADDONLANG['panel_title'] = 'e-Factura';
$_ADDONLANG['kind_invoice'] = 'Invoice';
$_ADDONLANG['kind_storno'] = 'Storno';
$_ADDONLANG['storno_reason_refund_full'] = 'full refund';
$_ADDONLANG['storno_reason_refund_partial'] = 'partial refund';
$_ADDONLANG['storno_reason_cancel'] = 'invoice cancelled';
$_ADDONLANG['storno_reason_cancel_rest'] = 'cancelled after partial refunds';
$_ADDONLANG['storno_reason_manual'] = 'issued by an admin';
$_ADDONLANG['panel_issue_date'] = 'Issue date';
$_ADDONLANG['panel_total'] = 'Total';
$_ADDONLANG['panel_corrects'] = 'Corrects';
$_ADDONLANG['panel_send_after'] = 'Sent to SPV after';
$_ADDONLANG['panel_deadline'] = 'Legal deadline';
$_ADDONLANG['panel_upload_index'] = 'Upload index';
$_ADDONLANG['panel_anaf_state'] = 'ANAF answer';
$_ADDONLANG['panel_uploaded_at'] = 'Uploaded at';
$_ADDONLANG['panel_validated_at'] = 'Validated at';
$_ADDONLANG['panel_attempts'] = 'Attempts';
$_ADDONLANG['panel_next_attempt'] = 'Next attempt';
$_ADDONLANG['panel_issued_by'] = 'Issued by';
$_ADDONLANG['panel_waits_for_original'] = 'Sent to SPV after the invoice it corrects is validated.';
$_ADDONLANG['panel_review'] = 'Manual check needed:';
$_ADDONLANG['panel_held_by'] = 'Held by %s on %s.';
$_ADDONLANG['panel_excluded'] = 'Not reported to e-Factura:';
$_ADDONLANG['panel_errors'] = 'Errors:';
$_ADDONLANG['panel_last_error'] = 'Last error:';
$_ADDONLANG['panel_stornos'] = 'Stornos';
$_ADDONLANG['panel_storno_title'] = 'Issue a storno';
$_ADDONLANG['panel_storno_intro'] = 'Enter the part to reverse (positive amounts). The whole rest gives the invoice lines negated; a part gives one line. What is left of this invoice:';
$_ADDONLANG['panel_storno_net'] = 'Net';
$_ADDONLANG['panel_storno_tax'] = 'VAT';
$_ADDONLANG['panel_storno_confirm'] = 'Issue a storno in the fiscal series? It takes the next fiscal number and cannot be undone.';
$_ADDONLANG['panel_proforma'] = 'Proforma: the fiscal invoice is issued when it is paid.';
$_ADDONLANG['panel_issue_early_confirm'] = 'Issue the fiscal invoice now? It takes the next number of the fiscal series and cannot be undone.';
$_ADDONLANG['panel_not_fiscal'] = 'Not a fiscal document:';
$_ADDONLANG['panel_no_document'] = 'This invoice has no e-Factura document (it was paid before the addon was processing, or it is not a fiscal invoice).';
$_ADDONLANG['notfiscal_mass_pay'] = 'Mass Pay invoice; the invoices it pays are reported one by one.';
$_ADDONLANG['notfiscal_add_funds'] = 'adding funds to the account, excluded in the settings.';
$_ADDONLANG['notfiscal_zero_total'] = 'total zero, excluded in the settings.';
$_ADDONLANG['file_xml_sent'] = 'XML sent';
$_ADDONLANG['file_anaf_zip'] = 'Signed ZIP from ANAF';
$_ADDONLANG['file_anaf_errors_zip'] = 'ANAF errors (ZIP)';
$_ADDONLANG['file_archive_lost'] = 'The answer of ANAF could not be downloaded in time and can no longer be obtained (60 days).';
$_ADDONLANG['btn_details'] = 'Details';
$_ADDONLANG['btn_send_now'] = 'Send to SPV now';
$_ADDONLANG['btn_retry_now'] = 'Try again now';
$_ADDONLANG['btn_send_again'] = 'Send again after correcting the data';
$_ADDONLANG['btn_hold'] = 'Hold';
$_ADDONLANG['btn_release'] = 'Release';
$_ADDONLANG['btn_check'] = 'Check the data';
$_ADDONLANG['btn_storno'] = 'Issue storno';
$_ADDONLANG['btn_issue_early'] = 'Issue the fiscal invoice now';
$_ADDONLANG['confirm_send_again'] = 'The XML is generated again from the current client data, with the same number, and sent to SPV. Continue?';
$_ADDONLANG['action_unknown'] = 'Unknown action.';
$_ADDONLANG['action_failed'] = 'The action failed: %s';
$_ADDONLANG['action_document_missing'] = 'The document does not exist.';
$_ADDONLANG['action_not_allowed'] = 'Not possible while the document is "%s".';
$_ADDONLANG['action_done_send_now'] = '%s goes to SPV at the next queue run.';
$_ADDONLANG['action_done_hold'] = '%s is on hold and will not be sent until released.';
$_ADDONLANG['action_done_release'] = '%s is released and will be sent at its time.';
$_ADDONLANG['action_done_issue_early'] = 'Fiscal invoice %s issued.';
$_ADDONLANG['action_done_storno'] = 'Storno %s issued.';
$_ADDONLANG['action_check_ok'] = 'The XML of %s can be generated from the current data.';
$_ADDONLANG['action_check_failed'] = 'The XML of %s cannot be generated from the current data:';
$_ADDONLANG['early_error_disabled'] = 'Processing is disabled in the settings.';
$_ADDONLANG['early_error_not_unpaid'] = 'Only unpaid invoices can be issued early.';
$_ADDONLANG['early_error_already_fiscal'] = 'The invoice already has a fiscal number.';
$_ADDONLANG['early_error_not_fiscal'] = 'This invoice is not a fiscal document.';
$_ADDONLANG['early_error_busy'] = 'The fiscal numbering is busy; try again in a moment.';
$_ADDONLANG['storno_error_amounts'] = 'Enter positive amounts, within what is left of the invoice.';
$_ADDONLANG['storno_error_not_fiscal'] = 'Only a fiscal invoice can be reversed.';
$_ADDONLANG['storno_error_busy'] = 'The fiscal numbering is busy; try again in a moment.';
$_ADDONLANG['download_missing'] = 'The file does not exist.';

// Documents pages
$_ADDONLANG['nav_documents'] = 'Documents';
$_ADDONLANG['filter_state'] = 'State';
$_ADDONLANG['filter_kind'] = 'Type';
$_ADDONLANG['filter_search'] = 'Number, invoice #, client';
$_ADDONLANG['filter_all'] = 'All';
$_ADDONLANG['filter_attention'] = 'Needs attention';
$_ADDONLANG['btn_filter'] = 'Filter';
$_ADDONLANG['documents_count'] = '%d documents';
$_ADDONLANG['documents_none_found'] = 'No documents match the filter.';
$_ADDONLANG['col_number'] = 'Number';
$_ADDONLANG['col_kind'] = 'Type';
$_ADDONLANG['col_invoice'] = 'WHMCS invoice';
$_ADDONLANG['col_client'] = 'Client';
$_ADDONLANG['col_issue_date'] = 'Issued';
$_ADDONLANG['col_total'] = 'Total';
$_ADDONLANG['col_state'] = 'State';
$_ADDONLANG['col_deadline'] = 'Deadline';
$_ADDONLANG['col_upload_index'] = 'Upload index';
$_ADDONLANG['document_not_found'] = 'The document does not exist.';
$_ADDONLANG['document_history'] = 'History';
$_ADDONLANG['history_none'] = 'No events recorded.';
$_ADDONLANG['queue_title'] = 'Queue';
$_ADDONLANG['queue_last_run'] = 'Last run:';
$_ADDONLANG['queue_never'] = 'never';
$_ADDONLANG['queue_worker_late'] = 'The queue has not run for more than 15 minutes. Check that the WHMCS system cron runs every 5 minutes.';
$_ADDONLANG['queue_breaker'] = 'Paused after repeated ANAF errors, until';
$_ADDONLANG['queue_auth_paused'] = 'Paused: ANAF refuses the authorization, until';
$_ADDONLANG['queue_pending_stornos'] = 'Stornos waiting to be issued';
$_ADDONLANG['queue_near_deadline'] = 'Not sent, deadline within 1 working day';
$_ADDONLANG['queue_attention'] = 'Documents that need attention';
$_ADDONLANG['event_document_created'] = 'Document created';
$_ADDONLANG['event_storno_created'] = 'Storno issued';
$_ADDONLANG['event_issued_early'] = 'Issued before payment';
$_ADDONLANG['event_document_review'] = 'Manual check needed';
$_ADDONLANG['event_upload_started'] = 'Upload started';
$_ADDONLANG['event_uploaded'] = 'Uploaded to ANAF';
$_ADDONLANG['event_upload_unknown'] = 'Upload with no clear answer';
$_ADDONLANG['event_validated'] = 'Validated by ANAF';
$_ADDONLANG['event_rejected'] = 'Rejected by ANAF';
$_ADDONLANG['event_retry'] = 'Technical error, will retry';
$_ADDONLANG['event_invalid'] = 'Invalid data';
$_ADDONLANG['event_duplicate'] = 'Duplicate: follows the original upload';
$_ADDONLANG['event_reconciled'] = 'Found among the sent invoices';
$_ADDONLANG['event_held'] = 'Put on hold';
$_ADDONLANG['event_released'] = 'Released';
$_ADDONLANG['event_send_now'] = 'Sent now by an admin';
$_ADDONLANG['event_deadline_alert'] = 'Deadline alert sent';
$_ADDONLANG['event_processing_alert'] = 'Processing alert sent';
$_ADDONLANG['event_number_replaced'] = 'Proforma number replaced';
$_ADDONLANG['event_number_restored'] = 'Fiscal number restored';
$_ADDONLANG['event_number_withdrawn'] = 'Fiscal number given back';
$_ADDONLANG['event_number_gap'] = 'Possible gap in the series';
$_ADDONLANG['note_no_storno'] = 'No storno was issued from this credit note: it does not come from a refund of a fiscal invoice.';
$_ADDONLANG['note_corrects'] = 'Credit note on the fiscal invoice';
$_ADDONLANG['storno_error_vat'] = 'The VAT %s does not match %s%% of the net %s (expected %s). Correct the VAT: the form computes it from the net.';
$_ADDONLANG['storno_error_vat_none'] = 'This invoice has no VAT: the VAT of the storno must be 0.';
$_ADDONLANG['storno_error_invalid'] = 'The storno cannot be built from the current data, so nothing was issued and no number was used:';
$_ADDONLANG['panel_storno_vat_hint'] = 'The VAT is computed at %s%% of the net; it can be changed.';
