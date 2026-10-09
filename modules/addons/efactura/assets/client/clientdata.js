/*!
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

/*
 * Client forms for Romanian addresses, on top of the forms WHMCS renders in
 * any theme (registration, checkout, profile, contacts, admin client pages):
 * - registers the Romanian counties for StatesDropdown.js, which then turns
 *   State/Region into a dropdown and saves the county name;
 * - preselects old values written differently ("DOLJ", "Bucuresti") and
 *   explains values that are not a county ("Romania", "-");
 * - in Bucharest, replaces City with a sector dropdown that saves "Sector N";
 * - on registration, checkout and the profile, groups the billing identity
 *   in one section: individual (optional CNP) or legal entity (CUI, company
 *   name, trade register number, VAT payer). The section moves the fields
 *   WHMCS renders (company name, VAT number, custom fields), so WHMCS saves
 *   them as usual; in the profile it can be a read-only summary.
 * Runs synchronously at the end of the page, before DOM ready, so the
 * fields are in place when the page first shows.
 */
(function (window, document) {
    'use strict';

    var config = window.efacturaClientData;
    var $ = window.jQuery;
    if (!config || !$) {
        return;
    }

    var text = config.text || {};
    var SECTOR_ID = 'efacturaSector';
    var STATE_HINT_ID = 'efacturaStateHint';
    var SECTOR_HINT_ID = 'efacturaSectorHint';
    var SECTOR_PATTERN = /(?:^|[^a-z])(?:sectorul|sector|sect\.?|sec\.?)\s*([1-6])(?![0-9])/;
    var SECTOR_ONLY_PATTERN = /^(?:municipiul\s+|mun\.?\s*)?(?:bucuresti|bucharest)?[\s,-]*(?:sectorul|sector|sect\.?|sec\.?)\s*[1-6]$/;
    var BUCHAREST_KEYS = ['bucuresti', 'bucharest', 'municipiulbucuresti', 'munbucuresti'];

    // Text matching, the same rules as Romania\Counties::fromText() on the server.

    function fold(value) {
        return String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function countyKey(folded) {
        return folded.replace(/\b(judetul|judet|jud|county|romania)\b\.?/g, ' ').replace(/[^a-z]/g, '');
    }

    var namesByKey = {};
    config.counties.forEach(function (name) {
        namesByKey[countyKey(fold(name))] = name;
    });

    function sectorOf(value) {
        var match = SECTOR_PATTERN.exec(fold(value));

        return match ? 'Sector ' + match[1] : null;
    }

    function canonical(value) {
        var code = /^(?:RO-)?([A-Z]{1,2})$/.exec(String(value || '').trim().toUpperCase());
        if (code && config.codes[code[1]]) {
            return config.codes[code[1]];
        }
        var folded = fold(value).trim();
        if (sectorOf(folded) && SECTOR_ONLY_PATTERN.test(folded)) {
            return config.bucharest;
        }
        var key = countyKey(folded);
        if (BUCHAREST_KEYS.indexOf(key) !== -1) {
            return config.bucharest;
        }

        return namesByKey[key] || null;
    }

    function format(message, value) {
        return String(message || '').replace('%s', value);
    }

    // DOM helpers that work on every theme: fields are found by name, labels by "for".

    function scopeOf(element) {
        return element.closest('form') || document;
    }

    function countryOf(element) {
        var select = scopeOf(element).querySelector('select[name="country"]') || document.querySelector('select[name="country"]');

        return select ? select.value : '';
    }

    function labelsFor(field) {
        return field.id ? Array.prototype.slice.call(document.querySelectorAll('label[for="' + field.id + '"]')) : [];
    }

    // The element that shows the field name: a label with text, or the
    // label cell of the admin area tables.
    function captionOf(field) {
        var labels = labelsFor(field).filter(function (label) {
            return label.children.length === 0 && label.textContent.trim() !== '';
        });
        if (labels.length > 0) {
            return labels[0];
        }
        var cell = field.closest('td');
        if (cell && cell.previousElementSibling && cell.previousElementSibling.classList.contains('fieldlabel')) {
            return cell.previousElementSibling;
        }

        return null;
    }

    function describe(field, id, linked) {
        var ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (value) {
            return value !== '' && value !== id;
        });
        if (linked) {
            ids.push(id);
        }
        if (ids.length > 0) {
            field.setAttribute('aria-describedby', ids.join(' '));
        } else {
            field.removeAttribute('aria-describedby');
        }
    }

    function showHint(field, id, message) {
        var hint = document.getElementById(id);
        if (!hint) {
            hint = document.createElement('div');
            hint.id = id;
            hint.className = 'efactura-hint';
        }
        hint.textContent = message;
        field.insertAdjacentElement('afterend', hint);
        describe(field, id, true);
    }

    function hideHint(field, id) {
        var hint = document.getElementById(id);
        if (hint) {
            hint.parentNode.removeChild(hint);
        }
        if (field) {
            describe(field, id, false);
        }
    }

    // Counties

    function registerCounties() {
        if (!window.states || typeof window.states !== 'object') {
            return false;
        }
        window.states.RO = config.counties.concat(['end']);

        return true;
    }

    // Remembers the saved value and preselects its county when it is written
    // differently. StatesDropdown.js reads the input when it builds the dropdown.
    function prepareStateInputs() {
        document.querySelectorAll('input[name="state"]').forEach(function (input) {
            if (input.hasAttribute('data-efactura-original')) {
                return;
            }
            var country = countryOf(input);
            input.setAttribute('data-efactura-original', input.value);
            input.setAttribute('data-efactura-original-country', country);
            if (country === 'RO') {
                var name = canonical(input.value);
                if (name) {
                    input.value = name;
                }
            }
        });
    }

    // Sector instead of City for Bucharest

    function sectorHint(select, city) {
        if (!select.value && !select.disabled && city.value.trim() !== '') {
            showHint(select, SECTOR_HINT_ID, text.cd_hint_sector_missing);
        } else {
            hideHint(select, SECTOR_HINT_ID);
        }
    }

    function enableSector(city) {
        var select = document.getElementById(SECTOR_ID);
        if (select && select.efacturaCity === city) {
            return;
        }

        select = document.createElement('select');
        select.id = SECTOR_ID;
        select.className = city.className;
        select.classList.add('efactura-sector');
        select.efacturaCity = city;
        select.add(new Option(text.cd_sector_choose, ''));
        config.sectors.forEach(function (sector) {
            select.add(new Option(sector, sector));
        });

        var current = sectorOf(city.value);
        select.value = current || '';
        if (current) {
            city.value = current;
        }
        if (city.disabled || city.readOnly) {
            select.disabled = true;
        } else {
            select.required = true;
        }
        if (city.hasAttribute('tabindex')) {
            select.setAttribute('tabindex', city.getAttribute('tabindex'));
        }

        // Labels now name and point to the sector dropdown.
        select.efacturaLabels = [];
        var caption = captionOf(city);
        if (caption) {
            select.efacturaLabels.push({element: caption, text: caption.textContent});
            caption.textContent = text.cd_sector;
        } else {
            select.setAttribute('aria-label', text.cd_sector);
        }
        labelsFor(city).forEach(function (label) {
            select.efacturaLabels.push({element: label, htmlFor: label.htmlFor});
            label.htmlFor = SECTOR_ID;
        });

        // The City input stays in the form, hidden: it carries the value.
        city.setAttribute('data-efactura-required', city.required ? '1' : '0');
        city.required = false;
        city.classList.add('efactura-hidden');
        city.insertAdjacentElement('afterend', select);

        select.addEventListener('change', function () {
            city.value = select.value;
            sectorHint(select, city);
        });
        sectorHint(select, city);
    }

    function disableSector(city) {
        var select = document.getElementById(SECTOR_ID);
        if (!select || select.efacturaCity !== city) {
            return;
        }
        hideHint(select, SECTOR_HINT_ID);
        (select.efacturaLabels || []).forEach(function (saved) {
            if (saved.text !== undefined) {
                saved.element.textContent = saved.text;
            }
            if (saved.htmlFor !== undefined) {
                saved.element.htmlFor = saved.htmlFor;
            }
        });
        select.parentNode.removeChild(select);
        city.classList.remove('efactura-hidden');
        city.required = city.getAttribute('data-efactura-required') === '1';
        // A sector is not a city outside Bucharest.
        if (/^Sector [1-6]$/.test(city.value)) {
            city.value = '';
        }
    }

    function toggleSector(anchor, bucharest) {
        var city = scopeOf(anchor).querySelector('input[name="city"]');
        if (!city) {
            return;
        }
        if (bucharest) {
            enableSector(city);
        } else {
            disableSector(city);
        }
    }

    // Runs after StatesDropdown.js renders the State/Region field (it fires
    // "state:rendered" on the country dropdown) and when the county changes.
    function update() {
        var select = document.getElementById('stateselect');
        var input = document.getElementById('stateinput');
        var anchor = select || input;
        if (!anchor) {
            return;
        }
        if (!select || countryOf(anchor) !== 'RO') {
            hideHint(select, STATE_HINT_ID);
            toggleSector(anchor, false);

            return;
        }

        var original = input ? (input.getAttribute('data-efactura-original') || '').trim() : '';
        var originalCountry = input ? input.getAttribute('data-efactura-original-country') : '';
        if (!select.value && original !== '' && originalCountry === 'RO' && !canonical(original)) {
            showHint(select, STATE_HINT_ID, format(text.cd_hint_state_invalid, original));
        } else {
            hideHint(select, STATE_HINT_ID);
        }
        toggleSector(select, select.value === config.bucharest);
    }

    // Identifiers, the same rules as Romania\Cui, Romania\Cnp and ClientData\RegCom.

    function cuiDigits(value) {
        var cui = String(value || '').toUpperCase().replace(/[\s.\-]+/g, '');

        return cui.indexOf('RO') === 0 ? cui.slice(2) : cui;
    }

    function isCui(value) {
        var cui = cuiDigits(value);
        if (!/^[1-9]\d{1,9}$/.test(cui)) {
            return false;
        }
        var body = ('000000000' + cui.slice(0, -1)).slice(-9);
        var key = '753217532';
        var sum = 0;
        for (var i = 0; i < 9; i++) {
            sum += Number(body[i]) * Number(key[i]);
        }
        var check = (sum * 10) % 11;

        return (check === 10 ? 0 : check) === Number(cui.slice(-1));
    }

    function isCnp(value) {
        var cnp = String(value || '').replace(/\s+/g, '');
        if (!/^[1-9]\d{12}$/.test(cnp)) {
            return false;
        }
        var key = '279146358279';
        var sum = 0;
        for (var i = 0; i < 12; i++) {
            sum += Number(cnp[i]) * Number(key[i]);
        }
        var check = sum % 11;

        return (check === 10 ? 1 : check) === Number(cnp[12]);
    }

    function isOldCounty(county) {
        return (county >= 1 && county <= 40) || county === 51 || county === 52;
    }

    function isRegCom(value) {
        var number = String(value || '').toUpperCase().replace(/\s+/g, '');
        var year;
        var match = /^([JFC])(\d{4})(\d{6})(\d{2})(\d)$/.exec(number);
        if (match) {
            year = Number(match[2]);
            var county = Number(match[4]);
            if (year < 1990 || year > config.year) {
                return false;
            }
            if (year < 2024 ? !isOldCounty(county) : (year === 2024 ? county !== 0 && !isOldCounty(county) : county !== 0)) {
                return false;
            }
            var sum = match[1].charCodeAt(0) % 10;
            for (var i = 1; i <= 12; i++) {
                sum += Number(number[i]);
            }

            return sum % 10 === Number(match[5]);
        }
        match = /^([JFC])(\d{1,2})\/(\d{1,6})\/(?:\d{1,2}\.\d{1,2}\.)?(\d{4})$/.exec(number);
        if (!match) {
            return false;
        }
        year = Number(match[4]);

        return isOldCounty(Number(match[2])) && Number(match[3]) > 0 && year >= 1990 && year <= Math.min(config.year, 2024);
    }

    // Billing details section

    var PERSON = 'person';
    var COMPANY = 'company';
    var identity = null;

    function element(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = content;
        }

        return node;
    }

    function toggle(node, visible) {
        node.classList.toggle('efactura-hidden', !visible);
    }

    // Every change to the theme markup is recorded, so leaving Romania puts
    // the form back exactly as WHMCS rendered it.
    function Changes() {
        this.undo = [];
    }
    Changes.prototype.set = function (node, property, value) {
        var previous = node[property];
        this.undo.push(function () {
            node[property] = previous;
        });
        node[property] = value;
    };
    Changes.prototype.attr = function (node, name, value) {
        var had = node.hasAttribute(name);
        var previous = node.getAttribute(name);
        this.undo.push(function () {
            if (had) {
                node.setAttribute(name, previous);
            } else {
                node.removeAttribute(name);
            }
        });
        node.setAttribute(name, value);
    };
    Changes.prototype.addClass = function (node, className) {
        if (node.classList.contains(className)) {
            return;
        }
        node.classList.add(className);
        this.undo.push(function () {
            node.classList.remove(className);
        });
    };
    Changes.prototype.place = function (node, parent, before) {
        var placeholder = null;
        if (node.parentNode) {
            placeholder = document.createComment('efactura');
            node.parentNode.insertBefore(placeholder, node);
        }
        parent.insertBefore(node, before || null);
        this.undo.push(function () {
            if (placeholder) {
                placeholder.parentNode.insertBefore(node, placeholder);
                placeholder.parentNode.removeChild(placeholder);
            } else if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        });
    };
    Changes.prototype.detach = function (node) {
        var placeholder = document.createComment('efactura');
        node.parentNode.replaceChild(placeholder, node);
        this.undo.push(function () {
            placeholder.parentNode.replaceChild(node, placeholder);
        });
    };
    Changes.prototype.revert = function () {
        while (this.undo.length > 0) {
            this.undo.pop()();
        }
    };

    function groupOf(input) {
        return input.closest('.form-group') || input.parentElement;
    }

    function customInput(scope, role) {
        var id = config.fields && config.fields[role];

        return id ? scope.querySelector('[name="customfield[' + id + ']"]') : null;
    }

    // Moves a field group into the section; a grid column left empty is hidden.
    function adopt(changes, group, target) {
        var column = group.parentElement;
        changes.place(group, target);
        if (column && /(^|\s)col-/.test(column.className) && column.querySelector('input, select, textarea, label') === null) {
            changes.addClass(column, 'efactura-hidden');
        }
    }

    // The label of a field becomes ours; theme help texts written for the admin are hidden.
    // iconStyle: forms whose fields show an icon and a placeholder instead of a
    // visible label (twenty-one, nexus and standard_cart registration and checkout).
    function relabel(changes, input, caption, options) {
        options = options || {};
        var group = groupOf(input);
        var label = labelsFor(input).filter(function (node) {
            return node.textContent.trim() !== '' && !node.classList.contains('sr-only');
        })[0];
        var full = options.optional ? caption + ' (' + text.cd_optional + ')' : caption;
        if (label && label.children.length === 0) {
            changes.set(label, 'textContent', full);
            if (options.required) {
                changes.addClass(label, 'label-required');
            }
        }
        if (options.iconStyle) {
            if (label) {
                changes.addClass(label, 'sr-only');
            }
            changes.attr(input, 'placeholder', full);
            if (options.icon && !group.classList.contains('prepend-icon')) {
                changes.addClass(group, 'prepend-icon');
                changes.addClass(input, 'field');
                var iconLabel = element('label', 'field-icon');
                iconLabel.htmlFor = input.id;
                iconLabel.appendChild(element('i', options.icon));
                changes.place(iconLabel, group, group.firstChild);
            }
        } else if (!label || label.children.length > 0 || input.getAttribute('placeholder')) {
            changes.attr(input, 'placeholder', options.example && label ? options.example : full);
        } else if (options.example) {
            changes.attr(input, 'placeholder', options.example);
        }
        if (!label) {
            changes.attr(input, 'aria-label', full);
        }
        Array.prototype.slice.call(group.querySelectorAll('.help-block, .field-help-text, .form-text')).forEach(function (help) {
            changes.addClass(help, 'efactura-hidden');
        });
        Array.prototype.slice.call(input.parentNode.childNodes).forEach(function (node) {
            if (node.nodeType === 3 && node.textContent.trim() !== '') {
                changes.detach(node);
            }
        });
        if (options.help) {
            var help = element('span', 'help-block form-text text-muted efactura-help', options.help);
            help.id = (input.id || input.name.replace(/\W+/g, '')) + 'EfacturaHelp';
            changes.place(help, input.parentNode, input.nextSibling);
            changes.attr(input, 'aria-describedby', help.id);
        }
    }

    function stash(input) {
        if (input && input.value !== '') {
            input.setAttribute('data-efactura-stash', input.value);
            input.value = '';
        }
    }

    function unstash(input) {
        if (input && input.value === '' && input.hasAttribute('data-efactura-stash')) {
            input.value = input.getAttribute('data-efactura-stash');
        }
        if (input) {
            input.removeAttribute('data-efactura-stash');
        }
    }

    function fieldError(input, message, blocking) {
        if (!input) {
            return;
        }
        var id = (input.id || input.name.replace(/\W+/g, '')) + 'EfacturaError';
        // Below the input, or below the row it shares with the ANAF button.
        var anchor = input.parentNode.classList.contains('efactura-lookup-row') ? input.parentNode : input;
        if (message) {
            var hint = document.getElementById(id) || element('div', 'efactura-error');
            hint.id = id;
            hint.textContent = message;
            if (hint.previousSibling !== anchor) {
                anchor.parentNode.insertBefore(hint, anchor.nextSibling);
            }
            describe(input, id, true);
            input.setAttribute('aria-invalid', 'true');
        } else {
            hideHint(input, id);
            input.removeAttribute('aria-invalid');
        }
        input.setCustomValidity(message && blocking ? message : '');
    }

    function inferType(state) {
        var company = state.company.value.trim();
        var tax = state.tax ? state.tax.value.trim() : '';
        var cui = state.cui.value.trim();
        if (company !== '' || tax !== '') {
            return COMPANY;
        }

        return cui === '' || isCnp(cui) ? PERSON : COMPANY;
    }

    // showEmpty: also show "CUI required" next to the field (when the client
    // tries to submit); the browser gets the message in any case.
    function validate(state, showEmpty) {
        var company = state.type === COMPANY;
        var cui = state.cui.value.trim();
        if (company && cui === '') {
            // A company without CUI is refused even when the rest only warns.
            fieldError(state.cui, showEmpty ? text.cd_error_cui_required : '', true);
            state.cui.setCustomValidity(text.cd_error_cui_required);
        } else {
            fieldError(state.cui, company && !isCui(cui) ? text.cd_error_cui_invalid : '', config.strict);
        }
        if (state.regcom) {
            var regcom = state.regcom.value.trim();
            fieldError(state.regcom, company && regcom !== '' && !isRegCom(regcom) ? text.cd_error_regcom : '', config.strict);
        }
        if (state.cnp) {
            var cnp = state.cnp.value.replace(/\s+/g, '');
            fieldError(state.cnp, !company && cnp !== '' && cnp !== '0000000000000' && !isCnp(cnp) ? text.cd_error_cnp : '', config.strict);
        }
    }

    function syncVat(state) {
        if (!state.vatBox) {
            return;
        }
        var digits = cuiDigits(state.cui.value);
        var payer = state.type === COMPANY && state.vatBox.checked;
        if (!state.vatBox.disabled) {
            state.tax.value = payer && digits !== '' ? 'RO' + digits : '';
        }
        state.vatCode.textContent = payer && digits !== '' ? format(text.cd_vat_code, 'RO' + digits) : '';
    }

    function setType(state, type) {
        state.type = type;
        state.radios.forEach(function (radio) {
            radio.checked = radio.value === type;
            radio.closest('.efactura-type-option').classList.toggle('is-active', radio.checked);
        });
        toggle(state.companyPart, type === COMPANY);
        toggle(state.personPart, type === PERSON);

        // An individual's CNP typed into the CUI field (older data) moves to the CNP field.
        if (type === PERSON && state.cnp && state.cnp.value === '' && isCnp(state.cui.value.trim())) {
            state.cnp.value = state.cui.value.trim();
            state.cui.value = '';
        }
        // The fields of the hidden part are emptied, and refilled if the client switches back.
        [state.company, state.cui, state.regcom, state.tax].forEach(type === PERSON ? stash : unstash);
        (type === COMPANY ? stash : unstash)(state.cnp);
        if (state.vatBox) {
            state.vatBox.checked = type === COMPANY && state.tax.value.trim() !== '';
        }

        state.company.required = type === COMPANY;
        state.cui.required = type === COMPANY;
        validate(state, false);
        syncVat(state);
    }

    function masked(cnp) {
        return cnp.length === 13 ? cnp[0] + '••••••••' + cnp.slice(-4) : cnp;
    }

    // Read-only summary for the profile: the inputs stay in the form, hidden,
    // so the stored values are submitted unchanged.
    function buildSummary(state, section) {
        var type = inferType(state);
        var rows = [[text.cd_type_label, type === COMPANY ? text.cd_type_company : text.cd_type_person]];
        if (type === COMPANY) {
            rows.push([text.cd_company_name, state.company.value.trim() || text.cd_missing]);
            rows.push([text.cd_cui, cuiDigits(state.cui.value) || text.cd_missing]);
            if (state.regcom) {
                rows.push([text.cd_regcom, state.regcom.value.trim() || text.cd_missing]);
            }
            if (state.tax) {
                rows.push([text.cd_vat_number, state.tax.value.trim() || text.cd_no]);
            }
        } else {
            var cnp = state.cnp && state.cnp.value.trim() !== '' ? state.cnp.value.trim() : (isCnp(state.cui.value.trim()) ? state.cui.value.trim() : '');
            rows.push([text.cd_cnp, cnp ? masked(cnp) : text.cd_missing]);
        }

        var list = element('dl', 'efactura-summary');
        rows.forEach(function (row) {
            var item = element('div', 'efactura-summary-row');
            item.appendChild(element('dt', '', row[0]));
            item.appendChild(element('dd', '', row[1]));
            list.appendChild(item);
        });
        section.appendChild(list);

        var note = element('p', 'efactura-lock-note', text.cd_lock_note + ' ');
        var link = element('a', '', text.cd_lock_link);
        link.href = config.ticketUrl || 'submitticket.php';
        note.appendChild(link);
        section.appendChild(note);

        // A company can still refresh its (editable) address from ANAF.
        if (type === COMPANY && config.lookup && isCui(state.cui.value)) {
            state.lookup = createLookup(state.changes, {
                scope: scopeOf(state.cui),
                cui: null,
                cuiValue: function () {
                    return state.cui.value;
                },
                after: note,
                label: text.cd_lookup_address_button,
                identityLocked: true,
                lockedKeys: config.locked
            });
        }
    }

    function buildIdentity(scope) {
        var state = {
            company: scope.querySelector('input[name="companyname"]'),
            tax: scope.querySelector('input[name="tax_id"]'),
            cui: customInput(scope, 'cui'),
            regcom: customInput(scope, 'regcom'),
            cnp: customInput(scope, 'cnp'),
            changes: new Changes(),
            radios: []
        };
        if (!state.company || !state.cui) {
            return null;
        }
        var changes = state.changes;
        var anchor = groupOf(state.company);
        var section = element('div', 'efactura-billing');
        section.id = 'efacturaBilling';

        // In a grid row the section gets a full-width column of its own.
        var column = anchor.parentElement;
        if (column && /(^|\s)col-/.test(column.className) && column.children.length === 1) {
            var wide = element('div', 'col-12 col-xs-12 col-sm-12 col-md-12 efactura-billing-column');
            wide.appendChild(section);
            changes.place(wide, column.parentNode, column);
        } else {
            changes.place(section, anchor.parentNode, anchor);
        }

        var groups = [state.company, state.cui, state.regcom, state.cnp, state.tax].filter(Boolean).map(groupOf);
        if (config.lock) {
            buildSummary(state, section);
            groups.forEach(function (group) {
                var groupColumn = group.parentElement;
                changes.addClass(group, 'efactura-hidden');
                if (groupColumn && /(^|\s)col-/.test(groupColumn.className) && groupColumn.children.length === 1) {
                    changes.addClass(groupColumn, 'efactura-hidden');
                }
            });

            return state;
        }

        // Client type
        var fieldset = element('fieldset', 'efactura-type');
        fieldset.appendChild(element('legend', 'efactura-type-legend', text.cd_type_label));
        var options = element('div', 'efactura-type-options');
        [[PERSON, text.cd_type_person, text.cd_type_person_hint], [COMPANY, text.cd_type_company, text.cd_type_company_hint]].forEach(function (option) {
            var label = element('label', 'efactura-type-option');
            var radio = element('input', 'efactura-type-radio');
            radio.type = 'radio';
            radio.name = 'efactura_client_type';
            radio.value = option[0];
            var body = element('span', 'efactura-type-body');
            body.appendChild(element('span', 'efactura-type-title', option[1]));
            body.appendChild(element('span', 'efactura-type-hint', option[2]));
            label.appendChild(radio);
            label.appendChild(body);
            options.appendChild(label);
            state.radios.push(radio);
            radio.addEventListener('change', function () {
                if (radio.checked) {
                    setType(state, radio.value);
                }
            });
        });
        fieldset.appendChild(options);
        section.appendChild(fieldset);

        state.companyPart = element('div', 'efactura-grid efactura-company');
        state.personPart = element('div', 'efactura-grid efactura-person');
        section.appendChild(state.companyPart);
        section.appendChild(state.personPart);

        // Legal entity: CUI, company name, trade register number, VAT payer.
        var iconStyle = groupOf(state.company).classList.contains('prepend-icon');
        relabel(changes, state.cui, text.cd_cui, {required: true, example: text.cd_cui_placeholder, iconStyle: iconStyle, icon: 'fas fa-hashtag'});
        adopt(changes, groupOf(state.cui), state.companyPart);
        changes.attr(state.cui, 'autocomplete', 'off');
        changes.attr(state.cui, 'spellcheck', 'false');
        relabel(changes, state.company, text.cd_company_name, {required: true, iconStyle: iconStyle});
        adopt(changes, groupOf(state.company), state.companyPart);
        if (state.regcom) {
            relabel(changes, state.regcom, text.cd_regcom, {optional: true, example: text.cd_regcom_placeholder, iconStyle: iconStyle, icon: 'fas fa-file-alt'});
            adopt(changes, groupOf(state.regcom), state.companyPart);
            changes.attr(state.regcom, 'spellcheck', 'false');
        }
        if (state.tax) {
            adopt(changes, groupOf(state.tax), state.companyPart);
            changes.addClass(groupOf(state.tax), 'efactura-hidden');

            var vat = element('div', 'efactura-vat');
            var check = element('label', 'efactura-check');
            state.vatBox = element('input', 'efactura-check-input');
            state.vatBox.type = 'checkbox';
            state.vatBox.id = 'efacturaVatPayer';
            state.vatBox.checked = state.tax.value.trim() !== '';
            state.vatBox.disabled = state.tax.disabled || state.tax.readOnly || config.locked.indexOf('tax_id') !== -1;
            check.appendChild(state.vatBox);
            check.appendChild(element('span', '', text.cd_vat_payer));
            vat.appendChild(check);
            state.vatCode = element('span', 'efactura-vat-code');
            state.vatCode.setAttribute('aria-live', 'polite');
            vat.appendChild(state.vatCode);
            vat.appendChild(element('span', 'efactura-vat-hint', text.cd_vat_payer_hint));
            changes.place(vat, state.companyPart);
            state.vatBox.addEventListener('change', function () {
                syncVat(state);
            });
        }

        // Individual: optional CNP.
        if (state.cnp) {
            relabel(changes, state.cnp, text.cd_cnp, {optional: true, help: text.cd_cnp_help, iconStyle: iconStyle, icon: 'fas fa-id-card'});
            adopt(changes, groupOf(state.cnp), state.personPart);
            changes.attr(state.cnp, 'inputmode', 'numeric');
            changes.attr(state.cnp, 'autocomplete', 'off');
        }

        changes.set(state.company, 'required', state.company.required);
        changes.set(state.cui, 'required', state.cui.required);

        state.cui.addEventListener('input', function () {
            // "RO" in front of the CUI means a VAT payer.
            if (state.vatBox && !state.vatBox.disabled && /^\s*ro/i.test(state.cui.value)) {
                state.vatBox.checked = true;
            }
            syncVat(state);
            if (state.cui.getAttribute('aria-invalid')) {
                validate(state, false);
            }
        });
        state.cui.addEventListener('change', function () {
            var digits = cuiDigits(state.cui.value);
            if (/^\d+$/.test(digits)) {
                state.cui.value = digits;
            }
            syncVat(state);
            validate(state, false);
        });
        [state.regcom, state.cnp].forEach(function (input) {
            if (input) {
                input.addEventListener('change', function () {
                    validate(state, false);
                });
            }
        });
        // The browser blocks an invalid field before "submit"; forms with
        // novalidate reach the submit check instead.
        state.cui.addEventListener('invalid', function () {
            validate(state, true);
        });
        state.onSubmit = function (event) {
            validate(state, true);
            if (!state.cui.checkValidity()) {
                event.preventDefault();
                state.cui.focus();
            }
        };
        scope.addEventListener('submit', state.onSubmit, true);

        if (config.lookup && config.lookupUrl) {
            state.lookup = createLookup(changes, {
                scope: scope,
                cui: state.cui,
                cuiValue: function () {
                    return state.cui.value;
                },
                inline: true,
                statusAfter: groupOf(state.cui),
                vatBox: state.vatBox,
                tax: state.tax,
                identityLocked: false,
                lockedKeys: config.locked
            });
        }

        setType(state, config.type || inferType(state));

        return state;
    }

    // ANAF lookup: the server endpoint asks ANAF (cached, rate limited); empty
    // fields are filled in, fields the client already filled in are listed
    // with both values so the client chooses, and locked fields are not changed.

    function fold2(value) {
        return fold(value).replace(/[^a-z0-9]+/g, '');
    }

    function badge(textValue, kind) {
        return element('span', 'efactura-badge efactura-badge-' + kind, textValue);
    }

    function statusBadges(company) {
        var list = element('span', 'efactura-badges');
        list.appendChild(company.vatPayer ? badge(text.cd_status_vat, 'ok') : badge(text.cd_status_novat, 'muted'));
        if (company.vatOnCollection) {
            list.appendChild(badge(text.cd_status_vat_collection, 'muted'));
        }
        if (company.eInvoiceRegistry) {
            list.appendChild(badge(text.cd_status_einvoice, 'muted'));
        }
        if (company.inactive) {
            list.appendChild(badge(text.cd_status_inactive, 'warn'));
        }
        if (company.deregistered) {
            list.appendChild(badge(text.cd_status_deregistered, 'danger'));
        }

        return list;
    }

    function fire(input, eventName) {
        input.dispatchEvent(new Event(eventName, {bubbles: true}));
        if ($) {
            $(input).trigger(eventName);
        }
    }

    // The fields ANAF can fill in, in the order they are set (the county
    // before the city: in Bucharest the city becomes the sector dropdown).
    function lookupTargets(scope, lookup, company) {
        var byName = function (name) {
            return scope.querySelector('[name="' + name + '"]');
        };
        var bucharest = company.county === config.bucharest;
        var targets = [
            {key: 'companyname', label: text.cd_company_name, value: company.name, input: byName('companyname'), identity: true},
            {key: 'regcom', label: text.cd_regcom, value: company.regCom, input: customInput(scope, 'regcom'), identity: true},
            {key: 'address1', label: text.cd_field_address1, value: company.address1, input: byName('address1')},
            {key: 'address2', label: text.cd_field_address2, value: company.address2, input: byName('address2')},
            {key: 'state', label: text.cd_field_state, value: company.county, input: document.getElementById('stateselect') || byName('state')},
            {key: 'city', label: bucharest ? text.cd_sector : text.cd_field_city, value: company.city, input: byName('city')},
            {key: 'postcode', label: text.cd_field_postcode, value: company.postcode, input: byName('postcode')}
        ];
        if (lookup.vatBox) {
            targets.push({key: 'vat', label: text.cd_vat_number, value: company.vatPayer ? 'RO' + company.cui : '', vat: true, identity: true});
        } else if (byName('tax_id') && lookup.allowTaxId) {
            targets.push({key: 'tax_id', label: text.cd_vat_number, value: company.vatPayer ? 'RO' + company.cui : '', input: byName('tax_id'), identity: true, allowEmpty: true});
        }

        return targets.filter(function (target) {
            return (target.input || target.vat) && (target.value !== '' || target.allowEmpty);
        });
    }

    function currentValue(lookup, target) {
        if (target.vat) {
            return lookup.vatBox.checked ? lookup.tax.value : '';
        }

        return target.input.value.trim();
    }

    function isLocked(lookup, target) {
        if (lookup.lockedKeys.indexOf(target.key) !== -1 || (target.identity && lookup.identityLocked)) {
            return true;
        }
        if (target.vat) {
            return lookup.vatBox.disabled;
        }

        return target.input.disabled || target.input.readOnly;
    }

    function setValue(lookup, target, value) {
        if (target.vat) {
            lookup.vatBox.checked = value !== '';
            fire(lookup.vatBox, 'change');

            return;
        }
        var input = target.input;
        if (target.key === 'state' && input.tagName === 'SELECT') {
            input.value = value;
            fire(input, 'change');

            return;
        }
        if (target.key === 'city') {
            var sector = document.getElementById(SECTOR_ID);
            if (sector && sector.efacturaCity === input) {
                sector.value = value;
                fire(sector, 'change');

                return;
            }
        }
        input.value = value;
        fire(input, 'input');
        fire(input, 'change');
    }

    function clearPanel(lookup) {
        if (lookup.panel && lookup.panel.parentNode) {
            lookup.panel.parentNode.removeChild(lookup.panel);
        }
        lookup.panel = null;
    }

    function showStatus(lookup, message, kind, company) {
        lookup.status.textContent = '';
        lookup.status.className = 'efactura-lookup-status' + (kind ? ' efactura-lookup-' + kind : '');
        if (message) {
            lookup.status.appendChild(element('span', 'efactura-lookup-message', message));
        }
        if (company) {
            lookup.status.appendChild(statusBadges(company));
        }
    }

    function applyCompany(lookup, company) {
        clearPanel(lookup);
        if (lookup.cui && !lookup.identityLocked && lookup.cui.value.trim() !== company.cui) {
            lookup.cui.value = company.cui;
            fire(lookup.cui, 'change');
        }
        var conflicts = [];
        var changed = 0;
        lookupTargets(lookup.scope, lookup, company).forEach(function (target) {
            var now = currentValue(lookup, target);
            if (fold2(now) === fold2(target.value)) {
                return;
            }
            var locked = isLocked(lookup, target);
            if (now === '' && !locked) {
                setValue(lookup, target, target.value);
                changed++;
            } else {
                conflicts.push({target: target, now: now, locked: locked});
            }
        });

        var editable = conflicts.filter(function (conflict) {
            return !conflict.locked;
        });
        var message = changed > 0 ? text.cd_lookup_filled : (conflicts.length === 0 ? text.cd_lookup_same : '');
        showStatus(lookup, message, changed > 0 ? 'ok' : '', company);
        if (conflicts.length === 0) {
            return;
        }

        // Fields that already have a value: the client chooses.
        var panel = element('div', 'efactura-lookup-panel');
        panel.setAttribute('role', 'group');
        if (editable.length > 0) {
            panel.appendChild(element('p', 'efactura-lookup-panel-title', text.cd_lookup_review));
        }
        var list = element('ul', 'efactura-lookup-list');
        conflicts.forEach(function (conflict, index) {
            var item = element('li', 'efactura-lookup-item' + (conflict.locked ? ' is-locked' : ''));
            var head = element('label', 'efactura-lookup-item-head');
            if (!conflict.locked) {
                var box = element('input', 'efactura-check-input');
                box.type = 'checkbox';
                box.checked = true;
                box.id = 'efacturaLookupPick' + index;
                conflict.box = box;
                head.appendChild(box);
            }
            head.appendChild(element('span', 'efactura-lookup-field', conflict.target.label));
            item.appendChild(head);
            var values = element('div', 'efactura-lookup-values');
            values.appendChild(element('span', 'efactura-lookup-now', text.cd_lookup_now + ': ' + (conflict.now || '-')));
            values.appendChild(element('span', 'efactura-lookup-anaf', text.cd_lookup_anaf + ': ' + (conflict.target.value || '-')));
            if (conflict.locked) {
                values.appendChild(element('span', 'efactura-lookup-locked', text.cd_lookup_locked));
            }
            item.appendChild(values);
            list.appendChild(item);
        });
        panel.appendChild(list);
        if (editable.length > 0) {
            var actions = element('div', 'efactura-lookup-actions');
            var apply = element('button', 'btn btn-primary btn-sm', text.cd_lookup_apply);
            apply.type = 'button';
            var keep = element('button', 'btn btn-default btn-sm', text.cd_lookup_keep);
            keep.type = 'button';
            apply.addEventListener('click', function () {
                editable.forEach(function (conflict) {
                    if (conflict.box.checked) {
                        setValue(lookup, conflict.target, conflict.target.value);
                    }
                });
                clearPanel(lookup);
                showStatus(lookup, text.cd_lookup_filled, 'ok', company);
            });
            keep.addEventListener('click', function () {
                clearPanel(lookup);
            });
            actions.appendChild(apply);
            actions.appendChild(keep);
            panel.appendChild(actions);
        }
        lookup.status.parentNode.insertBefore(panel, lookup.status.nextSibling);
        lookup.panel = panel;
    }

    function runLookup(lookup) {
        var cui = cuiDigits(lookup.cuiValue());
        if (!isCui(cui)) {
            showStatus(lookup, text.cd_lookup_need_cui, 'error');
            if (lookup.cui) {
                lookup.cui.focus();
            }

            return;
        }
        clearPanel(lookup);
        lookup.button.disabled = true;
        lookup.button.setAttribute('aria-busy', 'true');
        showStatus(lookup, text.cd_lookup_busy, 'busy');
        var body = new URLSearchParams();
        body.append('token', config.token || '');
        body.append('cui', cui);
        body.append('scope', config.lookupScope || 'client');
        fetch(config.lookupUrl, {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (response) {
                return response.json().catch(function () {
                    return {ok: false, message: text.cd_lookup_error};
                });
            })
            .then(function (data) {
                if (data && data.ok && data.company) {
                    applyCompany(lookup, data.company);
                } else {
                    showStatus(lookup, (data && data.message) || text.cd_lookup_error, 'error');
                }
            })
            .catch(function () {
                showStatus(lookup, text.cd_lookup_error, 'error');
            })
            .then(function () {
                lookup.button.disabled = false;
                lookup.button.removeAttribute('aria-busy');
            });
    }

    // options: scope (form), cui (input or null), cuiValue(), after (node the
    // button row follows), label, vatBox/tax, identityLocked, lockedKeys, allowTaxId.
    function createLookup(changes, options) {
        var lookup = options;
        lookup.lockedKeys = lookup.lockedKeys || [];
        var row = element('div', 'efactura-lookup-row');
        lookup.button = element('button', 'btn btn-default efactura-lookup-button', options.label || text.cd_lookup_button);
        lookup.button.type = 'button';
        lookup.status = element('div', 'efactura-lookup-status');
        lookup.status.setAttribute('aria-live', 'polite');
        lookup.button.addEventListener('click', function () {
            runLookup(lookup);
        });
        if (options.inline && lookup.cui) {
            // The button next to the CUI input, in one row; the status (and the
            // review panel) below the row or below statusAfter.
            changes.place(row, lookup.cui.parentNode, lookup.cui);
            changes.place(lookup.cui, row);
            row.appendChild(lookup.button);
            var statusAfter = options.statusAfter || row;
            changes.place(lookup.status, statusAfter.parentNode, statusAfter.nextSibling);
        } else {
            row.appendChild(lookup.button);
            changes.place(row, options.after.parentNode, options.after.nextSibling);
            changes.place(lookup.status, row.parentNode, row.nextSibling);
        }

        return lookup;
    }

    // The admin client pages: the button next to the CUI custom field.
    function setupAdminLookup() {
        if (config.context !== 'admin' || !config.lookup) {
            return;
        }
        var cui = document.querySelector('[name="customfield[' + config.fields.cui + ']"]');
        if (!cui) {
            return;
        }
        var scope = scopeOf(cui);
        createLookup(new Changes(), {
            scope: scope,
            cui: cui,
            cuiValue: function () {
                return cui.value;
            },
            inline: true,
            allowTaxId: true,
            identityLocked: false
        });
    }

    function teardownIdentity() {
        if (!identity) {
            return;
        }
        var state = identity;
        identity = null;
        if (state.onSubmit) {
            state.cui.closest('form').removeEventListener('submit', state.onSubmit, true);
        }
        [state.cui, state.regcom, state.cnp].forEach(function (input) {
            fieldError(input, '', false);
        });
        [state.company, state.cui, state.regcom, state.tax, state.cnp].forEach(unstash);
        state.changes.revert();
    }

    // The CNP and the trade register number only exist for Romania.
    var foreignChanges = null;

    function updateIdentity() {
        if (!config.identity) {
            return;
        }
        var company = document.querySelector('input[name="companyname"]');
        if (!company) {
            return;
        }
        var scope = scopeOf(company);
        var romania = countryOf(company) === 'RO';
        if (romania) {
            if (foreignChanges) {
                foreignChanges.revert();
                foreignChanges = null;
                [customInput(scope, 'regcom'), customInput(scope, 'cnp')].forEach(unstash);
            }
            if (!identity) {
                identity = buildIdentity(scope);
            }

            return;
        }
        var wasRomania = identity !== null;
        teardownIdentity();
        if (!foreignChanges) {
            foreignChanges = new Changes();
            [customInput(scope, 'regcom'), customInput(scope, 'cnp')].forEach(function (input) {
                if (input) {
                    // Typed for Romania and then another country chosen: not submitted,
                    // refilled if Romania is chosen again. Stored values stay as they are.
                    if (wasRomania) {
                        stash(input);
                    }
                    var group = groupOf(input);
                    foreignChanges.addClass(group, 'efactura-hidden');
                    if (group.parentElement && /(^|\s)col-/.test(group.parentElement.className) && group.parentElement.children.length === 1) {
                        foreignChanges.addClass(group.parentElement, 'efactura-hidden');
                    }
                }
            });
        }
    }

    $(document).on('state:rendered', 'select[name="country"]', update);
    $(document).on('change', '#stateselect', update);
    $(document).on('change', 'select[name="country"]', updateIdentity);
    updateIdentity();
    setupAdminLookup();

    var registered = registerCounties();
    prepareStateInputs();
    if (registered && $.isReady && typeof window.statechange === 'function') {
        // Loaded after DOM ready: the dropdown was built without the counties.
        window.statechange();
    }
    $(function () {
        if (!registered && registerCounties() && typeof window.statechange === 'function') {
            registered = true;
            prepareStateInputs();
            window.statechange();
        }
        update();
    });
}(window, document));
