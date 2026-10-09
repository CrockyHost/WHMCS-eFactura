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

namespace WHMCS\Module\Addon\Efactura\Anaf\OAuth;

use Closure;
use DateTimeImmutable;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Efactura\Addon;
use WHMCS\Module\Addon\Efactura\Database\Lock;
use WHMCS\Module\Addon\Efactura\Support\Audit;
use WHMCS\Module\Addon\Efactura\Support\Crypto;

/**
 * The addon's connection to ANAF: the OAuth application credentials entered
 * by the admin and the tokens obtained by authorizing with the qualified
 * certificate. Stored in the single row of mod_efactura_oauth; the client
 * secret and both tokens are encrypted.
 *
 * Every change of the tokens happens under the "oauth" lock, because the
 * refresh token rotates: two concurrent refreshes would invalidate the pair.
 */
final class Connection
{
    public const TABLE = 'mod_efactura_oauth';
    public const CALLBACK_PATH = 'modules/addons/efactura/oauth_callback.php';

    private const ROW = 1;
    private const LOCK = 'oauth';
    /** The authorization link (state) stays valid this long. */
    public const STATE_TTL_MINUTES = 120;
    /** The access token (90 days) is refreshed when it has fewer days left. */
    public const REFRESH_DAYS_BEFORE_EXPIRY = 10;

    private Closure $clock;

