<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Support\Facades\Artisan;

uses(Cms\Core\Tests\TestCase::class);

/**
 * A valid module manifest as an array; $override replaces fields, a null value removes the field.
 *
 * @param array<string, mixed> $override
 *
 * @return array<string, mixed>
 */
function coreKindModuleManifest(array $override = []): array
{
    $manifest = array_merge([
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
        'key_id' => str_repeat('a', 16),
    ], $override);

    return array_filter($manifest, static fn ($value): bool => $value !== null);
}

/**
 * A valid core manifest as an array.
 *
 * @param array<string, mixed> $override
 *
 * @return array<string, mixed>
 */
function coreKindCoreManifest(array $override = []): array
{
    return coreKindModuleManifest(array_merge([
        'name' => 'ispa-cms',
        'version' => '2026.12.0',
        'files' => ['core-manifest.json' => hash('sha256', 'm')],
        'kind' => 'core',
    ], $override));
}

/**
 * Parse manifest data and return the refusal message (null when accepted).
 *
 * @param array<string, mixed> $data
 */
function coreKindRefusal(array $data): ?string
{
    try {
        ReleaseManifest::fromVerifiedBytes(json_encode($data, JSON_UNESCAPED_SLASHES));
    } catch (PackageVerificationException $e) {
        return $e->getMessage();
    }

    return null;
}

it('a manifest without kind parses as a module', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(coreKindModuleManifest(), JSON_UNESCAPED_SLASHES));

    expect($manifest->kind)->toBe('module')
        ->and($manifest->toArray())->not->toHaveKey('kind');
});

it('an explicit module kind parses as a module', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(coreKindModuleManifest(['kind' => 'module']), JSON_UNESCAPED_SLASHES));

    expect($manifest->kind)->toBe('module');
});

it('a core manifest parses with kind core and keeps it when serialised', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(coreKindCoreManifest(), JSON_UNESCAPED_SLASHES));

    expect($manifest->kind)->toBe('core')
        ->and($manifest->name)->toBe('ispa-cms')
        ->and($manifest->toArray()['kind'])->toBe('core')
        ->and(array_keys($manifest->files))->toBe(['core-manifest.json']);
});

it('refuses an unknown kind', function (mixed $kind) {
    expect(coreKindRefusal(coreKindModuleManifest(['kind' => $kind])))->toContain('kind');
})->with(['theme', 'CORE', '', 7, [['core']]]);

it('keeps core and module names apart', function () {
    expect(coreKindRefusal(coreKindCoreManifest(['name' => 'Demo'])))->toContain('name')
        ->and(coreKindRefusal(coreKindModuleManifest(['name' => 'ispa-cms'])))->toContain('name');
});

it('refuses module-only fields and unsafe paths in a core manifest', function () {
    expect(coreKindRefusal(coreKindCoreManifest(['admin_packages' => ['vue' => 3]])))->toContain('kind core')
        ->and(coreKindRefusal(coreKindCoreManifest(['files' => ['../x' => hash('sha256', 'a')]])))->not->toBeNull()
        ->and(coreKindRefusal(coreKindCoreManifest(['files' => ['/etc/passwd' => hash('sha256', 'a')]])))->not->toBeNull();
});

/**
 * Remove a temp tree created by these tests.
 */
function coreKindRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Write a release-style zip (tree at the zip root with core-manifest.json) and return its path.
 */
function coreKindWriteZip(string $dir, string $version, string $phpMin = '^8.3'): string
{
    $path = $dir . '/ispa-cms-' . $version . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('core-manifest.json', json_encode(['format' => 1, 'version' => $version, 'php_min' => $phpMin, 'checked' => []]));
    $zip->addFromString('artisan', "<?php\n");
    $zip->addFromString('app/Foo.php', "<?php\n");
    $zip->close();

    return $path;
}

describe('cms:core-package-build', function () {
    beforeEach(function () {
        $this->tmp = sys_get_temp_dir() . '/ispa-corebuild-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $pair = sodium_crypto_sign_keypair();
        $this->public = sodium_crypto_sign_publickey($pair);
        $this->keyId = KeyId::fromPublicKey($this->public);
        $this->keyFile = $this->tmp . '/release-signing.key';
        file_put_contents($this->keyFile, base64_encode(sodium_crypto_sign_secretkey($pair)));
        chmod($this->keyFile, 0600);
    });

    afterEach(function () {
        coreKindRemoveDir($this->tmp);
    });

    it('writes a core manifest signed with the module signer that the verifier accepts', function () {
        $zip = coreKindWriteZip($this->tmp, '2026.12.0');

        $exit = Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.0', '--key-file' => $this->keyFile]);

        $sig = $this->tmp . '/ispa-cms-2026.12.0.sig.json';
        expect($exit)->toBe(0)->and(is_file($sig))->toBeTrue();
        $verifier = new PackageVerifier([$this->keyId => base64_encode($this->public)], []);
        $manifest = $verifier->verify((string) file_get_contents($sig));
        expect($manifest->kind)->toBe('core')
            ->and($manifest->name)->toBe('ispa-cms')
            ->and($manifest->version)->toBe('2026.12.0')
            ->and($manifest->zipSha256)->toBe(hash_file('sha256', $zip))
            ->and($manifest->zipSize)->toBe(filesize($zip))
            ->and($manifest->phpMin)->toBe('8.3')
            ->and($manifest->keyId)->toBe($this->keyId)
            ->and($manifest->files)->toBe(['core-manifest.json' => hash('sha256', json_encode(['format' => 1, 'version' => '2026.12.0', 'php_min' => '^8.3', 'checked' => []]))]);
        expect(Artisan::output())->not->toContain('push');
    });

    it('refuses a missing version, a version that disagrees with the zip, a bad key and an existing signature', function () {
        $zip = coreKindWriteZip($this->tmp, '2026.12.0');

        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--key-file' => $this->keyFile]))->not->toBe(0);
        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.1', '--key-file' => $this->keyFile]))->not->toBe(0)
            ->and(Artisan::output())->toContain('core-manifest.json');
        chmod($this->keyFile, 0644);
        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.0', '--key-file' => $this->keyFile]))->not->toBe(0)
            ->and(Artisan::output())->toContain('0600');
        chmod($this->keyFile, 0600);
        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.0', '--key-file' => $this->keyFile]))->toBe(0);
        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.0', '--key-file' => $this->keyFile]))->not->toBe(0)
            ->and(Artisan::output())->toContain('already exists');
    });

    it('refuses a zip without core-manifest.json', function () {
        $zip = $this->tmp . '/ispa-cms-2026.12.0.zip';
        $archive = new ZipArchive();
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('artisan', 'x');
        $archive->close();

        expect(Artisan::call('cms:core-package-build', ['zip' => $zip, '--release-version' => '2026.12.0', '--key-file' => $this->keyFile]))->not->toBe(0)
            ->and(Artisan::output())->toContain('core-manifest.json');
    });
});
