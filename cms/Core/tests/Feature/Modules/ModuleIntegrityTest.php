<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Console\Commands\ModuleIntegrityCommand;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\ModuleIntegrity;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Platform\SiteStatusReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/*
 * the integrity report compares installed runtime module files with the hashes of the signed package they came
 * from. A changed or missing file is listed, a module with no signed record is "unknown", and nothing ever blocks.
 */

const MI_NAME = 'ProbeIntegrity';
const MI_VERSION = '2026.1.0';
const MI_BASE = '/api/v2/admin/utilities/modules/integrity';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    URL::forceScheme('https');

    $this->tmp = realpath(tap(sys_get_temp_dir() . '/mod-integrity-' . bin2hex(random_bytes(6)), static fn (string $d) => mkdir($d, 0755, true)));
    $this->runtime = $this->tmp . '/runtime';
    mkdir($this->runtime, 0755);
    RuntimeModules::setPath($this->runtime);

    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    config(['core.module_packages.trusted_keys' => [$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))], 'core.module_packages.revoked_key_ids' => []]);
});

afterEach(function () {
    RuntimeModules::resetPath();
    miRemoveTree($this->tmp);
});

/**
 * Remove a temp tree.
 */
function miRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            miRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * Install a signed module into the test runtime folder (version folder plus link) and keep its record.
 *
 * @param array<string, string> $files relative path => content
 */
function miInstall(object $test, array $files, bool $record = true, string $name = MI_NAME): void
{
    $dir = $test->runtime . '/.versions/' . $name . '-' . MI_VERSION;
    $hashes = [];
    foreach ($files as $relative => $content) {
        @mkdir(dirname($dir . '/' . $relative), 0755, true);
        file_put_contents($dir . '/' . $relative, $content);
        $hashes[$name . '/' . $relative] = hash('sha256', $content);
    }
    symlink('.versions/' . $name . '-' . MI_VERSION, $test->runtime . '/' . $name);

    $manifest = json_encode([
        'format' => 1, 'name' => $name, 'version' => MI_VERSION, 'zip_sha256' => str_repeat('a', 64), 'zip_size' => 100,
        'unpacked_size' => 100, 'files' => $hashes, 'requires' => [], 'irreversible' => false, 'custom_code' => false,
        'php_min' => '8.3', 'built_at' => '2026-11-15T00:00:00+00:00', 'key_id' => $test->keyId,
    ], JSON_UNESCAPED_SLASHES);
    if ($record) {
        expect(ModuleIntegrity::record($test->runtime, $name, (new PackageSigner())->envelope($manifest, $test->signKey)))->toBeTrue();
    }
}

/**
 * Path of an installed file.
 */
function miFile(object $test, string $relative, string $name = MI_NAME): string
{
    return $test->runtime . '/' . $name . '/' . $relative;
}

/**
 * Sign in as a Webmaster (level-0 role) or a plain admin.
 */
