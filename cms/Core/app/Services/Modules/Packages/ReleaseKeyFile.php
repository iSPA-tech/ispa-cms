<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use InvalidArgumentException;

/**
 * The offline release key file the signing commands read (`cms:module-package-sign`, `cms:widget-bundle-sign`):
 * mode 0600, outside the repository, holding a base64 Ed25519 secret key. Messages never contain key material.
 */
final class ReleaseKeyFile
{
    /**
     * The real path of a usable key file.
     *
     * @throws InvalidArgumentException with the refusal message
     */
    public static function resolve(string $option): string
    {
        $option = trim($option);
        if ($option === '') {
            throw new InvalidArgumentException('--key-file is required.');
        }
        $keyPath = realpath($option);
        if ($keyPath === false || !is_file($keyPath)) {
            throw new InvalidArgumentException('Key file not found: ' . $option);
        }
        $repo = realpath(base_path());
        if ($repo === false || str_starts_with($keyPath, rtrim($repo, '/') . '/')) {
            throw new InvalidArgumentException('Refusing a key file inside the repository: ' . $keyPath);
        }
        clearstatcache(true, $keyPath);
        if ((fileperms($keyPath) & 0777) !== 0600) {
            throw new InvalidArgumentException(sprintf('Key file must be mode 0600 (found %04o): %s', fileperms($keyPath) & 0777, $keyPath));
        }

        return $keyPath;
    }

    /**
     * The raw secret key; the caller wipes it with sodium_memzero() when done.
     *
     * @throws InvalidArgumentException when the file does not hold a base64 Ed25519 secret key
     */
    public static function readSecret(string $keyPath): string
    {
        $encoded = file_get_contents($keyPath);
        $trimmed = $encoded === false ? '' : trim($encoded);
        $secret = $encoded === false ? false : base64_decode($trimmed, true);
        if ($encoded !== false) {
            sodium_memzero($encoded);
        }
        if ($trimmed !== '') {
            sodium_memzero($trimmed);
        }
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            if (is_string($secret) && $secret !== '') {
                sodium_memzero($secret);
            }
            throw new InvalidArgumentException('Key file does not hold a base64 Ed25519 secret key.');
        }

        return $secret;
    }
}
