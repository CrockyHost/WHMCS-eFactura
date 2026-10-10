/*
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

// Actions that cannot be undone ask first; a form is sent only once.
(function () {
    if (window.efacturaAdminLoaded) {
        return;
    }
    window.efacturaAdminLoaded = true;

    // The credit note panel is printed in the footer: move it next to the
    // status of the credit note (it stays at the bottom if that is not found).
    var panel = document.querySelector('[data-efactura-place="credit-note"]');
    var status = document.querySelector('#tab1 .invoice-status');
    var column = status && status.closest('[class*="col-"]');
    if (panel && column) {
        column.appendChild(panel);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.closest || !form.closest('.efactura')) {
            return;
        }
        var question = form.getAttribute('data-efactura-confirm');
        if (question && !window.confirm(question)) {
            event.preventDefault();
            return;
        }
        if (form.getAttribute('data-efactura-sent')) {
            event.preventDefault();
            return;
        }
        form.setAttribute('data-efactura-sent', '1');
        var buttons = form.querySelectorAll('button[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }
    });
})();