function miAdmin(bool $webmaster = true): User
{
    $user = User::query()->create(['name' => 'Webmaster', 'email' => uniqid('mi') . '@private.test', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster ' . uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }
    Sanctum::actingAs($user->fresh());

    return $user->fresh();
}

test('untouched files give state ok with nothing changed', function () {
    miInstall($this, ['module.json' => '{"name":"x"}', 'app/Foo.php' => '<?php // foo']);

    expect((new ModuleIntegrity())->check(MI_NAME))->toBe(['state' => 'ok', 'version' => MI_VERSION, 'changed' => 0, 'files' => [], 'truncated' => false]);
});

test('a changed file and a missing file are detected and listed', function () {
    miInstall($this, ['module.json' => '{"name":"x"}', 'app/Foo.php' => '<?php // foo', 'app/Bar.php' => '<?php // bar']);
    file_put_contents(miFile($this, 'app/Foo.php'), '<?php // edited by hand');
    unlink(miFile($this, 'app/Bar.php'));

    $result = (new ModuleIntegrity())->check(MI_NAME);

    expect($result['state'])->toBe('changed')
        ->and($result['changed'])->toBe(2)
        ->and($result['files'])->toBe([['path' => 'app/Foo.php', 'status' => 'modified'], ['path' => 'app/Bar.php', 'status' => 'missing']]);
});

test('a module with no signed record is unknown, not ok and not changed', function () {
    miInstall($this, ['module.json' => '{"name":"x"}'], record: false);
    file_put_contents(miFile($this, 'module.json'), 'edited');

    expect((new ModuleIntegrity())->check(MI_NAME))->toBe(['state' => 'unknown', 'version' => null, 'changed' => 0, 'files' => [], 'truncated' => false])
        ->and((new ModuleIntegrity())->check('NotInstalled')['state'])->toBe('unknown');
});

test('a record that no longer verifies, or belongs to another version, is unknown', function () {
    miInstall($this, ['module.json' => '{"name":"x"}']);
    $record = $this->runtime . '/.integrity/' . MI_NAME . '.json';
    $trusted = config('core.module_packages.trusted_keys');

    // Key no longer trusted.
    config(['core.module_packages.trusted_keys' => []]);
    expect((new ModuleIntegrity())->check(MI_NAME)['state'])->toBe('unknown');
    config(['core.module_packages.trusted_keys' => $trusted]);
    expect((new ModuleIntegrity())->check(MI_NAME)['state'])->toBe('ok');

    // Signature no longer matches the manifest bytes.
    $doc = json_decode((string) file_get_contents($record), true);
    $doc['manifest'] = base64_encode(base64_decode($doc['manifest']) . ' ');
    file_put_contents($record, json_encode($doc));
    expect((new ModuleIntegrity())->check(MI_NAME)['state'])->toBe('unknown');

    // The link points at a version other than the recorded one.
    miRemoveTree($this->tmp);
    mkdir($this->runtime, 0755, true);
    miInstall($this, ['module.json' => '{"name":"x"}']);
    unlink($this->runtime . '/' . MI_NAME);
    mkdir($this->runtime . '/.versions/' . MI_NAME . '-2026.2.0', 0755, true);
    symlink('.versions/' . MI_NAME . '-2026.2.0', $this->runtime . '/' . MI_NAME);
    expect((new ModuleIntegrity())->check(MI_NAME)['state'])->toBe('unknown');
});

test('the command stores the result, prints it and never blocks even when files changed', function () {
    miInstall($this, ['module.json' => '{"name":"x"}', 'app/Foo.php' => '<?php // foo']);
    file_put_contents(miFile($this, 'app/Foo.php'), 'edited');
    $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(ModuleIntegrityCommand::class));

    $this->artisan('cms:module-integrity')->assertExitCode(0);

    $stored = ModuleIntegrity::stored();
    expect($stored['modules'][MI_NAME]['state'])->toBe('changed')
        ->and($stored['modules'][MI_NAME]['changed'])->toBe(1)
        ->and(ModuleIntegrity::counts())->toBe([MI_NAME => 1]);
    // Modules shipped in the release have no record and come out unknown.
    expect($stored['modules']['Core']['state'])->toBe('unknown');
});

test('the fleet report carries the counts only, and only after a check', function () {
    miInstall($this, ['module.json' => '{"name":"x"}', 'app/Foo.php' => '<?php // foo']);
    expect(array_key_exists('module_integrity', (new SiteStatusReport())->build()))->toBeFalse();

    file_put_contents(miFile($this, 'app/Foo.php'), 'edited');
    ModuleIntegrity::store((new ModuleIntegrity())->checkAll([MI_NAME, 'Core']));
    $report = json_decode((string) json_encode((new SiteStatusReport())->build()), true);

    expect($report['module_integrity'])->toBe([MI_NAME => 1])
        ->and(json_encode($report))->not->toContain('app/Foo.php');
});

test('only a Webmaster reads or runs the check, and the page data lists the changed files', function () {
    miInstall($this, ['module.json' => '{"name":"x"}', 'app/Foo.php' => '<?php // foo']);
    file_put_contents(miFile($this, 'app/Foo.php'), 'edited');

    miAdmin(false); // user id 1 is always a Webmaster
    miAdmin(false);
    $this->getJson(MI_BASE)->assertForbidden();
    $this->postJson(MI_BASE . '/check')->assertForbidden();

    miAdmin();
    $this->getJson(MI_BASE)->assertOk()->assertJsonPath('data.checked_at', null);
    $this->postJson(MI_BASE . '/check')->assertOk()
        ->assertJsonPath('data.modules.' . MI_NAME . '.state', 'changed')
        ->assertJsonPath('data.modules.' . MI_NAME . '.changed', 1)
        ->assertJsonPath('data.modules.' . MI_NAME . '.files.0.path', 'app/Foo.php');
    $this->getJson(MI_BASE)->assertOk()->assertJsonPath('data.modules.' . MI_NAME . '.changed', 1);
});

test('the installer keeps the signed record of a placed package', function () {
    $sig = 'sig-document';
    expect(ModuleIntegrity::record($this->runtime, MI_NAME, $sig))->toBeTrue()
        ->and(file_get_contents($this->runtime . '/.integrity/' . MI_NAME . '.json'))->toBe($sig)
        ->and(ModuleIntegrity::record($this->runtime, '../Evil', $sig))->toBeFalse();
});
