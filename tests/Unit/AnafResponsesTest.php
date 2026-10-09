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

use WHMCS\Module\Addon\Efactura\Anaf\Outcome;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseParser;
use WHMCS\Module\Addon\Efactura\Anaf\ResponseZip;
use WHMCS\Module\Addon\Efactura\Http\Response;

/*
 * The answers are verbatim from the ANAF documentation and real cases
 * collected in research reports 02 and 04 (including ANAF's own typos).
 */

$xml = static fn (string $body, int $status = 200): Response => new Response($status, ['content-type' => 'application/xml'], $body);
$json = static fn (string $body, int $status = 200): Response => new Response($status, ['content-type' => 'application/json'], $body);
$transportFailure = static fn (bool $sent): Response => new Response(0, [], '', $sent ? 28 : 7, $sent ? 'Operation timed out after 120000 milliseconds' : 'Failed to connect to api.anaf.ro', $sent);

$zip = static function (array $files): string {
    $path = tempnam(sys_get_temp_dir(), 'efz');
    $archive = new ZipArchive();
    $archive->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $archive->addFromString($name, $content);
    }
    $archive->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
};

$uploadHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202108051144" ExecutionStatus="1">' . "\n" . '    <Errors errorMessage="%s"/>' . "\n" . '</header>';
$stateHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1">' . "\n" . '    <Errors errorMessage="%s"/>' . "\n" . '</header>';

