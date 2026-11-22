<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Security;

use RuntimeException;

/**
 * Time-based One-Time Password (TOTP) service implementing RFC 6238.
 *
 * Generates Base32-encoded secrets, computes TOTP codes with HMAC-SHA1,
 * and verifies codes within a configurable time window. Generates
 * otpauth:// URIs for authenticator app enrollment.
 *
 * @see AdminMfaService
 * @package Cms\Core\Services\Security
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically random Base32-encoded secret.
     *
     * @param int $length The number of Base32 characters (default 32).
     * @return string The generated secret.
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $maxIndex = strlen(self::BASE32_ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $maxIndex)];
        }

        return $secret;
    }

    /**
     * Generate an otpauth:// URI for authenticator app enrollment.
     *
     * @param string $issuer       The issuer name (e.g., brand).
     * @param string $accountLabel The account label (e.g., email).
     * @param string $secret       The Base32-encoded secret.
     * @return string The otpauth URI.
     */
    public function generateOtpAuthUri(string $issuer, string $accountLabel, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Generate the current TOTP code.
     *
     * @param string $secret The Base32-encoded secret.
     * @return string The 6-digit TOTP code.
     */
    public function now(string $secret): string
    {
        return $this->at($secret, time());
    }

    /**
     * Generate the TOTP code for a specific timestamp.
     *
     * @param string $secret    The Base32-encoded secret.
     * @param int    $timestamp The Unix timestamp.
     * @return string The 6-digit TOTP code.
     *
     * @throws RuntimeException When the TOTP secret is empty or contains invalid characters.
     */
    public function at(string $secret, int $timestamp): string
    {
        $secretKey = $this->decodeBase32($secret);
        $counter = intdiv($timestamp, 30);
        $counterBytes = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $counterBytes, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7fffffff;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code against the secret within a time window.
     *
     * @param string   $secret    The Base32-encoded secret.
     * @param string   $code      The code to verify.
     * @param int      $window    Number of 30-second periods to check on each side.
     * @param int|null $timestamp Optional timestamp override (default: current time).
     * @return bool True if the code matches within the window.
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        return $this->matchingCounter($secret, $code, $window, $timestamp) !== null;
    }

    /**
     * The 30-second time step a TOTP code belongs to, when it is valid within the window.
     *
     * Callers that must refuse a replayed code compare the step with the last one the factor accepted.
     *
     * @param string   $secret    The Base32-encoded secret.
     * @param string   $code      The code to verify.
     * @param int      $window    Number of 30-second periods to check on each side.
     * @param int|null $timestamp Optional timestamp override (default: current time).
     * @return int|null The time step (Unix time divided by 30), or null when the code does not match.
     */
    public function matchingCounter(string $secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $normalizedCode = preg_replace('/\D+/', '', $code);

        if (!is_string($normalizedCode) || strlen($normalizedCode) !== 6) {
            return null;
        }

        $timestamp ??= time();

        for ($offset = -$window; $offset <= $window; $offset++) {
            $at = $timestamp + ($offset * 30);

            if (hash_equals($this->at($secret, $at), $normalizedCode)) {
                return intdiv($at, 30);
            }
        }

        return null;
    }

    private function decodeBase32(string $secret): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        if ($normalized === '') {
            throw new RuntimeException('TOTP secret is empty.');
        }

        $buffer = '';
        $bits = 0;
        $value = 0;

        foreach (str_split($normalized) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                throw new RuntimeException('TOTP secret contains invalid characters.');
            }

            $value = ($value << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $buffer .= chr(($value >> $bits) & 0xff);
            }
        }

        return $buffer;
    }
}
