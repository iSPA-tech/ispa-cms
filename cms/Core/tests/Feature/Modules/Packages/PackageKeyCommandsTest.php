<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Illuminate\Support\Facades\Artisan;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Remove a temp tree created by these tests.
 */
function pkgCmdRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Write a valid manifest for the key id into the temp dir and return its path.
 */
function pkgCmdWriteManifest(string $dir, string $keyId): string
{
    $path = $dir . '/manifest.json';
    file_put_contents($path, json_encode([
        'format' => 1,
        'name' => 'Demo',
        'version' => '2026.03.0',
        'zip_sha256' => hash('sha256', 'zip'),
        'zip_size' => 10,
        'unpacked_size' => 20,
        'files' => ['Demo/module.json' => hash('sha256', 'a')],
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00+07:00',
        'key_id' => $keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

    return $path;
}

/**
 * Write a base64 secret key file with the given mode; return [path, secret, publicB64, keyId].
 *
 * @return array{0: string, 1: string, 2: string, 3: string}
 */
function pkgCmdWriteKey(string $dir, int $mode): array
{
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $path = $dir . '/release-signing.key';
    file_put_contents($path, base64_encode($secret));
    chmod($path, $mode);

    return [$path, $secret, base64_encode($public), KeyId::fromPublicKey($public)];
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/ispa-pkgkey-' . bin2hex(random_bytes(6));
    mkdir($this->tmp, 0700, true);
});

afterEach(function () {
    pkgCmdRemoveDir($this->tmp);
});

it('keygen refuses a path inside the repository and creates nothing', function () {
    $inside = base_path('.tmp-keygen-' . bin2hex(random_bytes(4)));

    $exit = Artisan::call('cms:module-package-keygen', ['--out' => $inside]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('inside the repository')
        ->and(file_exists($inside))->toBeFalse();
});

it('keygen requires --out', function () {
    expect(Artisan::call('cms:module-package-keygen'))->not->toBe(0);
});

it('keygen writes a 0600 key and prints only the public key and key id', function () {
    $out = $this->tmp . '/keys';

    $exit = Artisan::call('cms:module-package-keygen', ['--out' => $out]);
    $output = Artisan::output();
    $keyFile = $out . '/release-signing.key';

    expect($exit)->toBe(0)
        ->and(is_file($keyFile))->toBeTrue()
        ->and(fileperms($keyFile) & 0777)->toBe(0600)
        ->and(fileperms($out) & 0777)->toBe(0700);

    $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
    expect(strlen((string) $secret))->toBe(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);

    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    expect($output)->toContain(base64_encode($public))
        ->and($output)->toContain(KeyId::fromPublicKey($public))
        ->and($output)->not->toContain(base64_encode($secret))
        ->and($output)->not->toContain(trim((string) file_get_contents($keyFile)));

    // the hint names both lists, `trusted_keys` for a release key and `$countersignKeys` for the hub key.
    expect($output)->toContain('`trusted_keys`')->and($output)->toContain('`$countersignKeys`')->and($output)->toContain('cms/Core/config/module_packages.php');
});

it('keygen refuses to overwrite an existing key file', function () {
    file_put_contents($this->tmp . '/release-signing.key', 'old');

    $exit = Artisan::call('cms:module-package-keygen', ['--out' => $this->tmp]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('already exists')
        ->and(file_get_contents($this->tmp . '/release-signing.key'))->toBe('old');
});

it('sign refuses a key file that is not mode 0600', function () {
    [$keyPath, , , $keyId] = pkgCmdWriteKey($this->tmp, 0644);
    $manifest = pkgCmdWriteManifest($this->tmp, $keyId);

    $exit = Artisan::call('cms:module-package-sign', ['manifest' => $manifest, '--key-file' => $keyPath]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('0600')
        ->and(file_exists($this->tmp . '/Demo-2026.03.0.sig.json'))->toBeFalse();
});

it('sign refuses a key file inside the repository', function () {
    [, , , $keyId] = pkgCmdWriteKey($this->tmp, 0600);
    $manifest = pkgCmdWriteManifest($this->tmp, $keyId);

    // Any existing repository file works: the location check runs before reading the key.
    $exit = Artisan::call('cms:module-package-sign', ['manifest' => $manifest, '--key-file' => base_path('composer.json')]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('inside the repository');
});

it('sign refuses a manifest whose key_id is not the signing key', function () {
    [$keyPath] = pkgCmdWriteKey($this->tmp, 0600);
    $manifest = pkgCmdWriteManifest($this->tmp, '0123456789abcdef');

    $exit = Artisan::call('cms:module-package-sign', ['manifest' => $manifest, '--key-file' => $keyPath]);

    expect($exit)->not->toBe(0)
        ->and(file_exists($this->tmp . '/Demo-2026.03.0.sig.json'))->toBeFalse();
});

it('sign writes a sig.json the verifier accepts and never prints the secret', function () {
    [$keyPath, $secret, $publicB64, $keyId] = pkgCmdWriteKey($this->tmp, 0600);
    $manifest = pkgCmdWriteManifest($this->tmp, $keyId);

    $exit = Artisan::call('cms:module-package-sign', ['manifest' => $manifest, '--key-file' => $keyPath]);
    $output = Artisan::output();
    $sigPath = $this->tmp . '/Demo-2026.03.0.sig.json';

    expect($exit)->toBe(0)
        ->and(is_file($sigPath))->toBeTrue()
        ->and($output)->not->toContain(base64_encode($secret));

    $verified = (new PackageVerifier([$keyId => $publicB64], []))->verify((string) file_get_contents($sigPath));
    expect($verified->name)->toBe('Demo')
        ->and(base64_decode(json_decode((string) file_get_contents($sigPath), true)['manifest'], true))
        ->toBe(file_get_contents($manifest));

    // A second run refuses to overwrite.
    expect(Artisan::call('cms:module-package-sign', ['manifest' => $manifest, '--key-file' => $keyPath]))->not->toBe(0);
});
