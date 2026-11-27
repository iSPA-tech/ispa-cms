<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\CoreUpgrade\CoreIntegrity;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CoreUpdateChecker;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tree = sys_get_temp_dir().'/core-integrity-'.bin2hex(random_bytes(6));
    $files = [
        'app/X.php' => '<?php // x',
        'projects/Default/views/a.blade.php' => 'a',
        'cms/Core/module.json' => '{}',
        'composer.json' => '{}',
        'public/build/a.js' => 'js',
        'composer.lock' => 'lock-v1',
    ];
    $checked = [];
    foreach ($files as $rel => $body) {
        is_dir(dirname($this->tree.'/'.$rel)) || mkdir(dirname($this->tree.'/'.$rel), 0755, true);
        file_put_contents($this->tree.'/'.$rel, $body);
        if (! str_starts_with($rel, 'public/build') && $rel !== 'composer.lock') {
            $checked[$rel] = hash('sha256', $body);
        }
    }
    file_put_contents($this->tree.'/core-manifest.json', json_encode([
        'format' => 1, 'version' => '2026.12.0', 'php_min' => '8.3', 'built_at' => '2026-11-18T00:00:00Z',
        'composer_lock_sha256' => hash('sha256', 'lock-v1'), 'checked' => $checked,
        'replaced' => ['public/build', 'public/iPanel', 'vendor', 'composer.lock'],
    ]));
    $this->manifest = CoreManifest::installed($this->tree);
    $this->integrity = new CoreIntegrity();
});

afterEach(function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($this->tree);
});

function ciWrite(string $tree, string $rel, string $body): void
{
    @mkdir(dirname($tree.'/'.$rel), 0755, true);
    file_put_contents($tree.'/'.$rel, $body);
}

function ciManifest(string $tree, array $override): string
{
    $data = array_replace(json_decode(file_get_contents($tree.'/core-manifest.json'), true), $override);
    file_put_contents($tree.'/bad.json', json_encode($data));

    return $tree.'/bad.json';
}

test('a clean tree has no modified files and no custom dependencies', function () {
    expect($this->manifest->version)->toBe('2026.12.0')
        ->and($this->manifest->phpMin)->toBe('8.3')
        ->and($this->integrity->modified($this->manifest, $this->tree))->toBe([])
        ->and($this->integrity->customDependencies($this->manifest, $this->tree))->toBeFalse();
});

test('edited core files are listed sorted, replaced paths and own code are ignored', function () {
    ciWrite($this->tree, 'app/X.php', 'edited');
    ciWrite($this->tree, 'projects/Default/views/a.blade.php', 'edited');
    ciWrite($this->tree, 'public/build/a.js', 'edited');
    ciWrite($this->tree, 'projects/MySite/views/a.blade.php', 'mine');
    ciWrite($this->tree, 'cms/MyModule/module.json', '{}');
    ciWrite($this->tree, '.env', 'APP_KEY=x');

    expect($this->integrity->modified($this->manifest, $this->tree))
        ->toBe(['app/X.php', 'projects/Default/views/a.blade.php']);
});

test('a deleted checked file is listed', function () {
    unlink($this->tree.'/app/X.php');

    expect($this->integrity->modified($this->manifest, $this->tree))->toBe(['app/X.php']);
});

test('a changed composer.lock means custom dependencies', function () {
    ciWrite($this->tree, 'composer.lock', 'lock-v2');

    expect($this->integrity->customDependencies($this->manifest, $this->tree))->toBeTrue()
        ->and($this->integrity->modified($this->manifest, $this->tree))->toBe([]);
});

test('an own module composer.json means custom dependencies', function () {
    ciWrite($this->tree, 'cms/MyModule/composer.json', '{}');

    expect($this->integrity->customDependencies($this->manifest, $this->tree))->toBeTrue();
});

test('a missing manifest reads as null', function () {
    unlink($this->tree.'/core-manifest.json');

    expect(CoreManifest::installed($this->tree))->toBeNull();
});

test('a bad manifest is refused with a clear exception', function (array $override) {
    expect(fn () => CoreManifest::fromFile(ciManifest($this->tree, $override)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'traversal' => [['checked' => ['../etc/passwd' => str_repeat('a', 64)]]],
    'absolute' => [['checked' => ['/etc/passwd' => str_repeat('a', 64)]]],
    'dot segment' => [['checked' => ['app/../x' => str_repeat('a', 64)]]],
    'upper-case hash' => [['checked' => ['app/X.php' => str_repeat('A', 64)]]],
    'short hash' => [['checked' => ['app/X.php' => 'abc']]],
    'wrong format' => [['format' => 2]],
    'unsafe replaced' => [['replaced' => ['../vendor']]],
    'bad lock hash' => [['composer_lock_sha256' => 'zz']],
]);

test('invalid JSON is refused', function () {
    file_put_contents($this->tree.'/bad.json', '{nope');

    expect(fn () => CoreManifest::fromFile($this->tree.'/bad.json'))->toThrow(InvalidArgumentException::class);
});

// ---- update checker ----

function ciFakeHub(array $catalog, int $status = 200): void
{
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => 'secret-for-core-tests',
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);
    $GLOBALS['ci_hub_calls'] = 0;
    Http::fake(function (ClientRequest $sent) use ($catalog, $status) {
        $GLOBALS['ci_hub_calls']++;
        $body = json_encode($catalog);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, $status, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response('secret-for-core-tests', $status, $nonce, $body)]);
    });
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
});

