<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->originalAppEnv = [
        'config' => config('app.env'),
        'env' => getenv('APP_ENV') !== false ? getenv('APP_ENV') : null,
        'server' => $_SERVER['APP_ENV'] ?? null,
        'array' => $_ENV['APP_ENV'] ?? null,
    ];

    $this->originalDbEnv = [
        'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : null,
        'DB_USERNAME' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : null,
        'DB_HOST' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : null,
        'DB_DATABASE' => getenv('DB_DATABASE') !== false ? getenv('DB_DATABASE') : null,
    ];

    config()->set('app.env', 'production');
    putenv('APP_ENV=production');
    $_SERVER['APP_ENV'] = 'production';
    $_ENV['APP_ENV'] = 'production';

    putenv('DB_PASSWORD=testpass');
    putenv('DB_USERNAME=testuser');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_DATABASE=testdb');
});

afterEach(function () {
    config()->set('app.env', $this->originalAppEnv['config']);

    if ($this->originalAppEnv['env'] !== null) {
        putenv('APP_ENV=' . $this->originalAppEnv['env']);
    } else {
        putenv('APP_ENV');
    }

    if ($this->originalAppEnv['server'] !== null) {
        $_SERVER['APP_ENV'] = $this->originalAppEnv['server'];
    } else {
        unset($_SERVER['APP_ENV']);
    }

    if ($this->originalAppEnv['array'] !== null) {
        $_ENV['APP_ENV'] = $this->originalAppEnv['array'];
    } else {
        unset($_ENV['APP_ENV']);
    }

    foreach ($this->originalDbEnv as $key => $value) {
        if ($value !== null) {
            putenv($key . '=' . $value);
        } else {
            putenv($key);
        }
    }
});

function coreExportDbOutputPath(): array
{
    // Never target the tracked cms/Core/database/seeders/exported/translates.php.
    $outputPath = storage_path('framework/testing/export-db/translates.php');
    $originalContent = null;

    File::ensureDirectoryExists(dirname($outputPath));
    File::delete($outputPath);

    if (!Schema::hasTable('translates')) {
        Schema::create('translates', function ($table): void {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    DB::table('translates')->truncate();

    return [$outputPath, $originalContent];
}

function coreRestoreExportDbOutputPath(string $outputPath, ?string $originalContent): void
{
    if ($originalContent !== null) {
        File::put($outputPath, $originalContent);
        return;
    }

    File::delete($outputPath);
}

test('exports allowed database table to a php array file', function () {
    [$outputPath, $originalContent] = coreExportDbOutputPath();
    DB::table('translates')->insert([
        'key' => 'home.title',
        'value' => 'Trang chu',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $exit = Artisan::call('cms:export-db', ['table' => 'translates', '--target' => dirname($outputPath)]);
        expect($exit)->toBe(0);

        expect(File::exists($outputPath))->toBeTrue();
        expect(File::get($outputPath))->toContain('home.title');
        expect(Artisan::output())->toContain('Data from table translates has been exported');
    } finally {
        DB::table('translates')->truncate();
        coreRestoreExportDbOutputPath($outputPath, $originalContent);
    }
});

test('reports failure when the source table is missing', function () {
    [$outputPath, $originalContent] = coreExportDbOutputPath();
    Schema::dropIfExists('translates');

    try {
        Artisan::call('cms:export-db', ['table' => 'translates', '--target' => dirname($outputPath)]);
        expect(Artisan::output())->toContain('no such table');
    } finally {
        coreRestoreExportDbOutputPath($outputPath, $originalContent);
    }
});

test('rejects non-whitelisted tables', function () {
    Artisan::call('cms:export-db', ['table' => 'non_existent_table']);
    expect(Artisan::output())->toContain('Table name is not allowed to export data');
});

test('settings export redacts values that require encrypted storage', function () {
    $originalBasePath = app()->basePath();
    $originalAppUrl = config('app.url');
    $tempBasePath = storage_path('framework/testing/export-settings-' . Str::uuid());
    $outputPath = $tempBasePath . '/cms/Core/database/seeders/exported/settings.php';
    $createdTable = false;

    File::ensureDirectoryExists(dirname($outputPath));
    app()->setBasePath($tempBasePath);
    config()->set('app.url', 'http://cms-dev.test');

    if (! Schema::hasTable('settings')) {
        Schema::create('settings', function ($table): void {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('type')->default('text');
            $table->string('group_name')->nullable();
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->text('input_setting')->nullable();
            $table->timestamps();
        });
        $createdTable = true;
    }

    $keys = [
        'test.export.public',
        'test.export.private',
        'test.export.password',
        'test.export.legacy_public',
        'test.export.media',
    ];
    DB::table('settings')->whereIn('setting_key', $keys)->delete();
    DB::table('settings')->insert([
        [
            'setting_key' => $keys[0],
            'type' => 'text',
            'group_name' => 'test',
            'name' => 'public',
            'value' => 'public-value',
            'input_setting' => json_encode(['isEnv' => false, 'isPublic' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'setting_key' => $keys[1],
            'type' => 'text',
            'group_name' => 'test',
            'name' => 'private',
            'value' => 'foreign-app-key-ciphertext',
            'input_setting' => json_encode(
                json_encode(['isEnv' => true, 'isPublic' => false], JSON_THROW_ON_ERROR),
                JSON_THROW_ON_ERROR
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'setting_key' => $keys[2],
            'type' => 'password',
            'group_name' => 'test',
            'name' => 'password',
            'value' => 'foreign-password-ciphertext',
            'input_setting' => json_encode(['isEnv' => false, 'isPublic' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'setting_key' => $keys[3],
            'type' => 'text',
            'group_name' => 'test',
            'name' => 'legacy_public',
            'value' => 'legacy-public-value',
            'input_setting' => json_encode(['title' => 'Legacy public setting'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'setting_key' => $keys[4],
            'type' => 'image',
            'group_name' => 'test',
            'name' => 'media',
            'value' => json_encode([[
                'path' => 'image/2026/06',
                'name' => 'logo.png',
                'url' => 'http://cms-dev.test/storage/image/2026/06/logo.png',
                'cdn_url' => 'https://cdn.example.test/storage/image/2026/06/logo.png',
            ]], JSON_THROW_ON_ERROR),
            'input_setting' => json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    try {
        expect(Artisan::call('cms:export-db', ['table' => 'settings', '--target' => dirname($outputPath)]))->toBe(0);

        $rows = collect(require $outputPath)->keyBy('setting_key');
        $media = json_decode($rows[$keys[4]]['value'], true, 512, JSON_THROW_ON_ERROR);

        expect($rows[$keys[0]]['value'])->toBe('public-value')
            ->and($rows[$keys[1]]['value'])->toBeNull()
            ->and($rows[$keys[2]]['value'])->toBeNull()
            ->and($rows[$keys[3]]['value'])->toBe('legacy-public-value')
            ->and($media[0]['url'])->toBe('/storage/image/2026/06/logo.png')
            ->and($media[0]['cdn_url'])->toBe('https://cdn.example.test/storage/image/2026/06/logo.png');
    } finally {
        app()->setBasePath($originalBasePath);
        config()->set('app.url', $originalAppUrl);

        if ($createdTable) {
            Schema::drop('settings');
        } else {
            DB::table('settings')->whereIn('setting_key', $keys)->delete();
        }

        File::deleteDirectory($tempBasePath);
    }
});
