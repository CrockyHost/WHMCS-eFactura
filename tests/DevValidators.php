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

/**
 * The official validators, for tests only (they are not part of the addon):
 * the OASIS UBL 2.1 XSD through libxml, and the CEN EN 16931 + CIUS-RO
 * 1.0.9 Schematron, compiled to XSLT 2.0 and run with Saxon-HE (Java).
 *
 * The tools live outside the repository, in EFACTURA_TOOLS or by default in
 * ../crocky-efactura-env/tools (see build-validators.sh there). Tests that
 * need them are skipped when they are missing.
 */
final class DevValidators
{
    public static function toolsDir(): ?string
    {
        $dir = getenv('EFACTURA_TOOLS') ?: dirname(__DIR__, 2) . '/crocky-efactura-env/tools';

        return is_file($dir . '/ro16931/ro-validator-1.0.9.xsl') && is_file($dir . '/saxon/Saxon-HE-10.9.jar') ? $dir : null;
    }

    public static function available(): bool
    {
        return self::toolsDir() !== null;
    }

    /**
     * @return list<string> XSD errors (empty when valid)
     */
    public static function xsd(string $xml): array
    {
        $tools = self::toolsDir() ?? throw new RuntimeException('Validation tools not found.');
        $document = new DOMDocument();
        $document->loadXML($xml);
        $root = $document->documentElement?->localName;
        $schema = $tools . '/ubl-2.1/xsd/maindoc/UBL-' . ($root === 'CreditNote' ? 'CreditNote' : 'Invoice') . '-2.1.xsd';

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document->schemaValidate($schema);
        $errors = array_map(static fn (LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $errors;
    }

    /**
     * Runs the Schematron and returns the failed assertions.
     *
     * @return list<array{id: string, flag: string, text: string}>
     */
    public static function schematron(string $xml): array
    {
        $tools = self::toolsDir() ?? throw new RuntimeException('Validation tools not found.');
        $input = tempnam(sys_get_temp_dir(), 'efx');
        $output = $input . '.svrl';
        file_put_contents($input, $xml);
        $command = sprintf(
            'java -jar %s -s:%s -xsl:%s -o:%s 2>&1',
            escapeshellarg($tools . '/saxon/Saxon-HE-10.9.jar'),
            escapeshellarg($input),
            escapeshellarg($tools . '/ro16931/ro-validator-1.0.9.xsl'),
            escapeshellarg($output)
        );
        exec($command, $lines, $code);
        @unlink($input);
        if ($code !== 0 || !is_file($output)) {
            throw new RuntimeException('Saxon failed: ' . implode("\n", $lines));
        }
        $svrl = new DOMDocument();
        $svrl->load($output);
        @unlink($output);

        $failures = [];
        $xpath = new DOMXPath($svrl);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        foreach ($xpath->query('//svrl:failed-assert | //svrl:successful-report') as $node) {
            /** @var DOMElement $node */
            $failures[] = [
                'id' => $node->getAttribute('id'),
                'flag' => $node->getAttribute('flag') ?: 'fatal',
                'text' => trim(preg_replace('/\s+/', ' ', $node->textContent) ?? ''),
            ];
        }

        return $failures;
    }

    /**
     * @return list<string> the fatal Schematron failures and XSD errors, as text
     */
    public static function errors(string $xml): array
    {
        return self::errorsMany(['document' => $xml])['document'];
    }

    /**
     * Validates several documents with a single Saxon run (starting Java
     * takes most of the time).
     *
     * @param array<string, string> $documents name => XML
     * @return array<string, list<string>> name => fatal errors (XSD and Schematron)
     */
    public static function errorsMany(array $documents): array
    {
        $tools = self::toolsDir() ?? throw new RuntimeException('Validation tools not found.');
        $base = sys_get_temp_dir() . '/efx-' . bin2hex(random_bytes(4));
        mkdir($base . '/in', 0700, true);
        mkdir($base . '/out', 0700, true);
        $files = [];
        $results = [];
        foreach (array_values(array_keys($documents)) as $i => $name) {
            $files[$name] = sprintf('doc%03d.xml', $i);
            file_put_contents($base . '/in/' . $files[$name], $documents[$name]);
            $results[$name] = array_map(static fn (string $e): string => 'XSD: ' . $e, self::xsd($documents[$name]));
        }

        $command = sprintf(
            'java -jar %s -s:%s -xsl:%s -o:%s 2>&1',
            escapeshellarg($tools . '/saxon/Saxon-HE-10.9.jar'),
            escapeshellarg($base . '/in'),
            escapeshellarg($tools . '/ro16931/ro-validator-1.0.9.xsl'),
            escapeshellarg($base . '/out')
        );
        exec($command, $lines, $code);
        try {
            if ($code !== 0) {
                throw new RuntimeException('Saxon failed: ' . implode("\n", $lines));
            }
            foreach ($files as $name => $file) {
                $svrl = new DOMDocument();
                $svrl->load($base . '/out/' . $file);
                $xpath = new DOMXPath($svrl);
                $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
                foreach ($xpath->query('//svrl:failed-assert | //svrl:successful-report') as $node) {
                    /** @var DOMElement $node */
                    if (($node->getAttribute('flag') ?: 'fatal') === 'fatal') {
                        $results[$name][] = $node->getAttribute('id') . ': ' . trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
                    }
                }
            }
        } finally {
            array_map('unlink', glob($base . '/*/*') ?: []);
            @rmdir($base . '/in');
            @rmdir($base . '/out');
            @rmdir($base);
        }

        return $results;
    }
}