    public function __construct(private readonly OAuthClient $client, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * The URL to register as "Callback URL" for the OAuth application at ANAF.
     */
    public static function callbackUrl(): string
    {
        return rtrim((string) Setting::getValue('SystemURL'), '/') . '/' . self::CALLBACK_PATH;
    }

    /**
     * @return array<string, mixed> state of the connection for display; never contains secrets
     */
    public function status(): array
    {
        $row = $this->row();
        $now = $this->now();
        $refreshExpires = self::date($row?->refresh_expires_at);

        return [
            'client_id' => (string) ($row->client_id ?? ''),
            'has_secret' => ($row->client_secret ?? '') !== '',
            'configured' => ($row->client_id ?? '') !== '' && ($row->client_secret ?? '') !== '',
            'connected' => ($row->refresh_token ?? '') !== '',
            'needs_reauthorization' => (bool) ($row->needs_reauthorization ?? false),
            'authorized_at' => self::date($row?->authorized_at),
            'authorized_by' => isset($row->authorized_by) ? (int) $row->authorized_by : null,
            'certificate_serial' => (string) ($row->certificate_serial ?? ''),
            'token_roles' => (string) ($row->token_roles ?? ''),
            'access_expires_at' => self::date($row?->access_expires_at),
            'refresh_expires_at' => $refreshExpires,
            'days_left' => $refreshExpires === null ? null : self::daysBetween($now, $refreshExpires),
            'refreshed_at' => self::date($row?->refreshed_at),
            'last_error' => (string) ($row->last_error ?? ''),
            'last_error_at' => self::date($row?->last_error_at),
            'redirect_uri' => (string) ($row->redirect_uri ?? ''),
        ];
    }

    /**
     * Saves the OAuth application credentials. A null or empty secret keeps
     * the stored one. Changing the client ID drops the tokens, which belong
     * to the previous application.
     */
    public function saveCredentials(string $clientId, ?string $clientSecret): void
    {
        $clientId = trim($clientId);
        $this->withLock(function () use ($clientId, $clientSecret): void {
            $row = $this->row();
            $values = ['client_id' => $clientId];
            if ($clientSecret !== null && trim($clientSecret) !== '') {
                $values['client_secret'] = Crypto::encrypt(trim($clientSecret));
            }
            if ($row !== null && (string) $row->client_id !== $clientId) {
                $values += self::clearedTokens();
            }
            $this->update($values);
            Audit::log('oauth_credentials_saved', 'OAuth application credentials saved', ['client_id' => $clientId]);
        });
    }

    /**
     * Starts an authorization and returns the ANAF URL to open in a browser
     * that has the qualified certificate. The state value in the URL is
     * single use and expires after STATE_TTL_MINUTES.
     */
    public function startAuthorization(?int $adminId): string
    {
        $row = $this->row();
        if ($row === null || (string) $row->client_id === '' || (string) $row->client_secret === '') {
            throw new OAuthException('The OAuth client ID and secret are not set.', OAuthException::NOT_CONFIGURED);
        }

        $state = bin2hex(random_bytes(32));
        $redirectUri = self::callbackUrl();
        $this->update([
            'state_hash' => hash('sha256', $state),
            'state_expires_at' => $this->now()->modify('+' . self::STATE_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
            'state_admin_id' => $adminId,
            'redirect_uri' => $redirectUri,
        ]);
        Audit::log('oauth_authorization_started', 'Authorization link created', ['redirect_uri' => $redirectUri], adminId: $adminId);

        return OAuthClient::authorizeUrl((string) $row->client_id, $redirectUri, $state);
    }

    /**
     * Handles the callback: checks the state, exchanges the code and stores
     * the tokens.
     */
    public function completeAuthorization(string $state, string $code): void
    {
        $this->withLock(function () use ($state, $code): void {
            $row = $this->consumeState($state);
            $now = $this->now();
            try {
                $tokens = $this->client->exchangeCode(
                    (string) $row->client_id,
                    Crypto::decrypt($row->client_secret),
                    $code,
                    (string) $row->redirect_uri,
                    $now
                );
            } catch (OAuthException $e) {
                $this->recordError($e->getMessage());
                Audit::log('oauth_authorization_failed', $e->getMessage(), adminId: self::nullableInt($row->state_admin_id));
                throw $e;
            }

            $this->update([
                'access_token' => Crypto::encrypt($tokens->accessToken),
                'refresh_token' => Crypto::encrypt($tokens->refreshToken),
                'access_expires_at' => $tokens->accessExpiresAt->format('Y-m-d H:i:s'),
                // ANAF requires a new authorization one year after this one;
                // refreshing the tokens does not extend it.
                'refresh_expires_at' => self::earliest($tokens->refreshExpiresAt, $now->modify('+' . TokenSet::REFRESH_DAYS . ' days'))->format('Y-m-d H:i:s'),
                'authorized_at' => $now->format('Y-m-d H:i:s'),
                'authorized_by' => self::nullableInt($row->state_admin_id),
                'refreshed_at' => null,
                'certificate_serial' => $tokens->certificateSerial,
                'token_roles' => $tokens->roles,
                'needs_reauthorization' => 0,
                'last_error' => null,
                'last_error_at' => null,
                'alert_days_sent' => null,
            ]);
            Audit::log('oauth_authorized', 'Connected to ANAF', [
                'certificate_serial' => $tokens->certificateSerial,
                'roles' => $tokens->roles,
                'access_expires_at' => $tokens->accessExpiresAt->format('Y-m-d H:i:s'),
            ], adminId: self::nullableInt($row->state_admin_id));
            if (function_exists('logActivity')) {
                logActivity(Addon::NAME . ': connected to ANAF (certificate ' . ($tokens->certificateSerial ?: 'unknown') . ')');
            }
        });
    }

    /**
     * Records an error returned by ANAF on the callback (e.g. access_denied
     * when no certificate was presented). The state must be valid, so that
     * random requests to the callback cannot write errors.
     */
    public function failAuthorization(string $state, string $error): void
    {
        $this->withLock(function () use ($state, $error): void {
            $row = $this->consumeState($state);
            $this->recordError('ANAF: ' . $error);
            Audit::log('oauth_authorization_failed', 'ANAF: ' . $error, adminId: self::nullableInt($row->state_admin_id));
        });
    }

    /**
     * Returns a valid access token, refreshing it when it is about to expire.
     */
    public function accessToken(): string
    {
        $row = $this->row();
        if ($row === null || (string) ($row->refresh_token ?? '') === '') {
            throw new OAuthException('The addon is not connected to ANAF.', OAuthException::NOT_CONNECTED, true);
        }
        if ((bool) $row->needs_reauthorization) {
            throw new OAuthException('The ANAF authorization must be renewed.', OAuthException::REJECTED, true);
        }

        $expires = self::date($row->access_expires_at);
        if ($expires === null || $expires <= $this->now()->modify('+' . self::REFRESH_DAYS_BEFORE_EXPIRY . ' days')) {
            try {
                $this->refresh();
            } catch (OAuthException $e) {
                // A still valid token remains usable when the refresh fails
                // for a transient reason.
                if ($e->reauthorize || $expires === null || $expires <= $this->now()) {
                    throw $e;
                }
            }
            $row = $this->row();
        }

        $token = Crypto::decrypt($row->access_token);
        if ($token === '') {
            $this->markReauthorizationNeeded('The stored token cannot be decrypted (was the database moved to another WHMCS installation?).');
            throw new OAuthException('The stored ANAF token cannot be decrypted.', OAuthException::REJECTED, true);
        }

        return $token;
    }

    /**
     * Refreshes the tokens when the access token expires within
     * REFRESH_DAYS_BEFORE_EXPIRY days, or always when $force is true.
     *
     * @return bool whether the tokens were refreshed
     */
    public function refresh(bool $force = false): bool
    {
        return $this->withLock(function () use ($force): bool {
            // Read again under the lock: another request may have refreshed already.
            $row = $this->row();
            if ($row === null || (string) ($row->refresh_token ?? '') === '') {
                throw new OAuthException('The addon is not connected to ANAF.', OAuthException::NOT_CONNECTED, true);
            }
            if ((bool) $row->needs_reauthorization) {
                throw new OAuthException('The ANAF authorization must be renewed.', OAuthException::REJECTED, true);
            }
            $now = $this->now();
            $expires = self::date($row->access_expires_at);
            if (!$force && $expires !== null && $expires > $now->modify('+' . self::REFRESH_DAYS_BEFORE_EXPIRY . ' days')) {
                return false;
            }

            $refreshToken = Crypto::decrypt($row->refresh_token);
            $secret = Crypto::decrypt($row->client_secret);
            if ($refreshToken === '' || $secret === '') {
                $this->markReauthorizationNeeded('The stored credentials cannot be decrypted.');
                throw new OAuthException('The stored ANAF credentials cannot be decrypted.', OAuthException::REJECTED, true);
            }

            try {
                $tokens = $this->client->refresh((string) $row->client_id, $secret, $refreshToken, $now);
            } catch (OAuthException $e) {
                if ($e->reauthorize) {
                    $this->markReauthorizationNeeded($e->getMessage());
                } else {
                    $this->recordError($e->getMessage());
                }
                Audit::log('oauth_refresh_failed', $e->getMessage(), ['reauthorize' => $e->reauthorize]);
                throw $e;
            }

            $refreshExpires = self::date($row->refresh_expires_at);
            $this->update([
                'access_token' => Crypto::encrypt($tokens->accessToken),
                'refresh_token' => Crypto::encrypt($tokens->refreshToken),
                'access_expires_at' => $tokens->accessExpiresAt->format('Y-m-d H:i:s'),
                'refresh_expires_at' => ($refreshExpires === null ? $tokens->refreshExpiresAt : self::earliest($refreshExpires, $tokens->refreshExpiresAt))->format('Y-m-d H:i:s'),
                'refreshed_at' => $now->format('Y-m-d H:i:s'),
                'certificate_serial' => $tokens->certificateSerial !== '' ? $tokens->certificateSerial : $row->certificate_serial,
                'token_roles' => $tokens->roles !== '' ? $tokens->roles : $row->token_roles,
                'last_error' => null,
                'last_error_at' => null,
            ]);
            Audit::log('oauth_refreshed', 'ANAF tokens refreshed', ['access_expires_at' => $tokens->accessExpiresAt->format('Y-m-d H:i:s')]);

            return true;
        });
    }

    /**
     * Forgets the tokens locally. ANAF has no working revocation endpoint;
     * compromised tokens must be reported to ANAF.
     */
    public function disconnect(?int $adminId): void
    {
        $this->withLock(function () use ($adminId): void {
            $this->update(self::clearedTokens());
            Audit::log('oauth_disconnected', 'ANAF tokens deleted', adminId: $adminId);
        });
    }

    /**
     * Remembers the alert threshold already notified, so each one is sent once.
     */
    public function markAlertSent(int $days): void
    {
        $this->update(['alert_days_sent' => $days]);
    }

    public function alertDaysSent(): ?int
    {
        $value = $this->row()?->alert_days_sent;

        return $value === null ? null : (int) $value;
    }

    private function consumeState(string $state): object
    {
        $row = $this->row();
        $expires = self::date($row?->state_expires_at);
        if ($row === null || $state === '' || ($row->state_hash ?? '') === ''
            || !hash_equals((string) $row->state_hash, hash('sha256', $state))
            || $expires === null || $expires < $this->now()) {
            throw new OAuthException('The authorization link is invalid, expired or already used.', OAuthException::INVALID_STATE);
        }
        // Single use: forget the state before talking to ANAF.
        $this->update(['state_hash' => null, 'state_expires_at' => null]);

        return $row;
    }

    private function markReauthorizationNeeded(string $message): void
    {
        $this->update(['needs_reauthorization' => 1, 'last_error' => $message, 'last_error_at' => $this->now()->format('Y-m-d H:i:s')]);
    }

    private function recordError(string $message): void
    {
        $this->update(['last_error' => $message, 'last_error_at' => $this->now()->format('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function clearedTokens(): array
    {
        return [
            'access_token' => null,
            'refresh_token' => null,
            'access_expires_at' => null,
            'refresh_expires_at' => null,
            'authorized_at' => null,
            'authorized_by' => null,
            'refreshed_at' => null,
            'certificate_serial' => null,
            'token_roles' => null,
            'needs_reauthorization' => 0,
            'alert_days_sent' => null,
            'state_hash' => null,
            'state_expires_at' => null,
            'state_admin_id' => null,
        ];
    }

    private function row(): ?object
    {
        return Capsule::table(self::TABLE)->where('id', self::ROW)->first();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(array $values): void
    {
        $now = $this->now()->format('Y-m-d H:i:s');
        if (Capsule::table(self::TABLE)->where('id', self::ROW)->exists()) {
            Capsule::table(self::TABLE)->where('id', self::ROW)->update($values + ['updated_at' => $now]);
        } else {
            Capsule::table(self::TABLE)->insert($values + ['id' => self::ROW, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        if (!Lock::acquire(self::LOCK, 30)) {
            throw new OAuthException('Another request is updating the ANAF connection; try again.', OAuthException::TRANSPORT);
        }
        try {
            return $callback();
        } finally {
            Lock::release(self::LOCK);
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }

    private static function earliest(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a < $b ? $a : $b;
    }

    /**
     * Whole days from $from to $to (negative when $to is in the past).
     */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return (int) floor($seconds / 86400);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
