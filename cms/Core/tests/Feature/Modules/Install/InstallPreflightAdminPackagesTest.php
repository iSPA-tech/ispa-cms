<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * the install preflight refuses a package whose admin code needs a shared admin package the site's admin
 * shell does not provide (MODULE_ADMIN_PACKAGE_MISSING), and a package styled by a project theme
 * (MODULE_THEME_COUPLED). Manifests are built from bytes directly (the signature is not under test); the shell list is
 * a temp file. Nothing leaves the process.
 */
beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/preflight-p11-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->releaseDir = $this->tmp.'/release';
    $this->runtimeDir = $this->tmp.'/runtime';
    mkdir($this->releaseDir, 0755);
    mkdir($this->runtimeDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);

    $this->shellFile = $this->tmp.'/shared-packages.json';
    config([
        'core.module_packages.install_enabled' => true,
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'core.module_packages.max_zip_bytes' => 1024,
        'core.module_packages.shared_admin_packages_file' => $this->shellFile,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);
});

afterEach(function () {
    RuntimeModules::resetPath();
    p11pfRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function p11pfRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            p11pfRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Write a shell list file (format 1) with the given name => major packages.
 *
 * @param array<string, int> $packages
 */
function p11pfShell(string $path, array $packages): void
{
    @mkdir(dirname($path), 0755, true);
    $list = [];
    foreach ($packages as $name => $major) {
        $list[$name] = ['major' => $major, 'used_by' => ['*']];
    }
    file_put_contents($path, json_encode(['format' => 1, 'packages' => $list]));
}

/**
 * A verified-shape release manifest of module "Probe" with the given optional fields.
 *
 * @param array<string, mixed> $extra
 */
function p11pfManifest(array $extra = []): ReleaseManifest
{
    $files = ['Probe/module.json' => str_repeat('b', 64)];
    if (isset($extra['frontend'])) {
        $files['Probe/frontend-build/manifest.json'] = str_repeat('c', 64);
    }

    return ReleaseManifest::fromVerifiedBytes(json_encode([
        'format' => 1, 'name' => 'Probe', 'version' => '2026.1.0',
        'zip_sha256' => str_repeat('a', 64), 'zip_size' => 10, 'unpacked_size' => 10,
        'files' => $files,
        'requires' => [], 'irreversible' => false, 'custom_code' => true, 'php_min' => '8.1',
        'built_at' => '2026-11-13T10:00:00Z', 'key_id' => '0123456789abcdef',
    ] + $extra, JSON_UNESCAPED_SLASHES));
}

/**
 * A preflight whose environment is healthy.
 */
function p11pfPreflight(object $t): InstallPreflight
{
    return new InstallPreflight(
        new ModuleCatalog(null, $t->releaseDir),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
        fn (string $k): string => '0',
    );
}

test('a shared admin package missing from the shell blocks and the block names the package', function () {
    p11pfShell($this->shellFile, ['vue-draggable-plus' => 0, '@vueuse/core' => 14]);

    $result = p11pfPreflight($this)->checkUpload(p11pfManifest(['admin_packages' => ['@vueuse/core' => 14, 'vuedraggable' => 4]]));
    $block = collect($result->blocking)->firstWhere('code', InstallPreflight::ADMIN_PACKAGE_MISSING);

    expect($result->canInstall())->toBeFalse()
        ->and($block)->not->toBeNull()
        ->and($block['details']['packages'])->toBe(['vuedraggable'])
        ->and($block['details']['missing'])->toBe([['name' => 'vuedraggable', 'required_major' => 4, 'shell_major' => null]])
        ->and($block['message'])->toContain('vuedraggable');
});

test('a shared admin package with another major in the shell blocks', function () {
    p11pfShell($this->shellFile, ['vuedraggable' => 2, '@vueuse/core' => 14]);

    $block = collect(p11pfPreflight($this)->checkUpload(p11pfManifest(['admin_packages' => ['vuedraggable' => 4, '@vueuse/core' => 14]]))->blocking)
        ->firstWhere('code', InstallPreflight::ADMIN_PACKAGE_MISSING);

    expect($block['details']['packages'])->toBe(['vuedraggable'])
        ->and($block['details']['missing'])->toBe([['name' => 'vuedraggable', 'required_major' => 4, 'shell_major' => 2]]);
});

test('a site without a shell list blocks every package that declares admin packages', function (?string $content) {
    if ($content !== null) {
        file_put_contents($this->shellFile, $content);
    }

    $block = collect(p11pfPreflight($this)->checkUpload(p11pfManifest(['admin_packages' => ['vuedraggable' => 4, '@vueuse/core' => 14]]))->blocking)
        ->firstWhere('code', InstallPreflight::ADMIN_PACKAGE_MISSING);

    expect($block)->not->toBeNull()
        ->and($block['details']['packages'])->toBe(['@vueuse/core', 'vuedraggable'])
        ->and($block['details']['shell_list'])->toBeFalse();
})->with([
    'no file' => [null],
    'not JSON' => ['{nope'],
    'wrong format' => [json_encode(['format' => 2, 'packages' => ['vuedraggable' => ['major' => 4]]])],
]);

test('a shell that provides every package with the same major does not block', function () {
    p11pfShell($this->shellFile, ['vuedraggable' => 4, '@vueuse/core' => 14, 'axios' => 1]);

    $result = p11pfPreflight($this)->checkUpload(p11pfManifest(['admin_packages' => ['vuedraggable' => 4, '@vueuse/core' => 14]]));

    expect($result->blockingCodes())->toBe([])
        ->and($result->canInstall())->toBeTrue();
});

test('a package without admin packages is not checked, even on a site without a shell list', function () {
    expect(is_file($this->shellFile))->toBeFalse();

    $result = p11pfPreflight($this)->checkUpload(p11pfManifest());

    expect($result->blockingCodes())->toBe([]);
});

test('a project-theme manifest is blocked; module and none styles are not', function (string $styles, bool $blocked) {
    $result = p11pfPreflight($this)->checkUpload(p11pfManifest([
        'frontend' => ['entries' => ['resources/assets/js/app.js'], 'styles' => $styles, 'build' => 'frontend-build'],
    ]));

    expect(in_array(InstallPreflight::THEME_COUPLED, $result->blockingCodes(), true))->toBe($blocked);
})->with([
    'project-theme' => ['project-theme', true],
    'module' => ['module', false],
    'none' => ['none', false],
]);

test('nothing in the preflight result carries a file path of the site', function () {
    p11pfShell($this->shellFile, ['@vueuse/core' => 13]);

    $json = json_encode(p11pfPreflight($this)->checkUpload(p11pfManifest([
        'admin_packages' => ['vuedraggable' => 4, '@vueuse/core' => 14],
        'frontend' => ['entries' => ['resources/assets/js/app.js'], 'styles' => 'project-theme', 'build' => 'frontend-build'],
    ]))->toArray(), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain(InstallPreflight::ADMIN_PACKAGE_MISSING)
        ->and($json)->toContain(InstallPreflight::THEME_COUPLED)
        ->and($json)->not->toContain($this->tmp)
        ->and($json)->not->toContain('shared-packages.json')
        ->and($json)->not->toContain(base_path());
});

test('the shell list comes from the admin workspace of a deployed release, then the release and workspace layouts', function () {
    config(['core.module_packages.shared_admin_packages_file' => null]);
    $site = $this->tmp.'/site';
    $release = $site.'/releases/20261113';
    mkdir($release, 0755, true);

    // Nothing in any layout: no list.
    expect(InstallPreflight::shellAdminPackages($release))->toBeNull();

    // Workspace layout (../base-admin beside the code).
    p11pfShell($site.'/releases/base-admin/src/config/shared-packages.json', ['axios' => 1]);
    expect(InstallPreflight::shellAdminPackages($release))->toBe(['axios' => 1]);

    // Release layout (admin/ beside cms/) wins over the workspace layout.
    p11pfShell($release.'/admin/src/config/shared-packages.json', ['axios' => 2]);
    expect(InstallPreflight::shellAdminPackages($release))->toBe(['axios' => 2]);

    // A deployed release deletes admin/; the runtime admin workspace is the shell's source and wins.
    p11pfShell($site.'/runtime/admin-workspace/admin/src/config/shared-packages.json', ['axios' => 3]);
    expect(InstallPreflight::shellAdminPackages($release))->toBe(['axios' => 3]);
});

test('a configured shell list file is the only source', function () {
    config(['core.module_packages.shared_admin_packages_file' => $this->tmp.'/missing.json']);
    $release = $this->tmp.'/release-layout';
    p11pfShell($release.'/admin/src/config/shared-packages.json', ['axios' => 1]);

    expect(InstallPreflight::shellAdminPackages($release))->toBeNull();

    p11pfShell($this->tmp.'/missing.json', ['axios' => 5]);
    expect(InstallPreflight::shellAdminPackages($release))->toBe(['axios' => 5]);
});