test('the checker returns the hub core entry and caches it', function () {
    ciFakeHub(['modules' => [], 'core' => ['kind' => 'core', 'name' => 'ispa-cms', 'version' => '2026.12.1', 'notes_url' => 'https://ispa.tech/notes']]);
    $checker = new CoreUpdateChecker();

    expect($checker->latest())->toBe(['version' => '2026.12.1', 'notes_url' => 'https://ispa.tech/notes'])
        ->and($checker->latest())->toBe(['version' => '2026.12.1', 'notes_url' => 'https://ispa.tech/notes'])
        ->and($GLOBALS['ci_hub_calls'])->toBe(1);
});

test('a refresh skips the cached value, asks the hub and stores the fresh answer', function () {
    Cache::put(CoreUpdateChecker::CACHE_KEY, ['version' => '2026.11.06', 'notes_url' => null], CoreUpdateChecker::TTL_SECONDS);
    ciFakeHub(['modules' => [], 'core' => ['kind' => 'core', 'name' => 'ispa-cms', 'version' => '2026.11.07', 'notes_url' => 'https://ispa.tech/notes']]);
    $checker = new CoreUpdateChecker();

    // A normal load keeps the hour-long cache.
    expect($checker->latest())->toBe(['version' => '2026.11.06', 'notes_url' => null])
        ->and($GLOBALS['ci_hub_calls'])->toBe(0);

    expect($checker->latest(true))->toBe(['version' => '2026.11.07', 'notes_url' => 'https://ispa.tech/notes'])
        ->and($GLOBALS['ci_hub_calls'])->toBe(1)
        ->and(Cache::get(CoreUpdateChecker::CACHE_KEY))->toBe(['version' => '2026.11.07', 'notes_url' => 'https://ispa.tech/notes'])
        // The next normal load reads the fresh value from the cache.
        ->and($checker->latest())->toBe(['version' => '2026.11.07', 'notes_url' => 'https://ispa.tech/notes'])
        ->and($GLOBALS['ci_hub_calls'])->toBe(1);
});

test('a refresh the hub does not answer keeps the cached value', function () {
    $cached = ['version' => '2026.11.06', 'notes_url' => null];
    Cache::put(CoreUpdateChecker::CACHE_KEY, $cached, CoreUpdateChecker::TTL_SECONDS);

    ciFakeHub(['error' => 'boom'], 500);
    expect((new CoreUpdateChecker())->latest(true))->toBe($cached)
        ->and($GLOBALS['ci_hub_calls'])->toBeGreaterThan(0)
        ->and(Cache::get(CoreUpdateChecker::CACHE_KEY))->toBe($cached);

    // An answer without a usable core entry does not wipe it either.
    ciFakeHub(['modules' => []]);
    expect((new CoreUpdateChecker())->latest(true))->toBe($cached)
        ->and(Cache::get(CoreUpdateChecker::CACHE_KEY))->toBe($cached);

    // Nothing cached and no answer: still "no information".
    Cache::flush();
    ciFakeHub(['error' => 'boom'], 500);
    expect((new CoreUpdateChecker())->latest(true))->toBeNull();
});

test('the checker returns null when the hub has no core entry', function () {
    ciFakeHub(['modules' => []]);

    expect((new CoreUpdateChecker())->latest())->toBeNull();
});

test('the checker returns null on a hub error or an unconfigured site, never throws', function () {
    ciFakeHub(['error' => 'boom'], 500);
    expect((new CoreUpdateChecker())->latest())->toBeNull();

    config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);
    Cache::flush();
    expect((new CoreUpdateChecker())->latest())->toBeNull();
});

test('a core entry with a bad version is refused', function () {
    ciFakeHub(['core' => ['kind' => 'core', 'version' => '../x']]);
    expect((new CoreUpdateChecker())->latest())->toBeNull();
});

test('a non-https notes url is dropped', function () {
    ciFakeHub(['core' => ['kind' => 'core', 'version' => '2026.12.1', 'notes_url' => 'javascript:alert(1)']]);
    expect((new CoreUpdateChecker())->latest())->toBe(['version' => '2026.12.1', 'notes_url' => null]);
});
