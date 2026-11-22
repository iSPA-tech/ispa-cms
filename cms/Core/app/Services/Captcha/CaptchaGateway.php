<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Captcha;

use Throwable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Cms\Core\Services\CMSSettingRuntime;

/**
 * Gateway for verifying CAPTCHA tokens against Cloudflare Turnstile or Google reCAPTCHA.
 *
 * Reads the active provider and secret keys from CMS settings at runtime.
 * Returns a CaptchaVerifyResult with verification status, provider info,
 * and raw API response. Supports Google reCAPTCHA v3 score thresholds.
 *
 * Cloudflare Turnstile responses are additionally checked against the siteverify requirements:
 * the token length cap, a non-2xx answer is an error, the `action` the caller expects (via the
 * `action` context key) and an optional hostname allowlist (`services.turnstile.hostnames`).
 *
 * @see CaptchaServiceInterface
 * @see CaptchaVerifyResult
 * @package Cms\Core\Services\Captcha
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class CaptchaGateway implements CaptchaServiceInterface
{
    private const ENDPOINTS = [
        'cloudflare' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'google'     => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha'   => 'https://api.hcaptcha.com/siteverify',
    ];

    /** Turnstile tokens are at most 2048 characters (Cloudflare siteverify requirement). */
    private const CLOUDFLARE_MAX_TOKEN_LENGTH = 2048;

    /** Seconds to wait for the provider's siteverify answer (Cloudflare recommends 10). */
    private const TIMEOUT_SECONDS = 10;

    private const SECRET_KEYS = [
        'cloudflare' => 'api.CLOUDFLARE_TURNSTILE_SECRET_KEY',
        'google'     => 'api.GOOGLE_RECAPTCHA_BE_KEY',
        'hcaptcha'   => 'api.HCAPTCHA_SECRET_KEY',
    ];

    /**
     * Initialize the gateway with the CMS runtime settings provider.
     *
     * @param CMSSettingRuntime $settings Runtime settings for resolving captcha provider and secret keys.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        private readonly CMSSettingRuntime $settings,
    ) {}

    /**
     * {@inheritdoc}
     * @return string
     */
    public function provider(): string
    {
        $raw = $this->settings->get('advanced.recaptcha_provider', 'off');
        $normalized = strtolower(trim((string) $raw));

        if ($normalized === 'cloudlfare') {
            $normalized = 'cloudflare';
        }

        return in_array($normalized, ['off', 'google', 'cloudflare', 'hcaptcha'], true)
            ? $normalized
            : 'off';
    }

    /**
     * {@inheritdoc}
     * Error codes: `secret_missing`, `missing_token`, `site_key_missing`, `invalid_token` (Turnstile token over
     * 2048 characters, no request is sent), `http_error` (transport failure or non-2xx answer), `action_mismatch`
     * and `hostname_mismatch` (Turnstile only). Recognized context keys: `minScore` (Google v3) and `action`
     * (Turnstile: the action the widget was rendered with).
     *
     * @param  string|null $token
     * @param  string|null $ip
     * @param  array $context
     * @return CaptchaVerifyResult
     */
    public function verify(?string $token, ?string $ip = null, array $context = []): CaptchaVerifyResult
    {
        $provider = $this->provider();

        if ($provider === 'off') {
            return new CaptchaVerifyResult(ok: true, provider: 'off');
        }

        $secret = (string) $this->settings->get(self::SECRET_KEYS[$provider], '');

        if ($secret === '') {
            return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'secret_missing');
        }

        if ($token === null || $token === '') {
            return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'missing_token');
        }

        if ($provider === 'cloudflare' && strlen($token) > self::CLOUDFLARE_MAX_TOKEN_LENGTH) {
            return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'invalid_token');
        }

        $siteKey = null;
        if ($provider === 'hcaptcha') {
            $siteKey = trim((string) $this->settings->get('api.HCAPTCHA_SITE_KEY', ''));
            if ($siteKey === '') {
                return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'site_key_missing');
            }
        }

        $fields = [
            'secret'   => $secret,
            'response' => $token,
        ];

        if ($siteKey !== null) {
            $fields['sitekey'] = $siteKey;
        }

        if ($ip !== null && $ip !== '') {
            $fields['remoteip'] = $ip;
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post(self::ENDPOINTS[$provider], $fields);
            $json = $response->json();
        } catch (Throwable $exception) {
            $this->logFailure($provider, 'http_error', ['exception' => $exception::class, 'token_length' => strlen($token)]);
            return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'http_error');
        }

        if (! $response->successful()) {
            $this->logFailure($provider, 'http_error', ['status' => $response->status(), 'token_length' => strlen($token)]);
            return new CaptchaVerifyResult(ok: false, provider: $provider, error: 'http_error');
        }

        $json = is_array($json) ? $json : [];
        $ok = ($json['success'] ?? false) === true;

        if ($ok && $provider === 'google' && isset($json['score'], $context['minScore'])) {
            $ok = (float) $json['score'] >= (float) $context['minScore'];
        }

        $safe = array_diff_key($json, array_flip(['secret']));

        if ($ok && $provider === 'cloudflare') {
            $error = $this->cloudflareMismatch($json, $context);

            if ($error !== null) {
                $this->logFailure($provider, $error, $this->answerDetails($safe, $token));
                return new CaptchaVerifyResult(ok: false, provider: $provider, error: $error, raw: $safe);
            }
        }

        if (! $ok) {
            $this->logFailure($provider, 'rejected', $this->answerDetails($safe, $token));
        }

        return new CaptchaVerifyResult(ok: $ok, provider: $provider, raw: $safe);
    }

    /**
     * Log a failed verification so an operator can see the provider's reason; the token and secret are never logged.
     *
     * @param array<string, mixed> $details Provider answer fields or transport facts worth keeping.
     */
    private function logFailure(string $provider, string $error, array $details = []): void
    {
        Log::warning('Captcha verification failed', ['provider' => $provider, 'error' => $error] + $details);
    }

    /**
     * The safe-to-log part of a siteverify answer (error codes, hostname, action, timestamp) plus the token length.
     *
     * @param  array<string, mixed> $safe Decoded answer without the secret.
     * @return array<string, mixed>
     */
    private function answerDetails(array $safe, string $token): array
    {
        return [
            'error_codes' => $safe['error-codes'] ?? [],
            'hostname' => $safe['hostname'] ?? null,
            'action' => $safe['action'] ?? null,
            'challenge_ts' => $safe['challenge_ts'] ?? null,
            'token_length' => strlen($token),
        ];
    }

    /**
     * Compare a successful Turnstile answer with what the caller expects.
     *
     * The action is checked only when the caller passes a non-empty `action` in the context. The hostname is
     * checked only when `services.turnstile.hostnames` lists at least one hostname (case-insensitive); an empty
     * list skips the check so installations that do not configure it keep their behaviour.
     *
     * @param  array<string, mixed> $json    Decoded siteverify response.
     * @param  array<string, mixed> $context Verify context (`action`).
     * @return string|null `action_mismatch`, `hostname_mismatch`, or null when the answer matches.
     */
    private function cloudflareMismatch(array $json, array $context): ?string
    {
        $expectedAction = $context['action'] ?? null;

        if (is_string($expectedAction) && $expectedAction !== '' && ($json['action'] ?? null) !== $expectedAction) {
            return 'action_mismatch';
        }

        $allowedHostnames = array_values(array_filter(array_map(
            static fn (mixed $hostname): string => strtolower(trim((string) $hostname)),
            (array) config('services.turnstile.hostnames', []),
        )));

        if ($allowedHostnames !== []) {
            $hostname = is_string($json['hostname'] ?? null) ? strtolower(trim($json['hostname'])) : '';

            if (! in_array($hostname, $allowedHostnames, true)) {
                return 'hostname_mismatch';
            }
        }

        return null;
    }
}
