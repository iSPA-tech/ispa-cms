<?php

use Cms\Core\Models\Translate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    $this->originalDatabaseDefault = config('database.default');
    $this->originalSqliteDatabase = config('database.connections.sqlite.database');

    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    Schema::create('translates', function (Blueprint $table) {
        $table->id();
        $table->text('modules')->nullable();
        $table->string('group_name')->nullable();
        $table->string('name')->unique();
        $table->json('text')->nullable();
        $table->boolean('for_web')->default(false);
        $table->boolean('for_adm')->default(false);
        $table->boolean('for_app')->default(false);
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    // Under storage/framework/testing: the translation file guard refuses other paths it cannot prove untracked (an
    // install without git).
    $this->fixtureRoot = storage_path('framework/testing/admin-i18n-recover');
    File::deleteDirectory($this->fixtureRoot);
    File::ensureDirectoryExists($this->fixtureRoot);
});

afterEach(function () {
    Schema::dropIfExists('translates');
    config()->set('database.default', $this->originalDatabaseDefault);
    config()->set('database.connections.sqlite.database', $this->originalSqliteDatabase);
    File::deleteDirectory($this->fixtureRoot);
});

function makeAdminI18nRecoverFixtureTree(string $root): array
{
    $frontendRoot = $root . '/base-admin';
    $modulesRoot = $root . '/cms';
    $runtimeDir = $root . '/runtime';
    $reportDir = $root . '/reports';
    $exportPath = $root . '/translates.php';
    $historyPath = $root . '/history.php';

    File::ensureDirectoryExists($frontendRoot . '/src/pages/auth');
    File::ensureDirectoryExists($modulesRoot . '/Education/database/seeders/sql');
    File::ensureDirectoryExists($runtimeDir);
    File::ensureDirectoryExists($reportDir);

    File::put(
        $frontendRoot . '/src/pages/auth/sign-in.vue',
        <<<'VUE'
<template>
    <div>{{ $t('m.auth.failed-remaining') }}</div>
</template>
VUE
    );

    File::put(
        $exportPath,
        <<<'PHP'
<?php

return [
    [
        'id' => 9991,
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => 'text.acc.current-password',
        'text' => ['en' => 'Current password', 'vi' => 'Mat khau hien tai'],
        'for_adm' => 1,
        'for_web' => 0,
        'for_app' => 0,
        'status' => 'published',
    ],
];
PHP
    );

    File::put(
        $historyPath,
        <<<'PHP'
<?php

return [
    [
        'id' => 111,
        'modules' => ['Core'],
        'group_name' => 'mgs',
        'name' => 'mgs.auth.failed-remaining',
        'text' => ['en' => 'Wrong :n times', 'vi' => 'Sai :n lan'],
        'for_adm' => 0,
        'for_web' => 0,
        'for_app' => 0,
        'status' => 'published',
    ],
];
PHP
    );

    File::put(
        $modulesRoot . '/Education/database/seeders/sql/translate.php',
        <<<'PHP'
<?php

return [
    [
        'id' => 222,
        'modules' => ['Education'],
        'group_name' => 'enum',
        'name' => 'day-1',
        'text' => ['en' => 'Monday', 'vi' => 'Thu Hai'],
        'for_adm' => 0,
        'for_web' => 0,
        'for_app' => 0,
        'status' => 'published',
    ],
];
PHP
    );

    File::put(
        $reportDir . '/admin-i18n-audit-latest.json',
        json_encode([
            'core_missing_candidates' => [
                [
                    'key' => 'm.auth.failed-remaining',
                    'ownership' => [
                        'core' => true,
                        'modules' => [],
                    ],
                ],
            ],
            'module_missing_candidates' => [
                [
                    'key' => 'e.day-1',
                    'ownership' => [
                        'core' => false,
                        'modules' => ['Education'],
                    ],
                ],
            ],
            'runtime_missing_candidates' => [
                [
                    'key' => 'text.acc.current-password',
                    'canonical_candidates' => ['text.acc.current-password'],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
    );

    return [
        'frontend' => $frontendRoot,
        'modules' => $modulesRoot,
        'runtime' => $runtimeDir,
        'report' => $reportDir . '/admin-i18n-audit-latest.json',
        'history' => $historyPath,
        'export' => $exportPath,
        'core_recovery' => $root . '/translates-admin-recovered.php',
    ];
}

test('admin i18n recover writes recovery sidecars and syncs exact db matches', function () {
    $paths = makeAdminI18nRecoverFixtureTree($this->fixtureRoot);

    Translate::query()->create([
        'modules' => ['Core'],
        'group_name' => 'mgs',
        'name' => 'auth.failed-remaining',
        'text' => ['en' => 'Wrong :n times', 'vi' => 'Sai :n lan'],
        'for_web' => false,
        'for_adm' => false,
        'for_app' => false,
        'status' => 'published',
    ]);

    $exit = Artisan::call('cms:admin-i18n-recover', [
        '--frontend' => $paths['frontend'],
        '--export' => $paths['export'],
        '--modules-root' => $paths['modules'],
        '--runtime-dir' => $paths['runtime'],
        '--report' => $paths['report'],
        '--history' => $paths['history'],
        '--core-recovery' => $paths['core_recovery'],
        '--write-report' => 1,
        '--sync-db' => 1,
    ]);

    expect($exit)->toBe(0);
    expect(File::exists($paths['core_recovery']))->toBeTrue();

    $coreSidecar = require $paths['core_recovery'];
    expect($coreSidecar)->toBeArray();
    expect($coreSidecar[0]['name'])->toBe('mgs.auth.failed-remaining');
    expect($coreSidecar[0]['for_adm'])->toBe(1);
    // the source rows carry ids (111, 9991), the recovered rows never do.
    foreach ($coreSidecar as $row) {
        expect($row)->not->toHaveKey('id');
    }

    $reconciled = Translate::query()->where('group_name', 'mgs')->where('name', 'auth.failed-remaining')->first();
    expect($reconciled)->not->toBeNull();
    expect($reconciled->for_adm)->toBeTrue();

    $created = Translate::query()->where('group_name', 'text')->where('name', 'text.acc.current-password')->first();
    expect($created)->not->toBeNull();
    expect($created->for_adm)->toBeTrue();

    $report = json_decode(
        (string) File::get(dirname($paths['report']) . '/admin-i18n-recovery-latest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    expect($report['summary']['recovered_count'])->toBe(2);
});

test('admin i18n recover writes module-specific rows into module sidecars', function () {
    $paths = makeAdminI18nRecoverFixtureTree($this->fixtureRoot);

    $exit = Artisan::call('cms:admin-i18n-recover', [
        '--frontend' => $paths['frontend'],
        '--export' => $paths['export'],
        '--modules-root' => $paths['modules'],
        '--runtime-dir' => $paths['runtime'],
        '--report' => $paths['report'],
        '--history' => $paths['history'],
        '--core-recovery' => $paths['core_recovery'],
        '--write-report' => 0,
        '--sync-db' => 0,
    ]);

    expect($exit)->toBe(0);

    $moduleSidecarPath = $paths['modules'] . '/Education/database/seeders/sql/translate-admin-recovered.php';
    expect(File::exists($moduleSidecarPath))->toBeTrue();

    $moduleSidecar = require $moduleSidecarPath;
    expect($moduleSidecar)->toBeArray();
    expect($moduleSidecar[0]['group_name'])->toBe('enum');
    expect($moduleSidecar[0]['name'])->toBe('day-1');
    expect($moduleSidecar[0]['for_adm'])->toBe(1);
    // the source row carries id 222, the recovered row never does.
    foreach ($moduleSidecar as $row) {
        expect($row)->not->toHaveKey('id');
    }
});
