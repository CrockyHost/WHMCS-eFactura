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

// Client area: billing details (client type, CUI, Reg. Com., CNP)
$_ADDONLANG['cd_billing_title'] = 'Billing details';
$_ADDONLANG['cd_type_label'] = 'Customer type';
$_ADDONLANG['cd_type_person'] = 'Individual';
$_ADDONLANG['cd_type_person_hint'] = 'invoices in your own name';
$_ADDONLANG['cd_type_company'] = 'Legal entity';
$_ADDONLANG['cd_type_company_hint'] = 'company, sole trader, NGO or institution';
$_ADDONLANG['cd_cui'] = 'CUI (fiscal code)';
$_ADDONLANG['cd_cui_placeholder'] = 'e.g. 12345678';
$_ADDONLANG['cd_company_name'] = 'Company name';
$_ADDONLANG['cd_regcom'] = 'Trade register no.';
$_ADDONLANG['cd_regcom_placeholder'] = 'e.g. J40/1234/2020 or J2024012345008';
$_ADDONLANG['cd_optional'] = 'optional';
$_ADDONLANG['cd_vat_payer'] = 'The company is VAT registered';
$_ADDONLANG['cd_vat_payer_hint'] = 'The VAT number (RO followed by the CUI) is shown on the invoice.';
$_ADDONLANG['cd_vat_code'] = 'VAT number: %s';
$_ADDONLANG['cd_vat_number'] = 'VAT number';
$_ADDONLANG['cd_cnp'] = 'CNP (personal numeric code)';
$_ADDONLANG['cd_cnp_help'] = 'If left empty, the electronic invoice (e-Factura) uses 13 zeros, as the law allows.';
$_ADDONLANG['cd_missing'] = 'not filled in';
$_ADDONLANG['cd_yes'] = 'yes';
$_ADDONLANG['cd_no'] = 'no';
$_ADDONLANG['cd_lock_note'] = 'Billing details cannot be changed from your account.';
$_ADDONLANG['cd_lock_link'] = 'Ask for a change in a support ticket';
$_ADDONLANG['cd_error_cui_required'] = 'For a Romanian company, enter the CUI (fiscal code).';
$_ADDONLANG['cd_error_cui_invalid'] = 'The CUI is not valid: the check digit does not match.';
$_ADDONLANG['cd_error_company_name'] = 'Enter the company name.';
$_ADDONLANG['cd_error_regcom'] = 'The trade register number is not valid (e.g. J40/1234/2020 or J2024012345008).';
$_ADDONLANG['cd_error_cnp'] = 'The CNP is not valid.';
$_ADDONLANG['cd_error_vat_mismatch'] = 'The VAT number must be RO followed by the company CUI.';
$_ADDONLANG['cd_error_person_company'] = 'For an individual, leave the company name and the CUI empty.';
$_ADDONLANG['cd_error_person_vat'] = 'An individual has no VAT number.';
$_ADDONLANG['cd_error_locked'] = 'Billing details (customer type, CUI, trade register no., CNP, VAT number) cannot be changed from your account. Open a support ticket to change them.';

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
$_ADDONLANG['setting_client_profile_lock'] = 'Billing details are read-only in the profile';
$_ADDONLANG['help_client_profile_lock'] = 'Only an admin changes the customer type, company name, CUI, trade register no., CNP and VAT number. A Romanian company without CUI is never accepted.';

// Client area and admin: ANAF company lookup
$_ADDONLANG['cd_lookup_button'] = 'Fetch from ANAF';
$_ADDONLANG['cd_lookup_address_button'] = 'Update the address from ANAF';
$_ADDONLANG['cd_lookup_busy'] = 'Searching ANAF...';
$_ADDONLANG['cd_lookup_filled'] = 'The company details were filled in from ANAF. Check them before you continue.';
$_ADDONLANG['cd_lookup_same'] = 'The details you entered match ANAF.';
$_ADDONLANG['cd_lookup_review'] = 'ANAF has other values for fields you already filled in. Choose what to replace:';
$_ADDONLANG['cd_lookup_apply'] = 'Replace the ticked values';
$_ADDONLANG['cd_lookup_keep'] = 'Keep my details';
$_ADDONLANG['cd_lookup_now'] = 'Now';
$_ADDONLANG['cd_lookup_anaf'] = 'ANAF';
$_ADDONLANG['cd_lookup_locked'] = 'Differs from ANAF but cannot be changed from your account';
$_ADDONLANG['cd_lookup_error'] = 'The lookup failed. Fill in the details by hand.';
$_ADDONLANG['cd_lookup_error_token'] = 'Your session expired. Reload the page and try again.';
$_ADDONLANG['cd_lookup_error_rate'] = 'Too many lookups in a short time. Try again in a few minutes.';
$_ADDONLANG['cd_lookup_not_found'] = 'ANAF has no company with this CUI.';
$_ADDONLANG['cd_lookup_unavailable'] = 'The ANAF service does not answer right now. Fill in the details by hand or try again later.';
$_ADDONLANG['cd_lookup_need_cui'] = 'Enter a valid CUI first.';
$_ADDONLANG['cd_field_address1'] = 'Address';
$_ADDONLANG['cd_field_address2'] = 'Address line 2';
$_ADDONLANG['cd_field_city'] = 'City';
$_ADDONLANG['cd_field_state'] = 'County';
$_ADDONLANG['cd_field_postcode'] = 'Postcode';
$_ADDONLANG['cd_status_vat'] = 'VAT registered';
$_ADDONLANG['cd_status_novat'] = 'Not VAT registered';
$_ADDONLANG['cd_status_vat_collection'] = 'VAT on collection';
$_ADDONLANG['cd_status_inactive'] = 'Fiscally inactive';
$_ADDONLANG['cd_status_deregistered'] = 'Deregistered';
$_ADDONLANG['cd_status_einvoice'] = 'In the RO e-Factura registry';

// Settings and health check: the client fields the addon owns
$_ADDONLANG['section_client_owned_fields'] = 'Client fields';
$_ADDONLANG['section_client_owned_fields_intro'] = 'The addon uses client custom fields of its own, created automatically and created again when missing. The county is always the WHMCS State/Region field.';
$_ADDONLANG['client_field_role_cui'] = 'CUI';
$_ADDONLANG['client_field_role_regcom'] = 'Trade register no.';
$_ADDONLANG['client_field_role_cnp'] = 'CNP';
$_ADDONLANG['client_field_value'] = '%s (#%d)';
$_ADDONLANG['client_field_state'] = 'On the client forms: %1$s. On the invoice: %2$s.';
$_ADDONLANG['setting_client_cui_on_invoice'] = 'Show the CUI on the invoice';
$_ADDONLANG['help_client_cui_on_invoice'] = 'Turns "Show on Invoice" on or off for the addon CUI field.';
$_ADDONLANG['setting_client_regcom_on_invoice'] = 'Show the trade register no. on the invoice';
$_ADDONLANG['help_client_regcom_on_invoice'] = 'Turns "Show on Invoice" on or off for the addon trade register field.';
$_ADDONLANG['check_client_fields'] = 'Client fields';
$_ADDONLANG['check_client_fields_ok'] = 'CUI #%d, trade register no. #%d, CNP #%d';
