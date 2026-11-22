<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Translate;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Facades\Module;

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
});

afterEach(function () {
    Schema::dropIfExists('translates');
    config()->set('database.default', $this->originalDatabaseDefault);
    config()->set('database.connections.sqlite.database', $this->originalSqliteDatabase);
    File::deleteDirectory(storage_path('framework/testing/modules'));
});

test('translation sync command imports module translate file', function () {
    Translate::query()->create([
        'modules' => json_encode(['Core']),
        'group_name' => 'messages',
        'name' => 'mock.key',
        'text' => ['en' => 'Original'],
        'for_web' => false,
        'for_adm' => true,
        'for_app' => false,
        'status' => 'published',
    ]);

    $moduleRoot = storage_path('framework/testing/modules/Product');
    $sqlDir = $moduleRoot . '/database/seeders/sql';

    File::ensureDirectoryExists($sqlDir);

    $translateSeeder = <<<PHP
    <?php

    return [
        [
            'modules' => ['Product'],
            'group_name' => 'messages',
            'name' => 'mock.key',
            'text' => ['en' => 'Updated'],
            'for_web' => true,
            'for_adm' => true,
            'for_app' => false,
            'status' => 'draft',
        ],
        [
            'modules' => ['Product'],
            'group_name' => 'messages',
            'name' => 'mock.new',
            'text' => ['en' => 'New value'],
            'for_web' => false,
            'for_adm' => false,
            'for_app' => true,
            'status' => null,
        ],
    ];
    PHP;

    File::put($sqlDir . '/translate.php', $translateSeeder);

    $fakeModule = Mockery::mock(\Nwidart\Modules\Laravel\Module::class);
    $fakeModule->shouldReceive('getName')->andReturn('Product');
    $fakeModule->shouldReceive('getLowerName')->andReturn('product');
    $fakeModule->shouldReceive('getPath')->andReturn($moduleRoot);
    $fakeModule->shouldReceive('getExtraPath')->andReturnUsing(
        fn(string $path) => $moduleRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)
    );

    Module::shouldReceive('allEnabled')->andReturn([$fakeModule]);

    $exit = Artisan::call('cms:translation-sync');
    expect($exit)->toBe(Command::SUCCESS);

    $updated = Translate::query()->where('name', 'mock.key')->first();
    expect($updated)->not->toBeNull();
    expect($updated->modules)->toMatchArray(['Core', 'Product']);
    expect($updated->status)->toBe('draft');

    $created = Translate::query()->where('name', 'mock.new')->first();
    expect($created)->not->toBeNull();
    expect($created->modules)->toMatchArray(['Product']);
});

test('translation sync command warns when module missing', function () {
    Module::shouldReceive('allEnabled')->andReturn([]);
    Module::shouldReceive('all')->andReturn([]);
    Module::shouldReceive('find')->with('Unknown')->andReturn(null);

    $exit = Artisan::call('cms:translation-sync', ['module' => 'Unknown']);
    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('No matching CMS modules found.');
});

test('translation sync command can include disabled modules via all flag', function () {
    $moduleRoot = storage_path('framework/testing/modules/Blog');
    $sqlDir = $moduleRoot . '/database/seeders/sql';

    File::ensureDirectoryExists($sqlDir);
    File::put($sqlDir . '/translate.php', '<?php return [];');

    $disabledModule = Mockery::mock(\Nwidart\Modules\Laravel\Module::class);
    $disabledModule->shouldReceive('getName')->andReturn('Blog');
    $disabledModule->shouldReceive('getLowerName')->andReturn('blog');
    $disabledModule->shouldReceive('getPath')->andReturn($moduleRoot);
    $disabledModule->shouldReceive('getExtraPath')->andReturnUsing(
        fn(string $path) => $moduleRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)
    );

    Module::shouldReceive('allEnabled')->andReturn([]);
    Module::shouldReceive('all')->andReturn([$disabledModule]);

    $exit = Artisan::call('cms:translation-sync', ['--all' => true]);
    expect($exit)->toBe(Command::SUCCESS);

    File::deleteDirectory($moduleRoot);
});

test('translation sync dry run lists sidecar rewords without writing, then a real run lets the sidecar win', function () {
    \Illuminate\Support\Facades\DB::table('translates')->insert([
        'modules' => json_encode(['CmsManagement']),
        'group_name' => 'text',
        'name' => 'text.cmsm.donations.title',
        'text' => json_encode(['en' => 'Donations', 'vi' => 'Quyên góp']),
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-03-16 18:58:00',
        'updated_at' => '2026-10-20 08:00:00',
    ]);

    $moduleRoot = storage_path('framework/testing/modules/CmsManagement');
    $sqlDir = $moduleRoot . '/database/seeders/sql';
    File::ensureDirectoryExists($sqlDir);
    File::put($sqlDir . '/translate.php', '<?php return [];');
    File::put($sqlDir . '/translate-admin-authored.php', <<<'PHP'
    <?php

    return [
        [
            'id' => 993165,
            'modules' => ['CmsManagement'],
            'group_name' => 'text',
            'name' => 'text.cmsm.donations.title',
            'text' => ['en' => 'Payments', 'vi' => 'Giao dịch'],
            'for_web' => 0,
            'for_adm' => 1,
            'for_app' => 0,
            'status' => 'status_published',
            'created_at' => '2026-03-16 18:58:00',
            'updated_at' => '2026-03-16 18:58:00',
        ],
        [
            'id' => 993165,
            'modules' => ['CmsManagement'],
            'group_name' => 'text',
            'name' => 'text.cmsm.donations.title',
            'text' => ['en' => 'Payments', 'vi' => 'Thanh toán'],
            'for_web' => 0,
            'for_adm' => 1,
            'for_app' => 0,
            'status' => 'status_published',
            'created_at' => '2026-03-16 18:58:00',
            'updated_at' => '2026-03-16 18:58:00',
        ],
    ];
    PHP);

    $fakeModule = Mockery::mock(\Nwidart\Modules\Laravel\Module::class);
    $fakeModule->shouldReceive('getName')->andReturn('CmsManagement');
    $fakeModule->shouldReceive('getLowerName')->andReturn('cmsmanagement');
    $fakeModule->shouldReceive('getPath')->andReturn($moduleRoot);
    $fakeModule->shouldReceive('getExtraPath')->andReturnUsing(
        fn(string $path) => $moduleRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)
    );
    Module::shouldReceive('allEnabled')->andReturn([$fakeModule]);

    $exit = Artisan::call('cms:translation-sync', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('would be added 0, linked 0, updated 1')
        ->and($output)->toContain('reword: text.cmsm.donations.title [vi] "Quyên góp" → "Thanh toán"')
        ->and($output)->toContain('duplicate: text.cmsm.donations.title defined 2 times with different text')
        ->and($output)->toContain('Dry run completed; no translation rows were written.')
        ->and(json_decode((string) \Illuminate\Support\Facades\DB::table('translates')
            ->where('name', 'text.cmsm.donations.title')->value('text'), true))
        ->toBe(['en' => 'Donations', 'vi' => 'Quyên góp']);

    $exit = Artisan::call('cms:translation-sync');
    $output = Artisan::output();

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('added 0, linked 0, updated 1')
        ->and(Translate::query()->where('name', 'text.cmsm.donations.title')->first()->text)
        ->toBe(['en' => 'Payments', 'vi' => 'Thanh toán']);

    Artisan::call('cms:translation-sync');
    $output = Artisan::output();

    expect($output)->toContain('added 0, linked 0, updated 0')
        ->and($output)->toContain('duplicate: text.cmsm.donations.title defined 2 times with different text');
});
