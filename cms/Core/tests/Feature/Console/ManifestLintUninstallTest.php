<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleUninstaller;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * the optional `uninstall` declaration of `module.json` — `tables` and `refuse_while_rows` — in the
 * manifest lint, the signed release manifest reader and the runtime uninstaller.
 */
beforeEach(function () {
    $this->mluRoot = sys_get_temp_dir().'/manifest-lint-uninstall-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->mluRoot);
});

afterEach(function () {
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    File::deleteDirectory($this->mluRoot);
});

/**
 * Write a "Sample" module manifest merged with extra keys and run the lint on the temp tree.
 *
 * @param  array<string, mixed>  $extra
 */
function mluLint(string $root, array $extra): \Illuminate\Testing\PendingCommand
{
    File::ensureDirectoryExists("{$root}/Sample");
    File::put("{$root}/Sample/module.json", json_encode(['name' => 'Sample', 'version' => '1.0.0'] + $extra));

    return test()->artisan('cms:manifest-lint', ['--path' => $root]);
}

/**
 * A valid format 1 manifest with optional overrides.
 *
 * @return array<string, mixed>
 */
function mluManifest(array $overrides = []): array
{
    return array_merge([
        'format' => 1,
        'name' => 'Sample',
        'version' => '2026.11.0',
        'zip_sha256' => hash('sha256', 'zip'),
        'zip_size' => 10,
        'unpacked_size' => 20,
        'files' => ['Sample/module.json' => hash('sha256', 'a')],
        'requires' => [],
        'irreversible' => false,
        'custom_code' => true,
        'php_min' => '8.3',
        'built_at' => '2026-11-12T10:00:00Z',
        'key_id' => '0123456789abcdef',
    ], $overrides);
}

/**
 * Parse manifest bytes; the refusal code, or null when accepted.
 */
function mluManifestError(array $manifest): ?string
{
    try {
        ReleaseManifest::fromVerifiedBytes(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

/**
 * An uninstaller for a runtime module "Sample" whose module.json carries the given `uninstall` value, placed in a temp
 * runtime folder of a site that ships Core only.
 */
function mluUninstaller(object $t, mixed $uninstall): ModuleUninstaller
{
    $runtime = $t->mluRoot.'/runtime';
    $release = $t->mluRoot.'/release';
    File::ensureDirectoryExists($runtime.'/Sample/database/migrations');
    File::ensureDirectoryExists($release.'/Core');
    File::put($release.'/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.11.0']));
    File::put($runtime.'/Sample/module.json', json_encode(['name' => 'Sample', 'version' => '1.0.0', 'uninstall' => $uninstall]));
    RuntimeModules::setPath($runtime);
    config([
        'modules.paths.modules' => $release,
        'modules.activators.database.cache_store' => 'array',
        'modules.activators.database.connection' => null,
    ]);
    DatabaseActivator::flushProcessState();

    return new ModuleUninstaller(
        Mockery::mock(BackupGate::class),
        Mockery::mock(BackgroundTaskRecorder::class),
        new ModuleCatalog(null, $release),
        null,
        null,
        null,
        new DatabaseActivator(app()),
        $release,
    );
}

/**
 * The uninstall refusal code of a check, or null when allowed.
 */
function mluRefusal(ModuleUninstaller $uninstaller, bool $dropData = false): ?string
{
    try {
        $uninstaller->check('Sample', $dropData);
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

it('accepts uninstall.tables and uninstall.refuse_while_rows in module.json', function () {
    mluLint($this->mluRoot, ['uninstall' => ['tables' => ['sample_items'], 'refuse_while_rows' => ['sample_orders', 'sample_payments']]])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')->assertExitCode(0);
});

it('rejects a malformed uninstall declaration', function (mixed $uninstall, string $message) {
    mluLint($this->mluRoot, ['uninstall' => $uninstall])->expectsOutputToContain($message)->assertExitCode(1);
})->with([
    'not an object' => [['sample_orders'], "'uninstall' must be an object"],
    'unknown field' => [['keep' => true], "uninstall has unknown field 'keep'"],
    'rows not a list' => [['refuse_while_rows' => 'sample_orders'], 'uninstall.refuse_while_rows must be a list'],
    'unsafe table name' => [['refuse_while_rows' => ['orders; drop table x']], "uninstall.refuse_while_rows entry 'orders; drop table x'"],
    'duplicate table' => [['refuse_while_rows' => ['a_rows', 'a_rows']], "uninstall.refuse_while_rows entry 'a_rows'"],
    'bad tables entry' => [['tables' => [5]], "uninstall.tables entry '5'"],
]);

it('reads uninstall.refuse_while_rows from a signed manifest and keeps it in toArray', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(mluManifest(['uninstall' => ['refuse_while_rows' => ['orders']]]), JSON_UNESCAPED_SLASHES));

    expect($manifest->uninstallRefuseWhileRows)->toBe(['orders'])
        ->and($manifest->uninstallTables)->toBe([])
        ->and($manifest->toArray()['uninstall'])->toBe(['tables' => [], 'refuse_while_rows' => ['orders']]);
});

it('reads a manifest with only uninstall.tables as before', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(mluManifest(['uninstall' => ['tables' => ['sample_items']]]), JSON_UNESCAPED_SLASHES));

    expect($manifest->uninstallRefuseWhileRows)->toBeNull()
        ->and($manifest->toArray()['uninstall'])->toBe(['tables' => ['sample_items']]);
});

it('refuses a malformed uninstall.refuse_while_rows in a signed manifest', function (mixed $rows) {
    expect(mluManifestError(mluManifest(['uninstall' => ['refuse_while_rows' => $rows]])))->toBe('MODULE_PACKAGE_SCHEMA');
})->with([
    'string' => ['orders'],
    'object' => [['a' => 'orders']],
    'unsafe' => [['orders`']],
    'duplicate' => [['orders', 'orders']],
    'too many' => [array_map(fn (int $i) => 't'.$i, range(1, ReleaseManifest::MAX_UNINSTALL_TABLES + 1))],
]);

it('refuses the uninstall while a listed table has rows, and allows it once the tables are empty or absent', function () {
    Schema::create('mlu_sample_orders', function (Blueprint $table) {
        $table->id();
    });
    $uninstaller = mluUninstaller($this, ['refuse_while_rows' => ['mlu_sample_orders', 'mlu_sample_absent']]);

    expect(mluRefusal($uninstaller))->toBeNull();

    DB::table('mlu_sample_orders')->insert(['id' => 1]);
    expect(mluRefusal($uninstaller))->toBe(ModuleUninstaller::ERROR_HAS_DATA)
        ->and(mluRefusal($uninstaller, true))->toBe(ModuleUninstaller::ERROR_HAS_DATA);

    DB::table('mlu_sample_orders')->delete();
    expect(mluRefusal($uninstaller))->toBeNull();
});

it('fails closed: a malformed refuse_while_rows declaration refuses the uninstall', function (mixed $uninstall) {
    expect(mluRefusal(mluUninstaller($this, $uninstall)))->toBe(ModuleUninstaller::ERROR_HAS_DATA);
})->with([
    'string' => [['refuse_while_rows' => 'orders']],
    'unsafe name' => [['refuse_while_rows' => ['orders; --']]],
    'not an object' => [['orders']],
]);
