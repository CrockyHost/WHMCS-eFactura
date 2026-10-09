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

namespace WHMCS\Module\Addon\Efactura\Http;

use WHMCS\Module\Addon\Efactura\Addon;

/**
 * Minimal HTTP client over ext-curl, with TLS verification always on.
 */
final class CurlTransport implements Transport
{
    public function send(Request $request): Response
    {
        $headers = [];
        $handle = curl_init();
        $outgoing = ['User-Agent: ' . Addon::NAME . '/' . Addon::VERSION];
        foreach ($request->headers as $name => $value) {
            $outgoing[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_HTTPHEADER => $outgoing,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($request->body !== '' || $request->method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $started = hrtime(true);
        $body = curl_exec($handle);
        $duration = (int) ((hrtime(true) - $started) / 1_000_000);
        $errorCode = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // Whether any byte of the request left this server: after a failure
        // past this point, the server may have received the request.
        $requestSent = (int) curl_getinfo($handle, CURLINFO_REQUEST_SIZE) > 0;
        curl_close($handle);

        if ($body === false || $errorCode !== 0) {
            return new Response(0, $headers, is_string($body) ? $body : '', $errorCode, $error, $requestSent, $duration);
        }

        return new Response($status, $headers, (string) $body, 0, '', true, $duration);
    }
}
