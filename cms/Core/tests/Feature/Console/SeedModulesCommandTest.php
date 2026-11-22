<?php

use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;

uses(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'pgsql') {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
    }

    Schema::dropIfExists('settings');
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('setting_key')->nullable();
        $table->string('type')->default('text');
        $table->string('group_name')->nullable();
        $table->string('name')->nullable();
        $table->text('value')->nullable();
        $table->unsignedBigInteger('enumerate_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->text('input_setting')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('settings');
    // clean up fake modules
    File::deleteDirectory(base_path('cms/FakeModule'));
    File::deleteDirectory(base_path('cms/DisabledModule'));
});

afterEach(function () {
    Schema::dropIfExists('settings');
    File::deleteDirectory(storage_path('framework/testing/modules')); // in case future tests use it
    
    // clean up fake modules
    File::deleteDirectory(base_path('cms/FakeModule'));
    File::deleteDirectory(base_path('cms/DisabledModule'));

    // Module toggling never touches the tracked modules_statuses.json: the test bootstrap points
    // the file activator at a per-process copy through MODULES_STATUSES_FILE, so the
    // old rename-to-.bak dance is gone.
});

test('seeds exported php arrays from module exported directory', function () {
    $moduleRoot = base_path('cms/FakeModule');
    $exportDir = $moduleRoot . '/database/seeders/exported';
    File::ensureDirectoryExists($exportDir);
    File::put($exportDir . '/settings.php', <<<'PHP'
    <?php
    return [
        ['id' => 1, 'setting_key' => 'app.a', 'name' => 'a', 'group_name' => 'app', 'value' => 'v1'],
        ['id' => 2, 'setting_key' => 'app.b', 'name' => 'b', 'group_name' => 'app', 'value' => 'v2'],
    ];
    PHP
    );

    // mimic translate file existing but empty array
    File::ensureDirectoryExists($moduleRoot . '/database/seeders/sql');
    File::put($moduleRoot . '/database/seeders/sql/translate.php', "<?php\nreturn [];\n");

    $exit = Artisan::call('cms:seed-modules', ['--module' => ['FakeModule']]);
    expect($exit)->toBe(0);

    $rows = app('db')->table('settings')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->name)->toBe('a');
    expect($rows[1]->name)->toBe('b');
});

