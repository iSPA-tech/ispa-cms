<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\FrontendBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Symfony\Component\Process\ExecutableFinder;

uses(Cms\Core\Tests\TestCase::class);

const PKGFE_KEY = '0123456789abcdef';

/**
 * Why the real Vite build cannot run here, or null when it can (pnpm on PATH and the root node_modules installed).
 */
function pkgfeSkipReason(): ?string
{
    if ((new ExecutableFinder())->find('pnpm') === null) {
        return 'pnpm is not on PATH; the front-end package build needs Node and pnpm.';
    }
    if (!is_dir(dirname(__DIR__, 6) . '/node_modules/vite')) {
        return 'node_modules/vite is missing in the ispa-cms root; run pnpm install.';
    }

    return null;
}

/**
 * Remove a temp tree (symlinks are unlinked, never followed).
 */
function pkgfeRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        pkgfeRemove($path . '/' . $entry);
    }
    rmdir($path);
}

/**
 * Create a temp root holding a fixture module with one tiny JS entry (plus a relative import) and one CSS entry.
 *
 * @param array<string, mixed>  $module module.json overrides (null values remove the key)
 * @param array<string, string> $extra  extra module files, relative path => contents
 *
 * @return string the temp root (modules under <root>/mods, output under <root>/out)
 */
function pkgfeFixture(array $module = [], array $extra = []): string
{
    $root = sys_get_temp_dir() . '/pkgfe-' . bin2hex(random_bytes(6));
    $dir = $root . '/mods/Demo';
    mkdir($dir . '/app', 0775, true);
    mkdir($root . '/out', 0775, true);
    $manifest = array_merge([
        'name' => 'Demo', 'alias' => 'demo', 'version' => '2026.03.0', 'providers' => [], 'files' => [],
        'frontend' => ['entries' => ['resources/assets/js/app.js', 'resources/assets/css/app.css'], 'styles' => 'module'],
    ], $module);
    file_put_contents($dir . '/module.json', json_encode(array_filter($manifest, static fn ($v) => $v !== null), JSON_PRETTY_PRINT));
    $files = array_merge([
        'app/Thing.php' => "<?php\n// thing\n",
        'resources/assets/js/app.js' => "import { greet } from './greet.js'\ndocument.body.dataset.demo = greet('demo')\n",
        'resources/assets/js/greet.js' => "export function greet(name) {\n    return 'hello ' + name\n}\n",
        'resources/assets/css/app.css' => ".demo-box {\n    color: #17a2b8;\n    padding: 4px;\n}\n",
    ], $extra);
    foreach ($files as $relative => $contents) {
        if (!is_dir(dirname($dir . '/' . $relative))) {
            mkdir(dirname($dir . '/' . $relative), 0775, true);
        }
        file_put_contents($dir . '/' . $relative, $contents);
    }
    file_put_contents($root . '/composer.lock', json_encode(['packages' => [], 'packages-dev' => []]));

    return $root;
}

/**
 * Build the Demo fixture.
 *
 * @return array{zip: string, manifest: string, manifest_data: array<string, mixed>}
 */
function pkgfeBuild(string $root, ?FrontendBuilder $frontend = null): array
{
    return (new PackageBuilder($frontend))->build('Demo', $root . '/mods', $root . '/out', PKGFE_KEY, $root . '/composer.lock');
}

/**
 * Entries of a zip: name => bytes.
 *
 * @return array<string, string>
 */
