<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use JsonException;

/**
 * Verifies a module package sig.json against the trusted Ed25519 keys and returns its release manifest.
 *
 * Order: envelope shape -> revoked -> unknown -> configured key matches its id -> signature over the raw manifest
 * bytes -> manifest schema -> manifest key_id equals the signing key. Manifest JSON is never parsed before the
 * signature has verified.
 */
final class PackageVerifier
{
    private const ENVELOPE_KEYS = ['key_id', 'signature', 'manifest'];

    /** @var array<string, string> key id => base64 public key */
    private array $trustedKeys;

    /** @var array<int, string> */
    private array $revokedKeyIds;

    /**
     * @param array<string, string>|null $trustedKeys   key id => base64 public key; null = config core.module_packages.trusted_keys
     * @param array<int, string>|null    $revokedKeyIds null = config core.module_packages.revoked_key_ids
     */
    public function __construct(?array $trustedKeys = null, ?array $revokedKeyIds = null)
    {
        $this->trustedKeys = $trustedKeys ?? (array) config('core.module_packages.trusted_keys', []);
        $this->revokedKeyIds = array_map('strval', $revokedKeyIds ?? (array) config('core.module_packages.revoked_key_ids', []));
    }

    /**
     * Verify the sig.json document and return the manifest it signs.
     *
     * @throws PackageVerificationException with the code of the failing step
     */
    public function verify(string $sigJson): ReleaseManifest
    {
        ['key_id' => $keyId, 'bytes' => $manifestBytes] = $this->verifyEnvelope($sigJson);

        $manifest = ReleaseManifest::fromVerifiedBytes($manifestBytes);
        if (!hash_equals($keyId, $manifest->keyId)) {
            throw new PackageVerificationException(PackageVerificationException::KEY_MISMATCH, 'manifest key_id differs from the signing key');
        }

        return $manifest;
    }

    /**
     * Verify the envelope, trust, revocation and signature, and return the signed bytes unparsed.
     *
     * Shared by module packages ({@see self::verify()}) and other signed documents (widget bundles) that parse their
     * own payload after the signature has verified.
     *
     * @return array{key_id: string, bytes: string}
     *
     * @throws PackageVerificationException with the code of the failing step
     */
    public function verifyEnvelope(string $sigJson): array
    {
        [$keyId, $signature, $manifestBytes] = $this->decodeEnvelope($sigJson);

        if (in_array($keyId, $this->revokedKeyIds, true)) {
            throw new PackageVerificationException(PackageVerificationException::KEY_REVOKED, 'signing key ' . $keyId . ' is revoked');
        }
        if (!array_key_exists($keyId, $this->trustedKeys)) {
            throw new PackageVerificationException(PackageVerificationException::KEY_UNKNOWN, 'signing key ' . $keyId . ' is not trusted');
        }

        $public = base64_decode((string) $this->trustedKeys[$keyId], true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !hash_equals(KeyId::fromPublicKey($public), $keyId)) {
            throw new PackageVerificationException(PackageVerificationException::KEY_INVALID, 'configured public key for ' . $keyId . ' does not match its key id');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($signature, $manifestBytes, $public)) {
            throw new PackageVerificationException(PackageVerificationException::SIGNATURE, 'signature does not verify');
        }

        return ['key_id' => $keyId, 'bytes' => $manifestBytes];
    }

    /**
     * Strictly decode {key_id, signature, manifest}; returns [keyId, raw signature, raw manifest bytes].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function decodeEnvelope(string $sigJson): array
    {
        try {
            $doc = json_decode($sigJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->envelope('signature document is not valid JSON');
        }
        if (!is_array($doc) || array_is_list($doc)) {
            throw $this->envelope('signature document must be a JSON object');
        }
        $keys = array_map('strval', array_keys($doc));
        sort($keys);
        $expected = self::ENVELOPE_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw $this->envelope('signature document must have exactly key_id, signature, manifest');
        }
        foreach (self::ENVELOPE_KEYS as $key) {
            if (!is_string($doc[$key])) {
                throw $this->envelope($key . ' must be a string');
            }
        }
        if (preg_match('/^[0-9a-f]{16}$/', $doc['key_id']) !== 1) {
            throw $this->envelope('key_id must be 16 lowercase hex chars');
        }
        $signature = base64_decode($doc['signature'], true);
        $manifest = base64_decode($doc['manifest'], true);
        if ($signature === false || $manifest === false || $signature === '' || $manifest === '') {
            throw $this->envelope('signature and manifest must be non-empty strict base64');
        }

        return [$doc['key_id'], $signature, $manifest];
    }

    /**
     * Build an envelope refusal.
     */
    private function envelope(string $message): PackageVerificationException
    {
        return new PackageVerificationException(PackageVerificationException::ENVELOPE, $message);
    }
}
