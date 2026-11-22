<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Support\ModuleAssets;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite as ViteFacade;

/**
 * `@moduleVite` renders a module's entries from its runtime-published build when there is one, else from the
 * release root manifest, and never changes the global Vite instance. Runs on a temp public folder.
 *
 * (F3): the runtime build is used only while the runtime copy is the one the site loads, i.e. the runtime
 * modules folder holds the module and the release modules folder does not. The temp folders `release/` and `runtime/`
 * stand in for `cms/` and `runtime-modules/`; Probe starts as a runtime module.
 */
beforeEach(function () {
    $base = sys_get_temp_dir() . '/module-assets-' . bin2hex(random_bytes(6));
    mkdir($base . '/build/assets', 0755, true);
    mkdir($base . '/runtime-assets/Probe/2026.11.12/assets', 0755, true);
    $this->tmp = realpath($base);
    mkdir($this->tmp . '/release', 0755);
    mkdir($this->tmp . '/runtime/Probe', 0755, true);
    config(['modules.paths.modules' => $this->tmp . '/release']);
    RuntimeModules::setPath($this->tmp . '/runtime');

    file_put_contents($this->tmp . '/build/manifest.json', json_encode([
        'cms/Probe/resources/app.js' => ['file' => 'assets/release-abc.js', 'src' => 'cms/Probe/resources/app.js', 'isEntry' => true],
        'resources/js/site.js' => ['file' => 'assets/site-123.js', 'src' => 'resources/js/site.js', 'isEntry' => true],
    ]));
    file_put_contents($this->tmp . '/runtime-assets/Probe/2026.11.12/manifest.json', json_encode([
        'resources/app.js' => ['file' => 'assets/runtime-def.js', 'src' => 'resources/app.js', 'isEntry' => true, 'css' => ['assets/runtime-def.css']],
    ]));

    app()->usePublicPath($this->tmp);
    app()->forgetInstance(Vite::class);
    ModuleAssets::flush();
});

afterEach(function () {
    ModuleAssets::flush();
    RuntimeModules::resetPath();
    File::deleteDirectory($this->tmp);
});

/**
 * Record an installed version for a module.
 */
function moduleAssetsSetVersion(string $name, ?string $version): void
{
    DB::table(DatabaseActivator::TABLE)->updateOrInsert(['name' => $name], ['enabled' => true, 'version' => $version]);
}

it('renders a release module like @vite of its root-manifest entry', function () {
    $expected = (string) app(Vite::class)(['cms/Probe/resources/app.js']);

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toBe($expected)
        ->and($expected)->toContain('assets/release-abc.js');
});

it('renders a runtime module from its published build', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');

    $html = (string) ModuleAssets::tags('Probe', ['resources/app.js']);

    expect($html)->toContain('/runtime-assets/Probe/2026.11.12/assets/runtime-def.js')
        ->and($html)->toContain('/runtime-assets/Probe/2026.11.12/assets/runtime-def.css')
        ->and($html)->not->toContain('release-abc');
});

it('falls back to the release build when the published build has no manifest', function () {
    moduleAssetsSetVersion('Probe', '2026.11.13');

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toContain('assets/release-abc.js');
});

it('leaves the global Vite state alone so root entries still render after a runtime module', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');
    $nonce = ViteFacade::useCspNonce('fixed-nonce');

    $runtime = (string) ModuleAssets::tags('Probe', ['resources/app.js']);
    $root = (string) ViteFacade::withEntryPoints(['resources/js/site.js'])->toHtml();

    expect($runtime)->toContain('/runtime-assets/Probe/2026.11.12/')
        ->and($root)->toContain('/build/assets/site-123.js')
        ->and($root)->not->toContain('runtime-assets')
        ->and(ViteFacade::cspNonce())->toBe($nonce);
});

it('carries the CSP nonce of the page over to runtime tags', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');
    ViteFacade::useCspNonce('page-nonce');

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toContain('nonce="page-nonce"');
});

it('throws the Vite exception for an unknown entry from either source', function () {
    expect(fn () => ModuleAssets::tags('Probe', ['resources/missing.js']))->toThrow(ViteException::class);

    moduleAssetsSetVersion('Probe', '2026.11.12');
    ModuleAssets::flush();

    expect(fn () => ModuleAssets::tags('Probe', ['resources/missing.js']))->toThrow(ViteException::class);
});

it('registers the moduleVite Blade directive', function () {
    $html = Blade::render("@moduleVite('Probe', ['resources/app.js'])");

    expect($html)->toContain('assets/release-abc.js');
});

it('serves the release copy when the release ships the module again and the release copy wins ( F3)', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');
    // deploy.sh without RUNTIME_MODULES_WIN: the release copy is back in cms/, the runtime copy was moved aside, but the
    // status row keeps the online version and its published build stays on disk.
    mkdir($this->tmp . '/release/Probe', 0755);
    rmdir($this->tmp . '/runtime/Probe');

    $html = (string) ModuleAssets::tags('Probe', ['resources/app.js']);

    expect($html)->toContain('/build/assets/release-abc.js')
        ->and($html)->not->toContain('runtime-assets');
});

it('serves the release copy while both copies exist, because the release folder wins', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');
    mkdir($this->tmp . '/release/Probe', 0755);

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toContain('assets/release-abc.js')
        ->and((string) ModuleAssets::tags('Probe', ['resources/app.js']))->not->toContain('runtime-assets');
});

it('serves the runtime build only while the runtime copy is the loaded one', function () {
    moduleAssetsSetVersion('Probe', '2026.11.12');
    rmdir($this->tmp . '/runtime/Probe');

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toContain('assets/release-abc.js');

    // RUNTIME_MODULES_WIN: the release does not ship it, the runtime folder holds it.
    mkdir($this->tmp . '/runtime/Probe', 0755);
    ModuleAssets::flush();

    expect((string) ModuleAssets::tags('Probe', ['resources/app.js']))->toContain('/runtime-assets/Probe/2026.11.12/assets/runtime-def.js');
});