test('skips exported translation sidecars and still imports live sql translations', function () {
    Schema::dropIfExists('translates');
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
        $table->boolean('is_used')->default(false);
        $table->text('used_in')->nullable();
        $table->timestamp('last_scanned_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $moduleRoot = base_path('cms/FakeModule');
    $exportDir = $moduleRoot . '/database/seeders/exported';
    $sqlDir = $moduleRoot . '/database/seeders/sql';
    File::ensureDirectoryExists($exportDir);
    File::ensureDirectoryExists($sqlDir);

    File::put($exportDir . '/settings.php', <<<'PHP'
    <?php
    return [
        ['id' => 1, 'setting_key' => 'app.sidecar-proof', 'name' => 'sidecar-proof', 'group_name' => 'app', 'value' => 'ok'],
    ];
    PHP
    );
    File::put($exportDir . '/translates-admin-contract.php', <<<'PHP'
    <?php
    return [
        ['id' => 9001, 'modules' => ['FakeModule'], 'group_name' => 'text', 'name' => 'fake.contract-only', 'text' => ['en' => 'Contract', 'vi' => 'Hợp đồng']],
    ];
    PHP
    );
    File::put($sqlDir . '/translate.php', "<?php\nreturn [];\n");
    File::put($sqlDir . '/translate-admin-live.php', <<<'PHP'
    <?php
    return [
        [
            'modules' => ['FakeModule'],
            'group_name' => 'text',
            'name' => 'fake.live-translation',
            'text' => ['en' => 'Live', 'vi' => 'Trực tiếp'],
            'for_web' => 0,
            'for_adm' => 1,
            'for_app' => 0,
            'status' => 'status_published',
        ],
    ];
    PHP
    );

    try {
        $exit = Artisan::call('cms:seed-modules', ['--module' => ['FakeModule']]);

        expect($exit)->toBe(0)
            ->and(app('db')->table('settings')->where('setting_key', 'app.sidecar-proof')->exists())->toBeTrue()
            ->and(app('db')->table('translates')->where('name', 'fake.live-translation')->exists())->toBeTrue()
            ->and(app('db')->table('translates')->where('name', 'fake.contract-only')->exists())->toBeFalse();
    } finally {
        Schema::dropIfExists('translates');
    }
});

test('seeds only enabled modules when --enabled-only is passed', function () {
    // 1. Setup FakeModule (Enabled)
    $enabledRoot = base_path('cms/FakeModule');
    File::ensureDirectoryExists($enabledRoot . '/database/seeders/exported');
    File::put($enabledRoot . '/database/seeders/exported/settings.php', "<?php return [['id' => 1, 'setting_key' => 'app.enabled', 'name' => 'enabled', 'group_name' => 'app', 'value' => 'v1']];");
    File::ensureDirectoryExists($enabledRoot . '/database/seeders/sql');
    File::put($enabledRoot . '/database/seeders/sql/translate.php', "<?php\nreturn [];\n");

    // 2. Setup DisabledModule (Disabled)
    $disabledRoot = base_path('cms/DisabledModule');
    File::ensureDirectoryExists($disabledRoot . '/database/seeders/exported');
    File::put($disabledRoot . '/database/seeders/exported/settings.php', "<?php return [['id' => 2, 'setting_key' => 'app.disabled', 'name' => 'disabled', 'group_name' => 'app', 'value' => 'v2']];");
    File::ensureDirectoryExists($disabledRoot . '/database/seeders/sql');
    File::put($disabledRoot . '/database/seeders/sql/translate.php', "<?php\nreturn [];\n");
    
    // 3. Module status comes from the activator (ModuleStatus -> Module::find),
    //    so fake it instead of writing the real modules_statuses.json.
    $statuses = ['fakemodule' => true, 'disabledmodule' => false];
    \Nwidart\Modules\Facades\Module::shouldReceive('find')
        ->andReturnUsing(function (string $name) use ($statuses) {
            $key = strtolower($name);
            if (! array_key_exists($key, $statuses)) {
                return null;
            }
            $module = \Mockery::mock(\Nwidart\Modules\Module::class);
            $module->shouldReceive('isEnabled')->andReturn($statuses[$key]);

            return $module;
        });
    // Other services resolved during seeding may list enabled modules; the fake world has none.
    \Nwidart\Modules\Facades\Module::shouldReceive('allEnabled')->andReturn([]);
    
    // 4. Run command
    $exit = Artisan::call('cms:seed-modules', ['--enabled-only' => true]);
    expect($exit)->toBe(0);
    
    // 5. Verify results
    $rows = app('db')->table('settings')->orderBy('id')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->name)->toBe('enabled');
});

