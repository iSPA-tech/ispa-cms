<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Translate;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

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
    config()->set('database.default', $this->originalDatabaseDefault);
    config()->set('database.connections.sqlite.database', $this->originalSqliteDatabase);
});

test('imports records by inserting new rows and merging module lists', function () {
    Translate::query()->create([
        'modules' => json_encode(['Core']),
        'group_name' => 'messages',
        'name' => 'shared.key',
        'text' => ['en' => 'Existing'],
        'for_web' => false,
        'for_adm' => true,
        'for_app' => false,
        'status' => 'published',
    ]);

    $importer = new TranslationModuleSeederImporter();

    $records = [
        [
            'modules' => '["Blog"]',
            'group_name' => 'messages',
            'name' => 'shared.key',
            'text' => ['en' => 'Updated'],
            'for_web' => true,
            'for_adm' => true,
            'for_app' => false,
            'status' => 'draft',
        ],
        [
            'modules' => ['Blog'],
            'group_name' => 'messages',
            'name' => 'blog.only',
            'text' => '{"en":"New"}',
            'for_web' => true,
            'for_adm' => false,
            'for_app' => true,
            'status' => 'active',
        ],
        [
            'modules' => ['Blog'],
            'group_name' => 'messages',
            'name' => 'blog.plain',
            'text' => 'Plain text',
        ],
        ['invalid'],
    ];

    $result = $importer->importRecords('Blog', $records, 'dummy');

    expect($result['created'])->toBe(2);
    expect($result['linked'])->toBe(1);
    expect($result['skipped'])->toBe(1);
    expect($result['total'])->toBe(4);

    $updated = Translate::query()->where('name', 'shared.key')->first();
    expect($updated->modules)->toMatchArray(['Core', 'Blog']);
    expect($updated->status)->toBe('draft');

    $created = Translate::query()->where('name', 'blog.only')->first();
    expect($created->text)->toMatchArray(['en' => 'New']);

    $plain = Translate::query()->where('name', 'blog.plain')->first();
    expect($plain->text)->toMatchArray(['value' => 'Plain text']);
});

test('importer matches by id when a key has been renamed', function () {
    Translate::query()->insert([
        'id' => 99,
        'modules' => json_encode(['Core']),
        'group_name' => 'messages',
        'name' => 'new.key',
        'text' => json_encode(['en' => 'Existing']),
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'published',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $importer = new TranslationModuleSeederImporter();

    $records = [
        [
            'id' => 99,
            'modules' => ['Core'],
            'group_name' => 'messages',
            'name' => 'old.key',
            'text' => ['en' => 'Existing'],
            'for_web' => false,
            'for_adm' => true,
            'for_app' => false,
            'status' => 'published',
        ],
    ];

    $result = $importer->importRecords('Core', $records, 'dummy');

    expect($result['created'])->toBe(0);
    expect(Translate::query()->where('name', 'old.key')->exists())->toBeFalse();
    expect(Translate::query()->where('id', 99)->value('name'))->toBe('new.key');
});

test('a fresh install never links an exported id to a row the import just created', function () {
    $row = static fn (?int $id, string $name): array => array_filter([
        'id' => $id, 'modules' => ['Core'], 'group_name' => 'text', 'name' => $name,
        'text' => ['en' => $name, 'vi' => $name], 'for_adm' => true, 'status' => 'published',
    ], static fn ($value) => $value !== null);

    $importer = app(TranslationModuleSeederImporter::class);
    // The authored sidecar row is created first and gets id 1 from the database; the export says id 1 is another key.
    $importer->importRecords('Core', [$row(1, 'text.dashboard.incoming')], 'dummy', [$row(null, 'text.passkey.title')]);
    // A later module of the same install: its exported id 2 is a row Core created moments ago.
    $second = app(TranslationModuleSeederImporter::class)->importRecords('Content', [$row(2, 'text.dashboard.recents-posts')], 'dummy');

    expect(Translate::query()->whereIn('name', ['text.passkey.title', 'text.dashboard.incoming', 'text.dashboard.recents-posts'])->count())->toBe(3)
        ->and($second['created'])->toBe(1);
});

test('importFromFile handles missing and invalid files', function () {
    $importer = new TranslationModuleSeederImporter();

    $missing = $importer->importFromFile('Blog', storage_path('framework/testing/missing.php'));
    expect($missing['found'])->toBeFalse();
    expect($missing['valid'])->toBeFalse();

    $tempDir = storage_path('framework/testing');
    File::ensureDirectoryExists($tempDir);

    $invalidFile = $tempDir . '/invalid_translate.php';
    File::put($invalidFile, '<?php return "invalid";');

    try {
        $invalid = $importer->importFromFile('Blog', $invalidFile);
        expect($invalid['found'])->toBeTrue();
        expect($invalid['valid'])->toBeFalse();
    } finally {
        File::delete($invalidFile);
    }
});

test('importFromModule resolves module path fallback', function () {
    $moduleDir = storage_path('framework/testing/blogmodule/database/seeders/sql');
    File::deleteDirectory(storage_path('framework/testing/blogmodule'));
    File::ensureDirectoryExists($moduleDir);

    $file = $moduleDir . '/translate.php';
    File::put($file, '<?php return [];');

    $importer = new TranslationModuleSeederImporter();
    $result = $importer->importFromModule('Blog', storage_path('framework/testing/blogmodule'));

    expect($result['found'])->toBeTrue();
    expect($result['valid'])->toBeTrue();
    expect($result['total'])->toBe(0);

    File::deleteDirectory(storage_path('framework/testing/blogmodule'));
});
