<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseKeyFile;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Signs a module release manifest and writes <Name>-<version>.sig.json next to it.
 *
 * The key file must be mode 0600 and live outside the repository. Key material is never printed.
 */
class ModulePackageSignCommand extends Command
{
    protected $signature = 'cms:module-package-sign {manifest? : Path to the release manifest JSON} {--key-file= : Path to release-signing.key (mode 0600, outside the repository)} {--challenge= : Sign a hub key challenge instead of a manifest (prints the signature)}';

    protected $description = 'Sign a module package manifest with the offline release key.';

    /**
     * Validate key file and manifest, then write the signature document; every refusal exits non-zero.
     */
    public function handle(PackageSigner $signer): int
    {
        try {
            $keyPath = ReleaseKeyFile::resolve((string) $this->option('key-file'));
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        $challenge = $this->option('challenge');
        if (is_string($challenge) && $challenge !== '') {
            return $this->signChallenge($keyPath, $challenge);
        }

        $manifestPath = realpath((string) $this->argument('manifest'));
        if ($manifestPath === false || !is_file($manifestPath) || !is_readable($manifestPath)) {
            return $this->refuse('Manifest not found: ' . $this->argument('manifest'));
        }
        $bytes = file_get_contents($manifestPath);
        if ($bytes === false) {
            return $this->refuse('Cannot read manifest: ' . $manifestPath);
        }
        try {
            // Schema check only: the bytes are about to be signed by their author.
            $manifest = ReleaseManifest::fromVerifiedBytes($bytes);
        } catch (PackageVerificationException $e) {
            return $this->refuse('Manifest is not valid: ' . $e->getMessage());
        }

        try {
            $secret = ReleaseKeyFile::readSecret($keyPath);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        try {
            $keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey_from_secretkey($secret));
            if (!hash_equals($keyId, $manifest->keyId)) {
                return $this->refuse('Manifest key_id ' . $manifest->keyId . ' does not match the key file (' . $keyId . ').');
            }

            $sigPath = dirname($manifestPath) . '/' . $manifest->name . '-' . $manifest->version . '.sig.json';
            if (file_exists($sigPath) || is_link($sigPath)) {
                return $this->refuse('Signature file already exists, refusing to overwrite: ' . $sigPath);
            }
            $document = $signer->envelope($bytes, $secret);
        } finally {
            sodium_memzero($secret);
        }

        $handle = @fopen($sigPath, 'x');
        if ($handle === false) {
            return $this->refuse('Cannot create ' . $sigPath);
        }
        $written = fwrite($handle, $document);
        fclose($handle);
        if ($written !== strlen($document)) {
            @unlink($sigPath);

            return $this->refuse('Writing ' . $sigPath . ' failed; nothing kept.');
        }

        $this->info('Signed ' . $manifest->name . ' ' . $manifest->version . ' with key ' . $keyId . ': ' . $sigPath);

        return self::SUCCESS;
    }

    /**
     * Sign a hub key challenge (, `ispa-publisher-key-v1:...`) and print the base64 Ed25519 detached
     * signature on its own line, so the publisher proves they hold the private key of the public key they register.
     * Only that text format is signed, so the key cannot be used to sign arbitrary data through this option.
     */
    private function signChallenge(string $keyPath, string $challenge): int
    {
        if (preg_match('/^ispa-publisher-key-v1:[0-9a-f-]{36}:[0-9a-f]{16}:[A-Za-z0-9_-]{20,100}$/', trim($challenge)) !== 1) {
            return $this->refuse('This is not a hub key challenge. Copy the whole check text shown on the hub.');
        }
        try {
            $secret = ReleaseKeyFile::readSecret($keyPath);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }
        try {
            $signature = base64_encode(sodium_crypto_sign_detached(trim($challenge), $secret));
        } finally {
            sodium_memzero($secret);
        }
        $this->line($signature);

        return self::SUCCESS;
    }

    /**
     * Print a refusal and return the failure exit code.
     */
    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
