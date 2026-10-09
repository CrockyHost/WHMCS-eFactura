{*
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 *}
<!doctype html>
<html lang="{$language}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{$title}</title>
    <style>
        body { margin: 0; background: #f3f4f6; color: #1f2937; font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        main { max-width: 560px; margin: 12vh auto 24px; padding: 28px 32px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
        h1 { margin: 0 0 12px; font-size: 20px; }
        .status { display: inline-block; margin-bottom: 14px; padding: 2px 10px; border-radius: 12px; font-size: 13px; font-weight: 600; }
        .ok { background: #dcfce7; color: #166534; }
        .fail { background: #fee2e2; color: #991b1b; }
        footer { max-width: 560px; margin: 0 auto; padding: 0 32px; color: #6b7280; font-size: 12px; }
        footer a { color: inherit; }
    </style>
</head>
<body>
    <main>
        <h1>{$title}</h1>
        {if $success}
            <span class="status ok">OK</span>
        {else}
            <span class="status fail">!</span>
        {/if}
        <p>{$message}</p>
    </main>
    <footer>{$attributionHtml nofilter}</footer>
</body>
</html>
