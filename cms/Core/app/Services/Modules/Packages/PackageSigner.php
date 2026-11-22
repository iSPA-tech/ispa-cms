<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use InvalidArgumentException;

/**
 * Signs release manifest bytes with an Ed25519 secret key (libsodium detached signature).
 *
 * Runs only on the owner's release machine; the secret key never lives on a server or in the repository.
 */
final class PackageSigner
{
    /**
     * Sign the exact manifest bytes.
     *
     * @return array{key_id: string, signature: string} signature is base64 of the 64-byte detached signature
     *
     * @throws InvalidArgumentException when the secret key has the wrong length
     */
    public function sign(string $manifestBytes, #[\SensitiveParameter] string $secretKey): array
    {
        try {
            if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new InvalidArgumentException('Secret key must be ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' raw bytes.');
            }
            $public = sodium_crypto_sign_publickey_from_secretkey($secretKey);
            $signature = sodium_crypto_sign_detached($manifestBytes, $secretKey);

            return ['key_id' => KeyId::fromPublicKey($public), 'signature' => base64_encode($signature)];
        } finally {
            sodium_memzero($secretKey);
        }
    }

    /**
     * Build the sig.json document {key_id, signature, manifest} where manifest is base64 of the exact bytes.
     */
    public function envelope(string $manifestBytes, #[\SensitiveParameter] string $secretKey): string
    {
        try {
            $signed = $this->sign($manifestBytes, $secretKey);
        } finally {
            sodium_memzero($secretKey);
        }

        return json_encode([
            'key_id' => $signed['key_id'],
            'signature' => $signed['signature'],
            'manifest' => base64_encode($manifestBytes),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }
}
