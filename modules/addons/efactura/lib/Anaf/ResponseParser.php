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

namespace WHMCS\Module\Addon\Efactura\Anaf;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WHMCS\Module\Addon\Efactura\Http\Response;
use WHMCS\Module\Addon\Efactura\Romania\Text;

/**
 * Classifies ANAF answers by their content (research report 04): errors come
 * with HTTP 200 in XML (Errors/@errorMessage) or JSON (eroare), sometimes as
 * an HTML firewall page or an empty body. XML is read by local names, so the
 * namespace variants do not matter.
 */
final class ResponseParser
{
    public static function upload(Response $response): Outcome
    {
        if ($response->failed()) {
            // Only a request that never left this server is safe to repeat.
            return new Outcome($response->requestSent ? Outcome::UNKNOWN : Outcome::RETRY, $response->error);
        }
        if ($response->status === 429) {
            return new Outcome(Outcome::RETRY, 'HTTP 429');
        }
        if (in_array($response->status, [401, 403], true)) {
            return new Outcome(Outcome::AUTH, 'HTTP ' . $response->status);
        }
        if ($response->status === 400) {
            return new Outcome(Outcome::REFUSED, self::jsonMessage($response) ?: 'HTTP 400');
        }
        if ($response->status !== 200) {
            return new Outcome(Outcome::UNKNOWN, 'HTTP ' . $response->status);
        }

        $header = self::xmlHeader($response->body);
        if ($header === null) {
            return new Outcome(Outcome::UNKNOWN, self::excerpt($response->body));
        }
        $index = $header->getAttribute('index_incarcare');
        if ($header->getAttribute('ExecutionStatus') === '0' && $index !== '') {
            return new Outcome(Outcome::ACCEPTED, '', $index);
        }
        $message = implode(' ', self::errorMessages($header));
        $kind = self::classify($message) ?? Outcome::REFUSED;

        return new Outcome($kind === Outcome::RETRY ? Outcome::UNKNOWN : $kind, $message);
    }

    public static function state(Response $response): Outcome
    {
        if ($response->failed() || $response->status === 429 || $response->status >= 500) {
            return new Outcome(Outcome::RETRY, $response->failed() ? $response->error : 'HTTP ' . $response->status);
        }
        if (in_array($response->status, [401, 403], true)) {
            return new Outcome(Outcome::AUTH, 'HTTP ' . $response->status);
        }
        $header = self::xmlHeader($response->body);
        if ($header === null) {
            return new Outcome($response->status === 400 ? Outcome::FATAL : Outcome::RETRY, self::jsonMessage($response) ?: self::excerpt($response->body));
        }

        $state = Text::fold($header->getAttribute('stare'));
        $downloadId = $header->getAttribute('id_descarcare') ?: null;
        return match (true) {
            $state === 'ok' => new Outcome(Outcome::OK, '', null, $downloadId),
            $state === 'nok' => new Outcome(Outcome::NOK, '', null, $downloadId),
            $state === 'in prelucrare' => new Outcome(Outcome::PROCESSING),
            str_starts_with($state, 'xml cu erori') => new Outcome(Outcome::XML_ERRORS, $header->getAttribute('stare')),
            default => self::stateError($header),
        };
    }

    public static function download(Response $response): Outcome
    {
        if ($response->failed() || $response->status === 429 || $response->status >= 500) {
            return new Outcome(Outcome::RETRY, $response->failed() ? $response->error : 'HTTP ' . $response->status);
        }
        if (in_array($response->status, [401, 403], true)) {
            return new Outcome(Outcome::AUTH, 'HTTP ' . $response->status);
        }
        if ($response->status === 200 && str_starts_with($response->body, "PK\x03\x04")) {
            return new Outcome(Outcome::ZIP, '', null, null, [], $response->body);
        }
        $message = self::jsonMessage($response);
        $folded = Text::fold($message);
        $kind = match (true) {
            $message === '' => Outcome::RETRY,
            str_contains($folded, '60 de zile') => Outcome::EXPIRED,
            str_contains($folded, 'nu exista inregistrata') => Outcome::FATAL,
            default => self::classify($message) ?? Outcome::RETRY,
        };

        return new Outcome($kind, $message !== '' ? $message : self::excerpt($response->body));
    }

