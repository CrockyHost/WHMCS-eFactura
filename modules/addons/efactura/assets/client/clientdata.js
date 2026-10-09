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
 * - in Bucharest, replaces City with a sector dropdown that saves "Sector N".
 * Runs synchronously at the end of the page, before DOM ready, so the
 * dropdowns are in place when the page first shows.
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

    $(document).on('state:rendered', 'select[name="country"]', update);
    $(document).on('change', '#stateselect', update);

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