test('leaves stable widget definitions to cms sync widgets', function () {
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('DROP TABLE IF EXISTS pg_temp.widget_inputs');
        DB::statement('DROP TABLE IF EXISTS pg_temp.widget_themes');
        DB::statement('DROP TABLE IF EXISTS pg_temp.widgets');
        DB::statement('CREATE TEMPORARY TABLE widgets (id BIGINT PRIMARY KEY, widget_key VARCHAR(191) NOT NULL, name VARCHAR(255) NOT NULL)');
        DB::statement(
            'CREATE TEMPORARY TABLE widget_themes ('
            . 'id BIGINT PRIMARY KEY, '
            . 'widget_id BIGINT NOT NULL REFERENCES widgets(id) ON DELETE CASCADE, '
            . 'theme_key VARCHAR(191) NOT NULL, name VARCHAR(255) NOT NULL)'
        );
        DB::statement(
            'CREATE TEMPORARY TABLE widget_inputs ('
            . 'id BIGINT PRIMARY KEY, '
            . 'widget_id BIGINT NOT NULL REFERENCES widgets(id) ON DELETE CASCADE, '
            . 'input_key VARCHAR(191) NOT NULL, name VARCHAR(255) NOT NULL)'
        );
    } else {
        Schema::dropIfExists('widget_inputs');
        Schema::dropIfExists('widget_themes');
        Schema::dropIfExists('widgets');
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('widget_key', 191);
            $table->string('name');
        });
        Schema::create('widget_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();
            $table->string('theme_key', 191);
            $table->string('name');
        });
        Schema::create('widget_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();
            $table->string('input_key', 191);
            $table->string('name');
        });
    }

    $moduleRoot = base_path('cms/FakeModule');
    $exportDir = $moduleRoot . '/database/seeders/exported';
    File::ensureDirectoryExists($exportDir);
    File::put($exportDir . '/widgets.php', "<?php return [['id' => 1, 'name' => 'Feature Section']];");
    File::put($exportDir . '/widget_themes.php', "<?php return [['id' => 5, 'widget_id' => 1, 'name' => 'Default']];");
    File::put($exportDir . '/widget_inputs.php', "<?php return [['id' => 10, 'widget_id' => 1, 'name' => 'title']];");

    try {
        $exit = Artisan::call('cms:seed-modules', [
            '--module' => ['FakeModule'],
            '--skip-translations' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('owned by cms:sync-widgets')
            ->and(app('db')->table('widgets')->count())->toBe(0)
            ->and(app('db')->table('widget_themes')->count())->toBe(0)
            ->and(app('db')->table('widget_inputs')->count())->toBe(0);
    } finally {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS pg_temp.widget_inputs');
            DB::statement('DROP TABLE IF EXISTS pg_temp.widget_themes');
            DB::statement('DROP TABLE IF EXISTS pg_temp.widgets');
        } else {
            Schema::dropIfExists('widget_inputs');
            Schema::dropIfExists('widget_themes');
            Schema::dropIfExists('widgets');
        }
    }
});

test('settings seed updates existing rows but preserves value, group_name, and name', function () {
    // Pre-populate DB with an existing setting (user has customised value)
    app('db')->table('settings')->insert([
        'id' => 1,
        'setting_key' => 'email.MAIL_MAILER',
        'type' => 'text',
        'group_name' => 'email',
        'name' => 'MAIL_MAILER',
        'value' => 'my-custom-value',
        'input_setting' => '{"title":"t.old_key"}',
    ]);

    // Seed data has updated input_setting but also a different value
    $moduleRoot = base_path('cms/FakeModule');
    $exportDir = $moduleRoot . '/database/seeders/exported';
    File::ensureDirectoryExists($exportDir);
    File::put($exportDir . '/settings.php', <<<'PHP'
    <?php
    return [
        [
            'id' => 1,
            'setting_key' => 'email.MAIL_MAILER',
            'type' => 'select',
            'group_name' => 'email_new',
            'name' => 'MAIL_MAILER_NEW',
            'value' => 'seed-default-value',
            'input_setting' => '{"title":"text.default_mailer"}',
        ],
        [
            'id' => 2,
            'setting_key' => 'email.MAIL_HOST',
            'type' => 'text',
            'group_name' => 'email',
            'name' => 'MAIL_HOST',
            'value' => 'smtp.example.com',
            'input_setting' => '{"title":"text.smtp_host"}',
        ],
    ];
    PHP
    );

    File::ensureDirectoryExists($moduleRoot . '/database/seeders/sql');
    File::put($moduleRoot . '/database/seeders/sql/translate.php', "<?php\nreturn [];\n");

    $exit = Artisan::call('cms:seed-modules', ['--module' => ['FakeModule']]);
    expect($exit)->toBe(0);

    $rows = app('db')->table('settings')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    // Row 1: existing — preserved: value, group_name, name; updated: type, input_setting
    $row1 = $rows[0];
    expect($row1->value)->toBe('my-custom-value');          // preserved
    expect($row1->group_name)->toBe('email');                // preserved
    expect($row1->name)->toBe('MAIL_MAILER');                // preserved
    expect($row1->type)->toBe('select');                     // updated from seed
    expect($row1->input_setting)->toBe('{"title":"text.default_mailer"}'); // updated from seed

    // Row 2: new insert — all columns from seed
    $row2 = $rows[1];
    expect($row2->value)->toBe('smtp.example.com');
    expect($row2->name)->toBe('MAIL_HOST');
    expect($row2->input_setting)->toBe('{"title":"text.smtp_host"}');
});

