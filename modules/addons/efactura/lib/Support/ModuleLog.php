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

namespace WHMCS\Module\Addon\Efactura\Support;

use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Http\Request;
use WHMCS\Module\Addon\Efactura\Http\Response;

/**
 * Writes ANAF calls to the WHMCS Module Log (Configuration > System Logs >
 * Module Log, when enabled). Secrets are masked by WHMCS: every value passed
 * in $secrets is replaced with asterisks in the stored request and response.
 */
final class ModuleLog
{
    /**
     * @param list<string> $secrets
     */
    public static function call(string $action, Request $request, Response $response, array $secrets): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $requestText = $request->method . ' ' . $request->url;
        foreach ($request->headers as $name => $value) {
            $requestText .= "\n" . $name . ': ' . $value;
        }
        if ($request->body !== '') {
            $requestText .= "\n\n" . $request->body;
        }

        $responseText = $response->failed()
            ? sprintf('cURL error %d: %s (request sent: %s)', $response->errorCode, $response->error, $response->requestSent ? 'yes' : 'no')
            : 'HTTP ' . $response->status . "\n\n" . self::excerpt($response->body);

        // Also mask URL-encoded and Base64 forms, as they appear in bodies and headers.
        $masks = [];
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $masks[] = $secret;
                $masks[] = rawurlencode($secret);
                $masks[] = urlencode($secret);
            }
        }

        logModuleCall(Addon::MODULE, $action, $requestText, $responseText, $response->durationMs . ' ms', array_values(array_unique($masks)));
    }

    private static function excerpt(string $body): string
    {
        if (str_starts_with($body, "PK\x03\x04")) {
            return '[ZIP archive, ' . strlen($body) . ' bytes]';
        }
        if (str_starts_with($body, '%PDF')) {
            return '[PDF document, ' . strlen($body) . ' bytes]';
        }

        return strlen($body) > 20000 ? substr($body, 0, 20000) . "\n[truncated]" : $body;
    }
}
