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
 * Checks the test fixtures with the public ANAF validator
 * (webservicesp.anaf.ro, no authentication, validation only: nothing is
 * filed):
 *
 *   php tests/anaf-validate.php
 *
 * Only tests/fixtures/ubl/*.xml is sent, and a file is refused unless every
 * identifier in it is one of the fictive values of tests/UblScenarios.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const VALIDATOR = 'https://webservicesp.anaf.ro/prod/FCTEL/rest/validare/FACT1';
// Fictive identifiers used by the scenarios: anything else is not sent.
const FICTIVE_IDS = ['RO12345674', '12345674', 'J40/1234/2020', 'RO87654329', '87654329', '0000000000000', '12-3456789', 'DE123456789'];
const FICTIVE_IBANS = ['RO49AAAA1B31007593840000', 'RO66BACX0000001234567890'];

$files = glob(__DIR__ . '/fixtures/ubl/*.xml') ?: [];
if ($files === []) {
    exit("No fixtures found.\n");
}

$failures = 0;
$foreign = 0;
foreach ($files as $file) {
    $xml = (string) file_get_contents($file);
    $document = new DOMDocument();
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $ids = [];
    foreach ($xpath->query("//*[local-name()='CompanyID'] | //*[local-name()='PayeeFinancialAccount']/*[local-name()='ID']") as $node) {
        $ids[] = trim($node->textContent);
    }
    $unknown = array_diff($ids, FICTIVE_IDS, FICTIVE_IBANS);
    $sellerVat = trim((string) $xpath->evaluate("string(//*[local-name()='AccountingSupplierParty']//*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID'])"));
    if ($unknown !== [] || $sellerVat !== 'RO12345674') {
        echo basename($file), ": REFUSED, not fictive data (" . implode(', ', $unknown) . ")\n";
        $failures++;
        continue;
    }

    $curl = curl_init(VALIDATOR);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $json = is_string($body) ? json_decode($body, true) : null;
    $state = is_array($json) ? (string) ($json['stare'] ?? '?') : 'no JSON';
    echo str_pad(basename($file), 42), " HTTP {$status} stare={$state}", is_array($json) && isset($json['trace_id']) ? " trace_id={$json['trace_id']}" : '', "\n";

    // A buyer outside Romania is identified only with extern=DA, an upload
    // parameter the validator does not take: ERRIdentif alone is expected.
    $buyerCountry = trim((string) $xpath->evaluate("string(//*[local-name()='AccountingCustomerParty']//*[local-name()='IdentificationCode'])"));
    $messages = is_array($json) ? array_map(static fn ($m): string => is_array($m) ? (string) ($m['message'] ?? '') : (string) $m, (array) ($json['Messages'] ?? [])) : [];
    if ($state === 'nok' && $buyerCountry !== 'RO' && $messages !== []
        && array_filter($messages, static fn (string $m): bool => !str_contains($m, 'ERRIdentif')) === []) {
        echo "    expected: foreign buyer, identified by extern=DA at upload\n";
        $foreign++;
        sleep(1);
        continue;
    }
    if ($state !== 'ok') {
        $failures++;
        if (is_array($json)) {
            foreach ((array) ($json['Messages'] ?? []) as $message) {
                echo '    ', is_array($message) ? ($message['message'] ?? json_encode($message)) : $message, "\n";
            }
        } else {
            echo '    ', $error !== '' ? $error : mb_substr(trim(strip_tags((string) $body)), 0, 300), "\n";
        }
    }
    sleep(1);
}

printf("%d accepted, %d foreign buyer(s) (extern=DA at upload), %d not accepted.\n", count($files) - $failures - $foreign, $foreign, $failures);
exit($failures === 0 ? 0 : 1);