test('module settings seed matches by setting key and lets database allocate ids', function () {
    app('db')->table('settings')->insert([
        'id' => 999,
        'setting_key' => 'module.setting',
        'type' => 'text',
        'group_name' => 'custom_module',
        'name' => 'custom_setting',
        'value' => 'operator-value',
        'input_setting' => '{"title":"old"}',
    ]);

    $moduleRoot = base_path('cms/FakeModule');
    $exportDir = $moduleRoot . '/database/seeders/exported';
    File::ensureDirectoryExists($exportDir);
    File::put($exportDir . '/settings.php', <<<'PHP'
    <?php
    return [
        [
            'id' => 1,
            'setting_key' => 'module.setting',
            'type' => 'select',
            'group_name' => 'seed_module',
            'name' => 'seed_setting',
            'value' => 'seed-value',
            'input_setting' => '{"title":"new"}',
        ],
        [
            'id' => 2,
            'setting_key' => 'module.new_setting',
            'type' => 'text',
            'group_name' => 'seed_module',
            'name' => 'new_setting',
            'value' => 'new-value',
            'input_setting' => '{"title":"created"}',
        ],
    ];
    PHP
    );

    File::ensureDirectoryExists($moduleRoot . '/database/seeders/sql');
    File::put($moduleRoot . '/database/seeders/sql/translate.php', "<?php\nreturn [];\n");

    $exit = Artisan::call('cms:seed-modules', ['--module' => ['FakeModule']]);
    expect($exit)->toBe(0);

    $existing = app('db')->table('settings')->where('setting_key', 'module.setting')->first();
    expect($existing->id)->toBe(999)
        ->and($existing->value)->toBe('operator-value')
        ->and($existing->group_name)->toBe('custom_module')
        ->and($existing->name)->toBe('custom_setting')
        ->and($existing->type)->toBe('select')
        ->and($existing->input_setting)->toBe('{"title":"new"}');

    $created = app('db')->table('settings')->where('setting_key', 'module.new_setting')->first();
    expect($created->id)->not->toBe(2)
        ->and($created->value)->toBe('new-value');
});

test('translates export keeps text but copies updated_at from the export', function () {
    Schema::dropIfExists('translates');
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
        $table->boolean('is_used')->default(false);
        $table->timestamp('last_scanned_at')->nullable();
        $table->text('used_in')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    try {
        app('db')->table('translates')->insert([
            'id' => 256,
            'modules' => '["CmsManagement","Core"]',
            'group_name' => 'text',
            'name' => 'text.cmsm.actions.extend_1yr',
            'text' => '{"en":"Extend 1 year","vi":"Gia hạn thêm 1 năm"}',
            'created_at' => '2022-01-17 20:48:08',
            'updated_at' => '2026-10-20 08:00:00',
        ]);

        $moduleRoot = base_path('cms/FakeModule');
        $exportDir = $moduleRoot . '/database/seeders/exported';
        File::ensureDirectoryExists($exportDir);
        File::put($exportDir . '/translates.php', <<<'PHP'
        <?php
        return [
            [
                'id' => 256,
                'modules' => '["CmsManagement","Core"]',
                'group_name' => 'text',
                'name' => 'text.cmsm.actions.extend_1yr',
                'text' => '{"en":"Extend 1yr","vi":"Gia hạn 1 năm"}',
                'for_web' => 0,
                'for_adm' => 1,
                'for_app' => 0,
                'status' => 'status_published',
                'is_used' => 0,
                'last_scanned_at' => '2026-02-08 10:19:39',
                'used_in' => null,
                'created_at' => '2022-01-17 20:48:08',
                'updated_at' => '2022-01-17 20:48:08',
                'deleted_at' => null,
            ],
        ];
        PHP
        );

        $exit = Artisan::call('cms:seed-modules', ['--module' => ['FakeModule'], '--skip-translations' => true]);
        $row = app('db')->table('translates')->where('id', 256)->first();

        expect($exit)->toBe(0)
            ->and((string) $row->updated_at)->toBe('2022-01-17 20:48:08')
            ->and($row->text)->toBe('{"en":"Extend 1 year","vi":"Gia hạn thêm 1 năm"}')
            ->and((string) $row->last_scanned_at)->toBe('2026-02-08 10:19:39');
    } finally {
        Schema::dropIfExists('translates');
    }
});
