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

use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Http\Transport;

/**
 * A stand-in for the ANAF e-Factura API in tests, with the answer formats of
 * the documentation: upload returns an index, stareMesaj walks through the
 * states configured per upload, descarcare returns a ZIP with the invoice
 * and a signature (or the errors), and the paged list shows the messages.
 */
final class AnafSimulator implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var array<string, array{xml: string, endpoint: string, query: array<string, string>, final: string}> by index */
    public array $uploads = [];

    /**
     * How the next uploads are answered, in order (then "accept"): accept,
     * lost (received, but the answer times out), dropped (times out and never
     * arrived), not_sent, refuse, auth, technical.
     *
     * @var list<string>
     */
    public array $uploadModes = [];

    /**
     * stareMesaj answers per upload, in order of the uploads; the last one
     * repeats: "in prelucrare", "ok", "nok", "xml".
     *
     * @var list<list<string>>
     */
    public array $stateScripts = [];

    /** @var array<string, list<string>> by index: answers still to give */
    private array $states = [];

    /** @var array<string, list<string>> by index: errors of a rejected upload */
    public array $errors = [];

    /** @var list<string> descarcare answers, in order (then "zip"): zip, limit, expired */
    public array $downloadModes = [];

    private int $nextIndex = 5000000001;

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $endpoint = basename($path);

        return match ($endpoint) {
            'upload', 'uploadb2c' => $this->upload($request, $endpoint, $query),
            'stareMesaj' => $this->state((string) ($query['id_incarcare'] ?? '')),
            'descarcare' => $this->download((string) ($query['id'] ?? '')),
            'listaMesajePaginatieFactura' => $this->listMessages($query),
            default => new Response(404, [], '{"status":404,"error":"Not Found"}'),
        };
    }

    /**
     * @return list<string> the endpoints called, in order
     */
    public function calls(): array
    {
        return array_map(static fn (Request $request): string => basename((string) parse_url($request->url, PHP_URL_PATH)), $this->requests);
    }

    public static function downloadId(string $index): string
    {
        return (string) ((int) $index + 1000000000);
    }

    /**
     * @param array<string, string> $query
     */
    private function upload(Request $request, string $endpoint, array $query): Response
    {
        $mode = array_shift($this->uploadModes) ?? 'accept';
        $error = static fn (string $message): Response => self::xml('<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202610091200" ExecutionStatus="1"><Errors errorMessage="' . htmlspecialchars($message, ENT_QUOTES) . '"/></header>');
        switch ($mode) {
            case 'not_sent':
                return new Response(0, [], '', 7, 'Failed to connect to api.anaf.ro port 443', false);
            case 'refuse':
                return $error("Fisierul transmis nu este valid. org.xml.sax.SAXParseException; lineNumber: 2; columnNumber: 10; cvc-elt.1.a: Cannot find the declaration of element 'Invoice1'. ");
            case 'auth':
                return $error('Nu aveti drept in SPV pentru CIF=' . ($query['cif'] ?? ''));
            case 'technical':
                // As seen on the test environment on 2026-10-09: not registered.
                return $error('A aparut o eroare tehnica. Cod: 1814');
            case 'dropped':
                return new Response(0, [], '', 28, 'Operation timed out after 120000 milliseconds', true);
        }

        $index = (string) $this->nextIndex++;
        $script = array_shift($this->stateScripts) ?? ['in prelucrare', 'ok'];
        $this->states[$index] = $script;
        $this->uploads[$index] = ['xml' => $request->body, 'endpoint' => $endpoint, 'query' => $query, 'final' => end($script) ?: 'ok'];
        if ($mode === 'lost') {
            return new Response(0, [], '', 28, 'Operation timed out after 120000 milliseconds', true);
        }

        return self::xml('<header xmlns="mfp:anaf:dgti:spv:respUploadFisier:v1" dateResponse="202610091200" ExecutionStatus="0" index_incarcare="' . $index . '"/>');
    }

    private function state(string $index): Response
    {
        $head = '<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1"';
        if (!isset($this->uploads[$index])) {
            return self::xml($head . '><Errors errorMessage="Nu exista factura cu id_incarcare= ' . $index . '"/></header>');
        }
        $state = count($this->states[$index]) > 1 ? array_shift($this->states[$index]) : $this->states[$index][0];

        return match ($state) {
            'ok', 'nok' => self::xml($head . ' stare="' . $state . '" id_descarcare="' . self::downloadId($index) . '"/>'),
            'xml' => self::xml($head . ' stare="XML cu erori nepreluat de sistem"/>'),
            default => self::xml($head . ' stare="in prelucrare"/>'),
        };
    }

    private function download(string $id): Response
    {
        $mode = array_shift($this->downloadModes) ?? 'zip';
        $json = static fn (string $message): Response => new Response(200, ['content-type' => 'application/json'], json_encode(['eroare' => $message, 'titlu' => 'Descarcare mesaj']));
        if ($mode === 'limit') {
            return $json('S-au facut deja 10 descarcari la mesajul cu id_descarcare=' . $id . ' in cursul zilei');
        }
        if ($mode === 'expired') {
            return $json('Fisierul nu mai poate fi descarcat pentru ca a trecut perioada de 60 de zile in care este disponibil');
        }
        $index = (string) ((int) $id - 1000000000);
        if (!isset($this->uploads[$index])) {
            return $json('Pentru id=' . $id . ' nu exista inregistrata nici o factura');
        }

        $files = ['semnatura_' . $index . '.xml' => '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo/></Signature>'];
        if ($this->uploads[$index]['final'] === 'nok') {
            $errors = '';
            foreach ($this->errors[$index] ?? ['[BR-RO-110]-Daca Codul tarii cumparatorului (BT-55) este RO, atunci Subdiviziunea tarii (BT-54) trebuie codificata ISO 3166-2:RO.'] as $message) {
                $errors .= '<Error errorMessage="' . htmlspecialchars($message, ENT_QUOTES) . '"/>';
            }
            $files[$index . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><header xmlns="mfp:anaf:dgti:efactura:mesajEroriFactuta:v1" Index_incarcare="' . $index . '" Cif_emitent="12345674">' . $errors . '</header>';
        } else {
            $files[$index . '.xml'] = $this->uploads[$index]['xml'];
        }

        return new Response(200, ['content-type' => 'application/octet-stream'], self::zip($files));
    }

    /**
     * @param array<string, string> $query
     */
    private function listMessages(array $query): Response
    {
        $messages = [];
        foreach ($this->uploads as $index => $upload) {
            $type = $upload['final'] === 'nok' ? 'E' : 'T';
            if (isset($query['filtru']) && $query['filtru'] !== $type) {
                continue;
            }
            $messages[] = [
                'data_creare' => '202610091201',
                'cif' => '12345674',
                'id_solicitare' => (string) $index,
                'detalii' => $type === 'T'
                    ? 'Factura cu id_incarcare=' . $index . ' emisa de cif_emitent=12345674 pentru cif_beneficiar=87654329'
                    : 'Erori de validare identificate la factura primita cu id_incarcare=' . $index,
                'tip' => $type === 'T' ? 'FACTURA TRIMISA' : 'ERORI FACTURA',
                'id' => self::downloadId((string) $index),
            ];
        }
        if ($messages === []) {
            return new Response(200, [], json_encode(['eroare' => 'Nu exista mesaje in intervalul selectat', 'titlu' => 'Lista Mesaje']));
        }

        return new Response(200, ['content-type' => 'application/json'], json_encode([
            'mesaje' => $messages, 'numar_inregistrari_in_pagina' => count($messages), 'numar_total_inregistrari_per_pagina' => 500,
            'numar_total_inregistrari' => count($messages), 'numar_total_pagini' => 1, 'index_pagina_curenta' => 1, 'titlu' => 'Lista Mesaje',
        ]));
    }

    private static function xml(string $body): Response
    {
        return new Response(200, ['content-type' => 'application/xml'], '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $body);
    }

    /**
     * @param array<string, string> $files
     */
    private static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'efs');
        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        $archive->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
