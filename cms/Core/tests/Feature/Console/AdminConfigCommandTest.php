<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Services\Admin\AdminRuntimeConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * `cms:admin-config` writes public/iPanel/config.js (window.__ISPA_ADMIN__) from .env values. Every test writes to a
 * temp folder, never to the real public/iPanel/config.js.
 */
beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/cms-admin-config-'.Str::random(8);
    File::ensureDirectoryExists($this->tmp);
    $this->originalPublic = public_path();

    config([
        'app.url' => 'https://example.com',
        'session.domain' => null,
        'session.secure' => null,
        'ispa-cms.admin_auth_key' => 'key-123',
        'ispa-cms.platform_hub_url' => 'https://cms.ispa.io',
        'ispa-cms.app_version' => '2026.12.0',
    ]);
});

afterEach(function () {
    app()->usePublicPath($this->originalPublic);
    File::deleteDirectory($this->tmp);
});

/** Decode the object literal of a rendered file. */
function adminConfigLiteral(string $body): array
{
    expect(preg_match('/^window\.__ISPA_ADMIN__ = (\{.*\});$/m', $body, $m))->toBe(1);

    return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
}

it('follows the env values', function () {
    $values = (new AdminRuntimeConfig())->values();

    expect($values)->toBe([
        'url' => 'https://example.com/',
        'cookieDomain' => 'example.com',
        'secureCookie' => true,
        'adminAuthKey' => 'key-123',
        'hubUrl' => 'https://cms.ispa.io',
        'version' => '2026.12.0',
        'docsUrl' => 'https://docs.ispa.tech',
    ]);
});

it('carries the docs address without a trailing slash', function () {
    config(['ispa-cms.docs_url' => 'https://docs.example.org/']);

    expect((new AdminRuntimeConfig())->values()['docsUrl'])->toBe('https://docs.example.org');
});

it('lets SESSION_DOMAIN and SESSION_SECURE_COOKIE win', function () {
    config(['session.domain' => '.example.org', 'session.secure' => false]);

    $values = (new AdminRuntimeConfig())->values();

    expect($values['cookieDomain'])->toBe('.example.org')->and($values['secureCookie'])->toBeFalse();
});

it('normalises the trailing slash, port and path of APP_URL', function () {
    config(['app.url' => 'http://localhost:8000/']);
    $values = (new AdminRuntimeConfig())->values();
    expect($values['url'])->toBe('http://localhost:8000/')
        ->and($values['cookieDomain'])->toBe('localhost')
        ->and($values['secureCookie'])->toBeFalse();

    config(['app.url' => 'https://example.com/cms']);
    $values = (new AdminRuntimeConfig())->values();
    expect($values['url'])->toBe('https://example.com/cms/')->and($values['cookieDomain'])->toBe('example.com');

    config(['app.url' => 'https://example.com/cms///']);
    expect((new AdminRuntimeConfig())->values()['url'])->toBe('https://example.com/cms/');
});

it('renders safe javascript for hostile values', function () {
    config(['ispa-cms.admin_auth_key' => 'a"</script><b>&\'']);

    $body = (new AdminRuntimeConfig())->render();

    expect(strtolower($body))->not->toContain('</script')
        ->and($body)->not->toContain('<b>')
        ->and(adminConfigLiteral($body)['adminAuthKey'])->toBe('a"</script><b>&\'');
});

it('writes an empty string and warns when the admin key is empty', function () {
    config(['ispa-cms.admin_auth_key' => '']);
    $path = $this->tmp.'/nested/iPanel/config.js';

    $this->artisan('cms:admin-config', ['--path' => $path])
        ->expectsOutputToContain('ISPA_ADMIN_AUTH_KEY')
        ->assertSuccessful();

    expect(File::exists($path))->toBeTrue()
        ->and(adminConfigLiteral(File::get($path))['adminAuthKey'])->toBe('');
});

