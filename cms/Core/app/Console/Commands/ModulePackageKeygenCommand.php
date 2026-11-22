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
use Illuminate\Console\Command;

/**
 * Generates the Ed25519 module release-signing keypair into a directory outside the repository.
 *
 * The secret key is written base64-encoded to <out>/release-signing.key (mode 0600) and is never printed; only the
 * public key and its key id are shown so the owner can add them to config module_packages.trusted_keys.
 */
class ModulePackageKeygenCommand extends Command
{
    public const KEY_FILE = 'release-signing.key';

    protected $signature = 'cms:module-package-keygen {--out= : Directory outside the repository that receives release-signing.key}';

    protected $description = 'Create the module package signing keypair (secret stays on the release machine).';

    /**
     * Create the keypair; every refusal exits non-zero without writing.
     */
    public function handle(): int
    {
        $out = trim((string) $this->option('out'));
        if ($out === '') {
            $this->error('--out is required: a directory outside the repository.');

            return self::FAILURE;
        }

        $dir = $this->resolveDirectory($out);
        if ($dir === null) {
            $this->error('Cannot resolve --out: its parent directory must exist.');

            return self::FAILURE;
        }
        $repo = realpath(base_path());
        if ($repo === false || $dir === $repo || str_starts_with($dir, rtrim($repo, '/') . '/')) {
            $this->error('Refusing to write a signing key inside the repository: ' . $dir);

            return self::FAILURE;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->error('Cannot create directory ' . $dir);

            return self::FAILURE;
        }
        $keyPath = $dir . '/' . self::KEY_FILE;
        if (file_exists($keyPath) || is_link($keyPath)) {
            $this->error('Key file already exists, refusing to overwrite: ' . $keyPath);

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $encoded = base64_encode($secret);
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($keyPath, 'x');
            if ($handle === false) {
                $this->error('Cannot create key file ' . $keyPath);

                return self::FAILURE;
            }
            chmod($keyPath, 0600);
            $written = fwrite($handle, $encoded . "\n");
            fclose($handle);
            if ($written !== strlen($encoded) + 1) {
                @unlink($keyPath);
                $this->error('Writing the key file failed; nothing kept.');

                return self::FAILURE;
            }
        } finally {
            umask($previousUmask);
            sodium_memzero($pair);
            sodium_memzero($secret);
            sodium_memzero($encoded);
        }

        $this->info('Secret key written to ' . $keyPath . ' (mode 0600). Keep it offline; never commit or upload it.');
        $this->line('key_id:     ' . KeyId::fromPublicKey($public));
        $this->line('public_key: ' . base64_encode($public));
        $this->line('iSPA release key: add both to `trusted_keys` in cms/Core/config/module_packages.php in a reviewed commit.');
        $this->line('Hub countersign key (partner packages): add both to the `$countersignKeys` list at the top of cms/Core/config/module_packages.php instead, and set the secret only in the hub .env PLATFORM_HUB_COUNTERSIGN_SECRET.');

        return self::SUCCESS;
    }

    /**
     * Real absolute path of the target directory, resolving the parent when the directory does not exist yet.
     */
    private function resolveDirectory(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        $path = rtrim($path, '/');
        $real = realpath($path);
        if ($real !== false) {
            return is_dir($real) ? $real : null;
        }
        $base = basename($path);
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }
        $parent = realpath(dirname($path));

        return ($parent === false || !is_dir($parent)) ? null : rtrim($parent, '/') . '/' . $base;
    }
}
