<?php

use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackLoader;

uses(\Cms\Core\Tests\TestCase::class);

const SP_FIXTURE = __DIR__ . '/../../Fixtures/starter-packs/minimal';

/** Copy a directory recursively. */
function sp_copy(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (scandir($from) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        is_dir("$from/$item") ? sp_copy("$from/$item", "$to/$item") : copy("$from/$item", "$to/$item");
    }
}

/** Remove a directory tree (does not follow symlinks). */
function sp_rm(string $dir): void
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item !== '.' && $item !== '..') {
            sp_rm("$dir/$item");
        }
    }
    rmdir($dir);
}

/** Make a temp copy of the fixture named "minimal", editing pack.json via callback; returns the pack dir. */
function sp_variant(?callable $edit = null): string
{
    $base = sys_get_temp_dir() . '/starter-pack-test-' . uniqid('', true);
    $GLOBALS['sp_tmp'][] = $base;
    $dir = $base . '/minimal';
    sp_copy(SP_FIXTURE, $dir);
    if ($edit) {
        $data = json_decode(file_get_contents("$dir/pack.json"), true);
        $data = $edit($data) ?? $data;
        file_put_contents("$dir/pack.json", json_encode($data));
    }

    return $dir;
}

afterEach(function (): void {
    foreach ($GLOBALS['sp_tmp'] ?? [] as $dir) {
        sp_rm($dir);
    }
    $GLOBALS['sp_tmp'] = [];
});

it('loads the minimal fixture', function (): void {
    $m = (new StarterPackLoader())->load(SP_FIXTURE);

    expect($m->key)->toBe('minimal')
        ->and($m->version)->toBe('1.0.0')
        ->and($m->name['en'])->toBe('Minimal pack')
        ->and($m->modules)->toBe(['Core' => '*'])
        ->and($m->config)->toBe('config.package.json')
        ->and($m->configPath())->toBe(realpath(SP_FIXTURE . '/config.package.json'))
        ->and($m->rolesPath())->toBeNull()
        ->and($m->workflowsPath())->toBeNull()
        ->and($m->content[0]['importer'])->toBe('sample')
        ->and($m->content[0]['path'])->toBe(realpath(SP_FIXTURE . '/content/core/sample.json'))
        ->and($m->postApply)->toBe(['cache-clear'])
        ->and($m->toArray()['pack_dir'])->toBe(realpath(SP_FIXTURE));
});

it('accepts a dotted importer id such as helpdesk.kb', function (): void {
    $dir = sp_variant(function ($d) { $d['content'][0]['importer'] = 'helpdesk.kb'; return $d; });

    expect((new StarterPackLoader())->load($dir)->content[0]['importer'])->toBe('helpdesk.kb');
});

it('rejects invalid manifests', function (callable $edit, string $message): void {
    $dir = sp_variant($edit);
    expect(fn () => (new StarterPackLoader())->load($dir))->toThrow(StarterPackException::class, $message);
})->with([
    'unknown pack_format' => [fn ($d) => ['pack_format' => 2] + $d, 'pack_format'],
    'unknown top-level field' => [fn ($d) => $d + ['extra' => 1], 'extra'],
    'bad semver' => [fn ($d) => ['version' => '1.0'] + $d, 'semantic version'],
    'missing vi' => [function ($d) { unset($d['name']['vi']); return $d; }, '"vi"'],
    'missing en' => [function ($d) { unset($d['description']['en']); return $d; }, '"en"'],
    'Core missing' => [fn ($d) => ['modules' => ['Content' => '*']] + $d, 'Core'],
    'dotdot path' => [fn ($d) => ['config' => 'content/../config.package.json'] + $d, 'config'],
    'absolute path' => [fn ($d) => ['config' => '/etc/hosts'] + $d, 'config'],
    'missing content file' => [function ($d) { $d['content'][0]['file'] = 'content/core/none.json'; return $d; }, 'content[0].file'],
    'key differs from folder' => [fn ($d) => ['key' => 'other'] + $d, 'folder name'],
    'importer id with a path' => [function ($d) { $d['content'][0]['importer'] = 'helpdesk/kb'; return $d; }, 'content[0].importer'],
    'importer id with an empty word' => [function ($d) { $d['content'][0]['importer'] = 'helpdesk..kb'; return $d; }, 'content[0].importer'],
    'importer id in upper case' => [function ($d) { $d['content'][0]['importer'] = 'Helpdesk.kb'; return $d; }, 'content[0].importer'],
    'bad post_apply' => [fn ($d) => ['post_apply' => ['rm-rf']] + $d, 'post_apply'],
    'duplicate post_apply' => [fn ($d) => ['post_apply' => ['cache-clear', 'cache-clear']] + $d, 'duplicates'],
]);

it('rejects a symlink that points outside the pack', function (): void {
    if (!function_exists('symlink')) {
        $this->markTestSkipped('symlink() unavailable');
    }
    $dir = sp_variant();
    $outside = dirname($dir) . '/outside.json';
    file_put_contents($outside, '{}');
    unlink("$dir/config.package.json");
    if (!@symlink($outside, "$dir/config.package.json")) {
        $this->markTestSkipped('symlink() not permitted');
    }

    expect(fn () => (new StarterPackLoader())->load($dir))->toThrow(StarterPackException::class, 'config');
});

it('rejects invalid JSON and missing pack.json', function (): void {
    $dir = sp_variant();
    file_put_contents("$dir/pack.json", '{nope');
    expect(fn () => (new StarterPackLoader())->load($dir))->toThrow(StarterPackException::class);
    unlink("$dir/pack.json");
    expect(fn () => (new StarterPackLoader())->load($dir))->toThrow(StarterPackException::class, 'pack.json is missing');
});

it('returns an empty list for a missing root and finds packs in a temp root', function (): void {
    expect((new StarterPackLoader(sys_get_temp_dir() . '/no-such-' . uniqid()))->all())->toBe([]);

    $dir = sp_variant();
    $all = (new StarterPackLoader(dirname($dir)))->all();
    expect(array_keys($all))->toBe(['minimal']);
});