    /**
     * listaMesajeFactura and listaMesajePaginatieFactura.
     */
    public static function messages(Response $response): Outcome
    {
        if ($response->failed() || $response->status === 429 || $response->status >= 500) {
            return new Outcome(Outcome::RETRY, $response->failed() ? $response->error : 'HTTP ' . $response->status);
        }
        if (in_array($response->status, [401, 403], true)) {
            return new Outcome(Outcome::AUTH, 'HTTP ' . $response->status);
        }
        $json = $response->json();
        if ($json === null) {
            return new Outcome(Outcome::RETRY, self::excerpt($response->body));
        }
        if (isset($json['mesaje']) && is_array($json['mesaje'])) {
            $messages = [];
            foreach ($json['mesaje'] as $message) {
                if (is_array($message)) {
                    $messages[] = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $message);
                }
            }

            return new Outcome(Outcome::MESSAGES, '', null, null, $messages, '', max(1, (int) ($json['numar_total_pagini'] ?? 1)));
        }
        $message = trim((string) ($json['eroare'] ?? $json['message'] ?? ''));
        if (str_contains(Text::fold($message), 'nu exista mesaje')) {
            return new Outcome(Outcome::EMPTY, $message);
        }

        return new Outcome(self::classify($message) ?? Outcome::FATAL, $message);
    }

    /**
     * The class of an ANAF error text, or null when it is not one of the
     * known families.
     */
    public static function classify(string $message): ?string
    {
        $folded = Text::fold($message);

        return match (true) {
            preg_match('/s-au (facut|incarcat) deja \d+/', $folded) === 1 => Outcome::LIMIT,
            str_contains($folded, 'nu aveti drept') || str_contains($folded, 'nu exista niciun cif') => Outcome::AUTH,
            str_contains($folded, 'eroare tehnica') => Outcome::RETRY,
            default => null,
        };
    }

    private static function stateError(DOMElement $header): Outcome
    {
        $message = implode(' ', self::errorMessages($header));
        if ($message === '') {
            return new Outcome(Outcome::RETRY, 'stare: ' . $header->getAttribute('stare'));
        }
        if (str_contains(Text::fold($message), 'nu exista factura cu id_incarcare')) {
            return new Outcome(Outcome::FATAL, $message);
        }

        return new Outcome(self::classify($message) ?? Outcome::RETRY, $message);
    }

    private static function xmlHeader(string $body): ?DOMElement
    {
        if (trim($body) === '' || !str_starts_with(ltrim($body), '<?xml') && !str_starts_with(ltrim($body), '<header')) {
            return null;
        }
        $document = new DOMDocument();
        if (@$document->loadXML($body, LIBXML_NONET) === false) {
            return null;
        }
        $root = $document->documentElement;

        return $root !== null && $root->localName === 'header' ? $root : null;
    }

    /**
     * @return list<string>
     */
    private static function errorMessages(DOMElement $header): array
    {
        $messages = [];
        $xpath = new DOMXPath($header->ownerDocument);
        foreach ($xpath->query("*[local-name()='Errors' or local-name()='Error']", $header) as $error) {
            /** @var DOMElement $error */
            $messages[] = trim(preg_replace('/\s+/', ' ', $error->getAttribute('errorMessage')) ?? '');
        }

        return $messages;
    }

    private static function jsonMessage(Response $response): string
    {
        $json = $response->json();

        return $json === null ? '' : trim((string) ($json['eroare'] ?? $json['message'] ?? ''));
    }

    private static function excerpt(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $text === '' ? '(empty answer)' : mb_substr($text, 0, 200);
    }
}
