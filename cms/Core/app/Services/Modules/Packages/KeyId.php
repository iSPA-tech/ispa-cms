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
 * Derives the short identifier of an Ed25519 release-signing public key.
 */
final class KeyId
{
    /**
     * First 16 hex chars of sha256 over the raw 32-byte public key.
     *
     * @throws InvalidArgumentException when the key is not 32 bytes
     */
    public static function fromPublicKey(string $rawPublicKey): string
    {
        if (strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Public key must be ' . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ' raw bytes.');
        }

        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }
}