function pkgfeZipEntries(string $zipPath): array
{
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $out[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $out;
}

/**
 * The refusal code of a build, or null when it built.
 */
function pkgfeRefusal(string $root, ?FrontendBuilder $frontend = null): ?string
{
    try {
        pkgfeBuild($root, $frontend);
    } catch (PackageBuildException $e) {
        return $e->errorCode . ' ' . $e->getMessage();
    }

    return null;
}

it('packages the prebuilt assets under frontend-build with every file in the signed map', function () {
    $root = pkgfeFixture();
    try {
        $r = pkgfeBuild($root);
        $entries = pkgfeZipEntries($r['zip']);

        expect($entries)->toHaveKey('Demo/frontend-build/manifest.json');
        $vite = json_decode($entries['Demo/frontend-build/manifest.json'], true);
        expect($vite)->toHaveKeys(['resources/assets/js/app.js', 'resources/assets/css/app.css'])
            ->and($vite['resources/assets/js/app.js']['isEntry'])->toBeTrue();

        $built = array_filter(array_keys($entries), static fn (string $n): bool => str_starts_with($n, 'Demo/frontend-build/'));
        expect(count($built))->toBeGreaterThanOrEqual(3);
        foreach ($built as $name) {
            expect(pathinfo($name, PATHINFO_EXTENSION))->toBeIn(['js', 'css', 'json']);
            expect($r['manifest_data']['files'][$name] ?? null)->toBe(hash('sha256', $entries[$name]));
        }
        // Every Vite manifest file entry exists in the package, hashed.
        foreach ($vite as $chunk) {
            expect($entries)->toHaveKey('Demo/frontend-build/' . $chunk['file']);
            expect($chunk['file'])->toMatch('/-[A-Za-z0-9_-]{6,}\.(js|css)$/');
        }
        // Every zip entry is in the files map and nothing else is.
        expect(array_keys($r['manifest_data']['files']))->toEqualCanonicalizing(array_keys($entries));

        expect($r['manifest_data']['frontend'])->toBe([
            'entries' => ['resources/assets/js/app.js', 'resources/assets/css/app.css'],
            'styles' => 'module',
            'build' => 'frontend-build',
        ]);
        $manifest = ReleaseManifest::fromVerifiedBytes((string) file_get_contents($r['manifest']));
        expect($manifest->frontend)->toBe($r['manifest_data']['frontend'])
            ->and($manifest->toArray()['frontend'])->toBe($r['manifest_data']['frontend']);
        // The source tree is not touched (no cache or node_modules folder left in the module).
        expect(is_dir($root . '/mods/Demo/node_modules'))->toBeFalse()
            ->and(is_dir($root . '/mods/Demo/frontend-build'))->toBeFalse();
    } finally {
        pkgfeRemove($root);
    }
})->skip(fn () => pkgfeSkipReason() !== null, (string) pkgfeSkipReason());

it('produces identical zip bytes on two builds of the same tree', function () {
    $root = pkgfeFixture();
    try {
        $first = pkgfeBuild($root);
        $bytes = (string) file_get_contents($first['zip']);
        $files = $first['manifest_data']['files'];
        $second = pkgfeBuild($root);

        expect(hash('sha256', (string) file_get_contents($second['zip'])))->toBe(hash('sha256', $bytes))
            ->and($second['manifest_data']['files'])->toBe($files);
    } finally {
        pkgfeRemove($root);
    }
})->skip(fn () => pkgfeSkipReason() !== null, (string) pkgfeSkipReason());

it('leaves no absolute path of the builder and no APP_URL in the built files', function () {
    $root = pkgfeFixture([], [
        'resources/assets/js/app.js' => "import logo from '../img/logo.svg'\nimport { greet } from './greet.js'\ndocument.body.dataset.demo = greet(logo)\n",
        'resources/assets/img/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>',
        'resources/assets/css/app.css' => ".demo-box {\n    background: url('../img/logo.svg');\n}\n",
    ]);
    try {
        $r = pkgfeBuild($root);
        $needles = array_filter([realpath($root), $root, base_path(), (string) config('app.url'), sys_get_temp_dir()]);
        foreach (pkgfeZipEntries($r['zip']) as $name => $bytes) {
            if (!str_starts_with($name, 'Demo/frontend-build/')) {
                continue;
            }
            foreach ($needles as $needle) {
                expect(str_contains($bytes, $needle))->toBeFalse($name . ' contains ' . $needle);
            }
        }
        $vite = json_decode(pkgfeZipEntries($r['zip'])['Demo/frontend-build/manifest.json'], true);
        expect(json_encode($vite))->not->toContain('://');
        expect(collect(array_keys(pkgfeZipEntries($r['zip'])))->filter(fn ($n) => str_starts_with($n, "Demo/frontend-build/") && str_ends_with($n, ".svg"))->count())->toBe(1);
    } finally {
        pkgfeRemove($root);
    }
})->skip(fn () => pkgfeSkipReason() !== null, (string) pkgfeSkipReason());

it('refuses a built file with a disallowed extension', function (string $extension) {
    $root = pkgfeFixture([], [
        'resources/assets/js/app.js' => "import page from './page.{$extension}?url'\ndocument.body.dataset.page = page\n",
        "resources/assets/js/page.{$extension}" => 'x',
    ]);
    try {
        $refusal = pkgfeRefusal($root);
        expect($refusal)->toStartWith(PackageBuildException::FRONTEND_BUILD)->toContain('.' . $extension);
    } finally {
        pkgfeRemove($root);
    }
})->with(['map', 'html', 'php'])->skip(fn () => pkgfeSkipReason() !== null, (string) pkgfeSkipReason());

it('reports the built front-end files through the artisan command', function () {
    $root = pkgfeFixture();
    try {
        $this->artisan('cms:module-package-build', ['module' => 'Demo', '--modules-path' => $root . '/mods', '--out' => $root . '/out', '--key-id' => PKGFE_KEY])
            ->expectsOutputToContain('Frontend: ')
            ->assertSuccessful();
    } finally {
        pkgfeRemove($root);
    }
})->skip(fn () => pkgfeSkipReason() !== null, (string) pkgfeSkipReason());

it('refuses distribution deploy with the module reason', function () {
    $root = pkgfeFixture(['distribution' => 'deploy', 'distribution_reason' => 'Ships with its website theme.']);
    try {
        $refusal = pkgfeRefusal($root);
        expect($refusal)->toStartWith(PackageBuildException::DEPLOY_ONLY)->toContain('Ships with its website theme.');

        expect(fn () => (new PackageBuilder())->check('Demo', $root . '/mods', $root . '/composer.lock'))
            ->toThrow(PackageBuildException::class);
    } finally {
        pkgfeRemove($root);
    }
});

it('still refuses vite.config.js when the module declares frontend entries', function () {
    $root = pkgfeFixture([], ['vite.config.js' => "export default {}\n"]);
    try {
        expect(pkgfeRefusal($root))->toStartWith(PackageBuildException::VITE_CONFIG);
    } finally {
        pkgfeRemove($root);
    }
});

it('refuses a module that ships its own frontend-build folder', function () {
    $root = pkgfeFixture(['frontend' => null], ['frontend-build/manifest.json' => '{}']);
    try {
        expect(pkgfeRefusal($root))->toStartWith(PackageBuildException::FRONTEND_BUILD);
    } finally {
        pkgfeRemove($root);
    }
});

it('refuses a declared entry that does not exist', function () {
    $root = pkgfeFixture(['frontend' => ['entries' => ['resources/assets/js/missing.js'], 'styles' => 'none']]);
    try {
        $refusal = pkgfeRefusal($root, new FrontendBuilder('/nonexistent/pnpm'));
        expect($refusal)->toStartWith(PackageBuildException::FRONTEND_BUILD)->toContain('resources/assets/js/missing.js');
    } finally {
        pkgfeRemove($root);
    }
});

it('fails with a clear message when pnpm is missing', function () {
    $root = pkgfeFixture();
    try {
        $refusal = pkgfeRefusal($root, new FrontendBuilder('/nonexistent/pnpm'));
        expect($refusal)->toStartWith(PackageBuildException::FRONTEND_BUILD)->toContain('pnpm');
        expect(glob($root . '/out/*') ?: [])->toBe([]);
    } finally {
        pkgfeRemove($root);
    }
});

it('builds a module without frontend exactly as before, without running a front-end build', function () {
    $root = pkgfeFixture(['frontend' => null], ['resources/assets/js/app.js' => "console.log('x')\n"]);
    try {
        // A missing pnpm would fail any front-end build, so a successful build proves none ran.
        $r = pkgfeBuild($root, new FrontendBuilder('/nonexistent/pnpm'));
        expect(array_keys($r['manifest_data']))->toBe(ReleaseManifest::FIELDS);
        expect(array_filter(array_keys(pkgfeZipEntries($r['zip'])), fn ($n) => str_contains($n, 'frontend-build')))->toBe([]);
        expect(ReleaseManifest::fromVerifiedBytes((string) file_get_contents($r['manifest']))->frontend)->toBeNull();
    } finally {
        pkgfeRemove($root);
    }
});

it('builds a module whose frontend declares no entries without a front-end build', function () {
    $root = pkgfeFixture(['frontend' => ['entries' => [], 'styles' => 'none']]);
    try {
        $r = pkgfeBuild($root, new FrontendBuilder('/nonexistent/pnpm'));
        expect(array_keys($r['manifest_data']))->toBe(ReleaseManifest::FIELDS);
    } finally {
        pkgfeRemove($root);
    }
});

/**
 * A valid manifest array carrying a frontend field.
 *
 * @return array<string, mixed>
 */
function pkgfeManifest(mixed $frontend, bool $withViteManifest = true): array
{
    $files = ['Demo/app/Thing.php' => str_repeat('a', 64)];
    if ($withViteManifest) {
        $files['Demo/frontend-build/manifest.json'] = str_repeat('b', 64);
        $files['Demo/frontend-build/assets/app-AbC123xy.js'] = str_repeat('c', 64);
    }

    return [
        'format' => 1, 'name' => 'Demo', 'version' => '2026.03.0', 'zip_sha256' => str_repeat('d', 64), 'zip_size' => 10,
        'unpacked_size' => 10, 'files' => $files, 'requires' => [], 'irreversible' => false, 'custom_code' => false,
        'php_min' => '8.3', 'built_at' => '2026-11-13T10:00:00+07:00', 'key_id' => PKGFE_KEY, 'frontend' => $frontend,
    ];
}

it('reads a valid frontend field', function () {
    $frontend = ['entries' => ['resources/assets/js/app.js'], 'styles' => 'none', 'build' => 'frontend-build'];
    $manifest = ReleaseManifest::fromVerifiedBytes((string) json_encode(pkgfeManifest($frontend)));

    expect($manifest->frontend)->toBe($frontend)
        ->and(array_keys($manifest->toArray()))->toBe([...ReleaseManifest::FIELDS, 'frontend']);
});

it('refuses a malformed frontend field', function (mixed $frontend, bool $withViteManifest = true) {
    expect(fn () => ReleaseManifest::fromVerifiedBytes((string) json_encode(pkgfeManifest($frontend, $withViteManifest))))
        ->toThrow(PackageVerificationException::class);
})->with([
    'not an object' => [['resources/assets/js/app.js']],
    'unknown key' => [['entries' => ['resources/a.js'], 'styles' => 'none', 'build' => 'frontend-build', 'x' => 1]],
    'missing build' => [['entries' => ['resources/a.js'], 'styles' => 'none']],
    'other build folder' => [['entries' => ['resources/a.js'], 'styles' => 'none', 'build' => 'public']],
    'bad styles' => [['entries' => ['resources/a.js'], 'styles' => 'tailwind', 'build' => 'frontend-build']],
    'no entries' => [['entries' => [], 'styles' => 'none', 'build' => 'frontend-build']],
    'entry outside resources' => [['entries' => ['app/x.js'], 'styles' => 'none', 'build' => 'frontend-build']],
    'entry with parent segment' => [['entries' => ['resources/../x.js'], 'styles' => 'none', 'build' => 'frontend-build']],
    'duplicate entries' => [['entries' => ['resources/a.js', 'resources/a.js'], 'styles' => 'none', 'build' => 'frontend-build']],
    'entries not a list' => [['entries' => ['a' => 'resources/a.js'], 'styles' => 'none', 'build' => 'frontend-build']],
    'vite manifest not in files' => [['entries' => ['resources/a.js'], 'styles' => 'none', 'build' => 'frontend-build'], false],
]);