it('is written by cms:install', function () {
    $env = $this->tmp.'/env';
    File::ensureDirectoryExists($env);
    File::put($env.'/.env', "APP_NAME=Test\nAPP_KEY=\n");
    app()->useEnvironmentPath($env);
    app()->usePublicPath($this->tmp.'/public');
    config(['ispa-cms.folder_name' => 'Default', 'ispa-cms.cms_domain_secret' => '']);

    $this->artisan('cms:install', ['--skip-seed' => true])->assertSuccessful();

    expect(File::exists($this->tmp.'/public/iPanel/config.js'))->toBeTrue()
        ->and(adminConfigLiteral(File::get($this->tmp.'/public/iPanel/config.js'))['url'])->toBe('https://example.com/');
});

/** A folder with a built-admin style index.html; returns the config.js path inside it. */
function adminConfigIndexFixture(string $dir, string $tag = '<script src="/iPanel/config.js"></script>'): string
{
    File::ensureDirectoryExists($dir);
    File::put($dir.'/index.html', "<!doctype html><html><head><title>Admin</title></head><body><div id=\"app\"></div>\n<script type=\"module\" src=\"/iPanel/assets/app.js\"></script>\n{$tag}\n</body></html>\n");

    return $dir.'/config.js';
}

function adminConfigIndexSrc(string $dir): ?string
{
    return preg_match('~src="(/iPanel/config\.js[^"]*)"~', File::get($dir.'/index.html'), $m) === 1 ? $m[1] : null;
}

it('the index src carries a hash of the config body', function () {
    $dir = $this->tmp.'/idx-hash';
    $path = adminConfigIndexFixture($dir);

    (new AdminRuntimeConfig())->write($path);

    expect(adminConfigIndexSrc($dir))->toBe('/iPanel/config.js?v='.substr(sha1(File::get($path)), 0, 12))
        ->and(File::get($dir.'/index.html'))->toContain('src="/iPanel/assets/app.js"');
});

it('the src changes when values change', function () {
    $dir = $this->tmp.'/idx-change';
    $path = adminConfigIndexFixture($dir);

    (new AdminRuntimeConfig())->write($path);
    $first = adminConfigIndexSrc($dir);

    config(['ispa-cms.app_version' => '2027.1.0']);
    (new AdminRuntimeConfig())->write($path);

    expect(adminConfigIndexSrc($dir))->not->toBe($first);
});

it('the src stays the same when values do not change (file not rewritten: same mtime/content)', function () {
    $dir = $this->tmp.'/idx-same';
    $path = adminConfigIndexFixture($dir);

    (new AdminRuntimeConfig())->write($path);
    $content = File::get($dir.'/index.html');
    touch($dir.'/index.html', 1_000_000_000);
    clearstatcache();

    (new AdminRuntimeConfig())->write($path);
    clearstatcache();

    expect(File::get($dir.'/index.html'))->toBe($content)
        ->and(filemtime($dir.'/index.html'))->toBe(1_000_000_000);
});

it('an existing ?v= is replaced, not appended', function () {
    $dir = $this->tmp.'/idx-replace';
    $path = adminConfigIndexFixture($dir, "<script src='/iPanel/config.js?v=old123'></script>");

    (new AdminRuntimeConfig())->write($path);

    expect(File::get($dir.'/index.html'))->toContain('/iPanel/config.js?v='.substr(sha1(File::get($path)), 0, 12))
        ->and(File::get($dir.'/index.html'))->not->toContain('old123')
        ->and(substr_count(File::get($dir.'/index.html'), '?v='))->toBe(1);
});

it('no index.html: config.js is written and nothing fails', function () {
    $dir = $this->tmp.'/idx-none';
    $path = $dir.'/config.js';

    expect((new AdminRuntimeConfig())->write($path))->toBe($path)
        ->and(File::exists($path))->toBeTrue()
        ->and(File::exists($dir.'/index.html'))->toBeFalse();

    // An index.html without the tag is left alone too.
    File::put($dir.'/index.html', '<html></html>');
    (new AdminRuntimeConfig())->write($path);
    expect(File::get($dir.'/index.html'))->toBe('<html></html>');
});
