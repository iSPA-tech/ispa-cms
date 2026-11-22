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
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Builds the hub package record of an iSPA CMS core release (`kind: core`) and signs it.
 *
 * The package zip is the release zip itself (`dist/ispa-cms-<version>.zip`, made by the release script). The signed
 * manifest binds that zip by size and sha256 and lists one file, its root `core-manifest.json`, whose own hashes carry
 * the integrity list. The signer and the key rules are those of {@see ModulePackageSignCommand}: Ed25519 key file mode
 * 0600 outside the repository (`--key-file`, or the `ISPA_RELEASE_SIGNING_KEY_FILE` environment variable the release
 * script passes on), never printed. The version option is `--release-version` because `--version` belongs to artisan itself. Publishing on the hub stays the owner's step (`cms:platform-publish`).
 */
class CorePackageBuildCommand extends Command
{
    private const INNER_MANIFEST = 'core-manifest.json';
    private const MAX_INNER_BYTES = 8 * 1024 * 1024;

    protected $signature = 'cms:core-package-build {zip : Path to the core release zip} {--release-version= : Release version, CalVer like 2026.12.0} {--key-file= : Path to release-signing.key (mode 0600, outside the repository); default env ISPA_RELEASE_SIGNING_KEY_FILE}';

    protected $description = 'Sign the hub package manifest (kind core) of an iSPA CMS release zip.';

    /**
     * Validate zip, version and key, then write <zip dir>/ispa-cms-<version>.sig.json; every refusal exits non-zero.
     */
    public function handle(PackageSigner $signer): int
    {
        $version = trim((string) $this->option('release-version'));
        if (preg_match(ReleaseManifest::VERSION_PATTERN, $version) !== 1) {
            return $this->refuse('--package-version is required and must look like 2026.12.0.');
        }
        $zipPath = realpath((string) $this->argument('zip'));
        if ($zipPath === false || !is_file($zipPath) || is_link($zipPath)) {
            return $this->refuse('Zip not found: ' . $this->argument('zip'));
        }
        $inner = $this->readInnerManifest($zipPath);
        if (is_string($inner)) {
            return $this->refuse($inner);
        }
        if (($inner['version'] ?? null) !== $version) {
            return $this->refuse(self::INNER_MANIFEST . ' in the zip is for version ' . (is_string($inner['version'] ?? null) ? $inner['version'] : '?') . ', not ' . $version . '.');
        }
        $phpMin = is_string($inner['php_min'] ?? null) && preg_match('/\d+\.\d+(\.\d+)?/', $inner['php_min'], $m) === 1 ? $m[0] : null;
        if ($phpMin === null) {
            return $this->refuse(self::INNER_MANIFEST . ' has no usable php_min.');
        }

        $secret = $this->readSecret();
        if (is_string($secret) === false) {
            return self::FAILURE;
        }

        try {
            $keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey_from_secretkey($secret));
            $bytes = json_encode($this->manifest($zipPath, $version, $phpMin, $keyId, (string) $inner['_sha256']), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            try {
                ReleaseManifest::fromVerifiedBytes($bytes);
            } catch (PackageVerificationException $e) {
                return $this->refuse('Manifest is not valid: ' . $e->getMessage());
            }
            $sigPath = dirname($zipPath) . '/' . ReleaseManifest::CORE_NAME . '-' . $version . '.sig.json';
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

        $this->info('Signed core ' . $version . ' with key ' . $keyId . ': ' . $sigPath);

        return self::SUCCESS;
    }

    /**
     * The manifest fields in canonical order, `kind` last.
     *
     * @return array<string, mixed>
     */
    private function manifest(string $zipPath, string $version, string $phpMin, string $keyId, string $innerSha): array
    {
        $unpacked = 0;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $unpacked += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $zip->close();
        }

        return [
            'format' => 1,
            'name' => ReleaseManifest::CORE_NAME,
            'version' => $version,
            'zip_sha256' => (string) hash_file('sha256', $zipPath),
            'zip_size' => (int) filesize($zipPath),
            'unpacked_size' => $unpacked,
            'files' => [self::INNER_MANIFEST => $innerSha],
            'requires' => (object) [],
            'irreversible' => false,
            'custom_code' => false,
            'php_min' => $phpMin,
            'built_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'key_id' => $keyId,
            'kind' => ReleaseManifest::KIND_CORE,
        ];
    }

    /**
     * Decode the zip's root core-manifest.json, adding its sha256 as `_sha256`; a string is the refusal message.
     *
     * @return array<string, mixed>|string
     */
    private function readInnerManifest(string $zipPath): array|string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return 'The zip cannot be opened: ' . $zipPath;
        }
        try {
            $stat = $zip->statName(self::INNER_MANIFEST);
            if ($stat === false || $stat['size'] > self::MAX_INNER_BYTES) {
                return 'The zip has no ' . self::INNER_MANIFEST . ' at its root (or it is too large).';
            }
            $bytes = $zip->getFromName(self::INNER_MANIFEST);
        } finally {
            $zip->close();
        }
        $data = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($data) || array_is_list($data)) {
            return self::INNER_MANIFEST . ' in the zip is not a JSON object.';
        }
        $data['_sha256'] = hash('sha256', $bytes);

        return $data;
    }

    /**
     * Read the secret key under the key file rules of cms:module-package-sign; null after printing a refusal.
     */
    private function readSecret(): string|false
    {
        $keyOption = trim((string) ($this->option('key-file') ?: getenv('ISPA_RELEASE_SIGNING_KEY_FILE') ?: ''));
        if ($keyOption === '') {
            $this->refuse('--key-file (or ISPA_RELEASE_SIGNING_KEY_FILE) is required.');

            return false;
        }
        $keyPath = realpath($keyOption);
        if ($keyPath === false || !is_file($keyPath)) {
            $this->refuse('Key file not found: ' . $keyOption);

            return false;
        }
        $repo = realpath(base_path());
        if ($repo === false || str_starts_with($keyPath, rtrim($repo, '/') . '/')) {
            $this->refuse('Refusing a key file inside the repository: ' . $keyPath);

            return false;
        }
        clearstatcache(true, $keyPath);
        if ((fileperms($keyPath) & 0777) !== 0600) {
            $this->refuse(sprintf('Key file must be mode 0600 (found %04o): %s', fileperms($keyPath) & 0777, $keyPath));

            return false;
        }
        $encoded = file_get_contents($keyPath);
        $secret = $encoded === false ? false : base64_decode(trim($encoded), true);
        if ($encoded !== false) {
            sodium_memzero($encoded);
        }
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->refuse('Key file does not hold a base64 Ed25519 secret key.');

            return false;
        }

        return $secret;
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
