<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Cms\Core\Support\ModuleAssets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * (F5): SVG files of runtime-installed modules are never published as static files, so the web server
 * cannot serve them without headers; the application serves them from the installed module's signed `frontend-build`
 * folder with `X-Content-Type-Options: nosniff` and a Content Security Policy that blocks scripts, only for the version
 * the site loads (the F3 rule of {@see ModuleAssets}: runtime copy loaded, release copy absent).
 *
 * The temp folders `release/` and `runtime/` stand in for `cms/` and `runtime-modules/`; Probe is a runtime module at
 * 2026.11.12 with a published build.
 */
const RMA_VERSION = '2026.11.12';
const RMA_URL = '/runtime-assets/Probe/' . RMA_VERSION . '/assets/icon-abc.svg';
const RMA_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';

beforeEach(function () {
    $base = sys_get_temp_dir() . '/runtime-module-asset-' . bin2hex(random_bytes(6));
    mkdir($base . '/public/runtime-assets/Probe/' . RMA_VERSION . '/assets', 0755, true);
    mkdir($base . '/runtime/Probe/frontend-build/assets', 0755, true);
    mkdir($base . '/release', 0755);
    $this->tmp = realpath($base);
    config(['modules.paths.modules' => $this->tmp . '/release']);
    RuntimeModules::setPath($this->tmp . '/runtime');
    app()->usePublicPath($this->tmp . '/public');
    // The 404 page loads @vite assets; the temp public folder has no build manifest.
    $this->withoutVite();

    file_put_contents($this->tmp . '/public/runtime-assets/Probe/' . RMA_VERSION . '/manifest.json', '{}');
    file_put_contents($this->tmp . '/runtime/Probe/frontend-build/manifest.json', '{}');
    file_put_contents($this->tmp . '/runtime/Probe/frontend-build/assets/icon-abc.svg', RMA_SVG);
    // A file outside the build folder that a crafted path must not reach.
    file_put_contents($this->tmp . '/runtime/Probe/secret.svg', '<svg>outside</svg>');
    DB::table(DatabaseActivator::TABLE)->updateOrInsert(['name' => 'Probe'], ['enabled' => true, 'version' => RMA_VERSION]);
    ModuleAssets::flush();
});

afterEach(function () {
    ModuleAssets::flush();
    RuntimeModules::resetPath();
    RuntimeAssets::resetPath();
    File::deleteDirectory($this->tmp);
});

/**
 * GET over https, as the site forces https outside its own host.
 */
function rmaGet(string $path): Illuminate\Testing\TestResponse
{
    return test()->get('https://' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost') . $path);
}

it('serves a runtime module svg with nosniff and a script-blocking content security policy, without a session', function () {
    $response = rmaGet(RMA_URL);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")
        ->and($csp)->toContain('sandbox')
        ->and($csp)->not->toContain('script-src')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(RMA_SVG)
        ->and($response->headers->getCookies())->toBe([]);
});

it('answers 404 for another version, a release copy that wins, a module not installed or a path outside the build', function () {
    rmaGet('/runtime-assets/Probe/2026.11.11/assets/icon-abc.svg')->assertNotFound();
    rmaGet('/runtime-assets/Ghost/' . RMA_VERSION . '/assets/icon-abc.svg')->assertNotFound();
    rmaGet('/runtime-assets/Probe/' . RMA_VERSION . '/assets/missing.svg')->assertNotFound();
    rmaGet('/runtime-assets/Probe/' . RMA_VERSION . '/..%2Fsecret.svg')->assertNotFound();
    rmaGet('/runtime-assets/Probe/' . RMA_VERSION . '/assets/..%2F..%2Fsecret.svg')->assertNotFound();

    // The release ships Probe again and its copy wins (F3): the runtime build is no longer the loaded one.
    mkdir($this->tmp . '/release/Probe', 0755);
    ModuleAssets::flush();
    rmaGet(RMA_URL)->assertNotFound();
});

it('publishes every runtime asset except svg files, which stay in the module folder only', function () {
    RuntimeAssets::setPath($this->tmp . '/public/runtime-assets');
    $source = $this->tmp . '/build-source';
    mkdir($source . '/assets', 0755, true);
    file_put_contents($source . '/manifest.json', '{}');
    file_put_contents($source . '/assets/app-1.js', 'console.log(1);');
    file_put_contents($source . '/assets/logo-2.svg', RMA_SVG);
    file_put_contents($source . '/assets/LOGO-3.SVG', RMA_SVG);

    $count = RuntimeAssets::publish('Probe', '2026.11.13', $source);

    $published = $this->tmp . '/public/runtime-assets/Probe/2026.11.13';
    expect($count)->toBe(2)
        ->and(is_file($published . '/assets/app-1.js'))->toBeTrue()
        ->and(is_file($published . '/manifest.json'))->toBeTrue()
        ->and(is_file($published . '/assets/logo-2.svg'))->toBeFalse()
        ->and(is_file($published . '/assets/LOGO-3.SVG'))->toBeFalse();
});
