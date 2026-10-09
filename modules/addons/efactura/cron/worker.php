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

declare(strict_types=1);

/*
 * Runs the e-Factura queue once, from the command line. The WHMCS cron
 * already runs it after every run (AfterCronJob); this script is for a
 * separate, more frequent cron entry or for support:
 *
 *   php modules/addons/efactura/cron/worker.php [--budget=50] [--document=ID ...]
 *
 * --budget    seconds for this run (default 50)
 * --document  process only this document (mod_efactura_documents.id); repeatable
 *
 * Prints what was done as JSON. Never reachable over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['budget:', 'document:']);
$budget = max(5, (int) ($options['budget'] ?? 50));
$only = isset($options['document']) ? array_map('intval', (array) $options['document']) : null;

require dirname(__DIR__, 4) . '/init.php';
require_once dirname(__DIR__) . '/bootstrap.php';

use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Settings\Settings;
use WHMCS\Module\Addon\Efactura\Support\Lang;

Lang::boot(Settings::string('ui_language'));
echo json_encode(Addon::worker()->run($budget, $only)) . "\n";
