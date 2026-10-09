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
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The ZIP returned by "descarcare": {index}.xml (the invoice as validated,
 * or the list of errors) and semnatura_{index}.xml (the detached signature
 * of the Ministry of Finance). The ZIP itself is the legal original and is
 * archived as received; this class only reads it.
 */
final class ResponseZip
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public readonly string $index,
        public readonly ?string $invoiceXml,
        public readonly ?string $errorsXml,
        public readonly ?string $signatureXml,
        public readonly array $errors,
        public readonly ?string $duplicateIndex,
        public readonly ?string $duplicateDate,
    ) {
    }

    public static function read(string $zip): self
    {
        $file = tempnam(sys_get_temp_dir(), 'efz');
        file_put_contents($file, $zip);
        $archive = new ZipArchive();
        try {
            if ($archive->open($file) !== true) {
                throw new RuntimeException('The ANAF answer is not a readable ZIP archive.');
            }
            $main = null;
            $signature = null;
            $index = '';
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = basename((string) $archive->getNameIndex($i));
                if (preg_match('/^semnatura_(\d+)\.xml$/i', $name) === 1) {
                    $signature = (string) $archive->getFromIndex($i);
                } elseif (preg_match('/^(\d+)\.xml$/', $name, $match) === 1) {
                    $main = (string) $archive->getFromIndex($i);
                    $index = $match[1];
                }
            }
        } finally {
            $archive->close();
            @unlink($file);
        }
        if ($main === null) {
            throw new RuntimeException('The ANAF ZIP has no {index}.xml file.');
        }

        $document = new DOMDocument();
        if (@$document->loadXML($main, LIBXML_NONET) === false) {
            throw new RuntimeException('The XML in the ANAF ZIP cannot be read.');
        }
        if ($document->documentElement?->localName !== 'header') {
            return new self($index, $main, null, $signature, [], null, null);
        }

        $errors = [];
        $duplicateIndex = null;
        $duplicateDate = null;
        $xpath = new DOMXPath($document);
        foreach ($xpath->query("//*[local-name()='Error' or local-name()='Eroare']") as $node) {
            $message = trim(preg_replace('/\s+/', ' ', $node->getAttribute('errorMessage')) ?? '');
            $errors[] = $message;
            if (preg_match('/a mai fost transmisa anterior cu index\s*=\s*(\d+)\s+si\s+data incarcare\s*=\s*([0-9\-]+)/i', $message, $match) === 1) {
                $duplicateIndex = $match[1];
                $duplicateDate = $match[2];
            }
        }

        return new self($index, null, $main, $signature, $errors, $duplicateIndex, $duplicateDate);
    }

    public function isInvoice(): bool
    {
        return $this->invoiceXml !== null;
    }

    /**
     * BT-1, BT-2, the seller identifier, the total with VAT (BT-112) and the
     * SHA-256 of the invoice XML, used to match an upload whose answer was
     * lost.
     *
     * @return array{number: string, date: string, seller: string, total: string, sha256: string}|null
     */
    public function invoiceKey(): ?array
    {
        if ($this->invoiceXml === null) {
            return null;
        }
        $document = new DOMDocument();
        $document->loadXML($this->invoiceXml, LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $value = static fn (string $path): string => trim((string) $xpath->evaluate('string(' . $path . ')'));

        return [
            'number' => $value("/*/*[local-name()='ID']"),
            'date' => $value("/*/*[local-name()='IssueDate']"),
            'seller' => $value("//*[local-name()='AccountingSupplierParty']//*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']")
                ?: $value("//*[local-name()='AccountingSupplierParty']//*[local-name()='PartyLegalEntity']/*[local-name()='CompanyID']"),
            'total' => $value("/*/*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxInclusiveAmount']"),
            'sha256' => hash('sha256', $this->invoiceXml),
        ];
    }
}