return [
    'upload accepted' => static function () use ($xml): void {
        $outcome = ResponseParser::upload($xml('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202108051140" ExecutionStatus="0" index_incarcare="3828"/>'));
        Assert::same(Outcome::ACCEPTED, $outcome->kind);
        Assert::same('3828', $outcome->index);
    },
    'upload errors by family' => static function () use ($xml, $uploadHeader): void {
        $cases = [
            'Fisierul transmis nu este valid. org.xml.sax.SAXParseException; lineNumber: 15; columnNumber: 155; cvc-elt.1.a: Cannot find the declaration of element \'Invoice1\'. ' => Outcome::REFUSED,
            'Marime fisier transmis mai mare de 10 MB.' => Outcome::REFUSED,
            'CIF introdus= 123a nu este un numar' => Outcome::REFUSED,
            'Nu exista niciun CIF pentru care sa aveti drept in SPV' => Outcome::AUTH,
            'Nu aveti drept in SPV pentru CIF=1234' => Outcome::AUTH,
            'A aparut o eroare tehnica. Cod: XXX' => Outcome::UNKNOWN,
            'S-au incarcat deja 1000 de mesaje de tip RASP pentru cui=1234 in cursul zilei' => Outcome::LIMIT,
        ];
        foreach ($cases as $message => $kind) {
            $outcome = ResponseParser::upload($xml(sprintf($uploadHeader, htmlspecialchars($message, ENT_QUOTES))));
            Assert::same($kind, $outcome->kind, $message);
            Assert::same(trim($message), $outcome->message);
        }
    },
    'upload transport failures: unknown once the request may have left' => static function () use ($transportFailure, $json): void {
        Assert::same(Outcome::RETRY, ResponseParser::upload($transportFailure(false))->kind);
        Assert::same(Outcome::UNKNOWN, ResponseParser::upload($transportFailure(true))->kind);
        Assert::same(Outcome::UNKNOWN, ResponseParser::upload(new Response(502, [], 'Bad Gateway'))->kind);
        Assert::same(Outcome::UNKNOWN, ResponseParser::upload(new Response(200, [], ''))->kind);
        Assert::same(Outcome::UNKNOWN, ResponseParser::upload(new Response(200, ['content-type' => 'text/html'], '<html><body>The requested URL was rejected. Please consult with your administrator.</body></html>'))->kind);
        Assert::same(Outcome::RETRY, ResponseParser::upload(new Response(429, [], ''))->kind);
        Assert::same(Outcome::AUTH, ResponseParser::upload(new Response(403, [], ''))->kind);
        $bad = ResponseParser::upload($json('{"timestamp":"05-08-2021 12:04:01","status":400,"error":"Bad Request","message":"Parametrii standard si cif sunt obligatorii"}', 400));
        Assert::same(Outcome::REFUSED, $bad->kind);
        Assert::same('Parametrii standard si cif sunt obligatorii', $bad->message);
    },
    'stareMesaj states' => static function () use ($xml): void {
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1" ';
        $ok = ResponseParser::state($xml($head . 'stare="ok" id_descarcare="1234"/>'));
        Assert::same([Outcome::OK, '1234'], [$ok->kind, $ok->downloadId]);
        $nok = ResponseParser::state($xml($head . 'stare="nok" id_descarcare="123"/>'));
        Assert::same([Outcome::NOK, '123'], [$nok->kind, $nok->downloadId]);
        Assert::same(Outcome::PROCESSING, ResponseParser::state($xml($head . 'stare="in prelucrare"/>'))->kind);
        Assert::same(Outcome::PROCESSING, ResponseParser::state($xml($head . 'stare="In prelucrare"/>'))->kind);
        Assert::same(Outcome::XML_ERRORS, ResponseParser::state($xml($head . 'stare="XML cu erori nepreluat de sistem"/>'))->kind);
    },
    'stareMesaj errors' => static function () use ($xml, $stateHeader, $transportFailure): void {
        $cases = [
            'Nu exista factura cu id_incarcare= 15000' => Outcome::FATAL,
            'Nu aveti dreptul de inteorgare pentru id_incarcare= 18' => Outcome::AUTH,
            'Nu exista niciun CIF petru care sa aveti drept' => Outcome::AUTH,
            'S-au facut deja 20 descarcari de mesaj in cursul zilei' => Outcome::LIMIT,
            'A aparut o eroare tehnica. Cod: 4003' => Outcome::RETRY,
        ];
        foreach ($cases as $message => $kind) {
            Assert::same($kind, ResponseParser::state($xml(sprintf($stateHeader, $message)))->kind, $message);
        }
        Assert::same(Outcome::RETRY, ResponseParser::state($transportFailure(true))->kind);
        Assert::same(Outcome::RETRY, ResponseParser::state(new Response(503, [], ''))->kind);
    },
    'descarcare answers' => static function () use ($json): void {
        Assert::same(Outcome::ZIP, ResponseParser::download(new Response(200, ['content-type' => 'application/octet-stream'], "PK\x03\x04rest"))->kind);
        $cases = [
            '{"eroare":"S-au facut deja 10 descarcari de mesaj in cursul zilei","titlu":"Descarcare mesaj"}' => Outcome::LIMIT,
            '{"eroare":"S-au facut deja 10 descarcari la mesajul cu id_descarcare=123 in cursul zilei","titlu":"Descarcare mesaj"}' => Outcome::LIMIT,
            '{"eroare":"Fisierul nu mai poate fi descarcat pentru ca a trecut perioada de 60 de zile in care este disponibil","titlu":"Descarcare mesaj"}' => Outcome::EXPIRED,
            '{"eroare":"Pentru id=21 nu exista inregistrata nici o factura","titlu":"Descarcare mesaj"}' => Outcome::FATAL,
            '{"eroare":"Nu aveti dreptul sa descarcati acesta factura","titlu":"Descarcare mesaj"}' => Outcome::AUTH,
        ];
        foreach ($cases as $body => $kind) {
            Assert::same($kind, ResponseParser::download($json($body))->kind, $body);
        }
        // The Swagger example has an extra brace: not JSON, retried.
        Assert::same(Outcome::RETRY, ResponseParser::download($json('{"eroare":"S-au facut deja 10 descarcari de mesaj in cursul zilei","titlu":"Descarcare mesaj"}}'))->kind);
    },
    'message lists' => static function () use ($json): void {
        $list = ResponseParser::messages($json('{"mesaje":[{"data_creare":"202211011336","cif":"8000000000","id_solicitare":"5001131297","detalii":"Factura cu id_incarcare=5001131297 emisa de cif_emitent=8000000000 pentru cif_beneficiar=3","tip":"FACTURA TRIMISA","id":"3001503294"}],"numar_total_pagini":3,"serial":"1234AA456","cui":"8000000000","titlu":"Lista Mesaje"}'));
        Assert::same(Outcome::MESSAGES, $list->kind);
        Assert::same('5001131297', $list->messages[0]['id_solicitare']);
        Assert::same(3, $list->pages);
        Assert::same(Outcome::EMPTY, ResponseParser::messages($json('{"eroare":"Nu exista mesaje in intervalul selectat","titlu":"Lista Mesaje"}'))->kind);
        Assert::same(Outcome::AUTH, ResponseParser::messages($json('{"eroare":"Nu aveti drept in SPV pentru CIF=8000000000","titlu":"Lista Mesaje"}'))->kind);
        Assert::same(Outcome::LIMIT, ResponseParser::messages($json('{"eroare":"S-au facut deja 1000 interogari de lista mesaje de catre utilizator in cursul zilei","titlu":"Lista Mesaje"}'))->kind);
        Assert::same(Outcome::FATAL, ResponseParser::messages($json('{"eroare":"endTime = 02-12-2022 11:49:24 nu poate in viitor fata de momentul requestului","titlu":"Lista Mesaje"}'))->kind);
    },
    'ZIP with the validated invoice and the signature' => static function () use ($zip): void {
        $invoice = file_get_contents(dirname(__DIR__) . '/fixtures/ubl/b2b-ron-paid.xml');
        $read = ResponseZip::read($zip(['4027474196.xml' => $invoice, 'semnatura_4027474196.xml' => '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/>']));
        Assert::true($read->isInvoice());
        Assert::same('4027474196', $read->index);
        Assert::same(['number' => 'FX-0001', 'date' => '2026-10-05', 'seller' => 'RO12345674', 'total' => '119.67', 'sha256' => hash('sha256', $invoice)], $read->invoiceKey());
        Assert::true(str_contains((string) $read->signatureXml, 'xmldsig'));
    },
    'ZIP with errors, including a duplicate' => static function () use ($zip): void {
        $errors = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<header xmlns="mfp:anaf:dgti:efactura:mesajEroriFactuta:v1" Index_incarcare="5025079551" Cif_emitent="35913862">'
            . '<Error errorMessage="[BR-RO-065]-Identificatorul de înregistrare fiscala a Vanzatorului (BT-32) trebuie sa fie înscris.&#xA;&#x9;&#x9;#The Seller tax registration identifier (BT-32) shall be present."/>'
            . '<Error errorMessage="Factura a mai fost transmisa anterior cu index=0123456789 si data incarcare=2026-01-20" /></header>';
        $read = ResponseZip::read($zip(['5025079551.xml' => $errors, 'semnatura_5025079551.xml' => '<Signature/>']));
        Assert::false($read->isInvoice());
        Assert::same(2, count($read->errors));
        Assert::true(str_contains($read->errors[0], 'trebuie sa fie înscris. #The Seller'), 'whitespace normalized');
        Assert::same(['0123456789', '2026-01-20'], [$read->duplicateIndex, $read->duplicateDate]);
    },
];
