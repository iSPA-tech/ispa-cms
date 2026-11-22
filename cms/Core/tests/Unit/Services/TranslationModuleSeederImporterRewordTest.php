<?php

/**
 * Source-wins rule of TranslationModuleSeederImporter.
 *
 * Authored admin sidecar records (sql/translate-admin-*.php) overwrite the text of an existing,
 * live row matched by name whenever a non-empty locale differs, regardless of any timestamp.
 * Exported records never change text. Duplicate authored names resolve to the last definition.
 */

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Translate;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', config('app.timezone')));
    $this->moduleRoot = storage_path('framework/testing/reword-modules/Widget');
});

afterEach(function () {
    Carbon::setTestNow();
    Schema::dropIfExists('translates');
    File::deleteDirectory(storage_path('framework/testing/reword-modules'));
    config()->set('database.default', $this->originalDatabaseDefault);
    config()->set('database.connections.sqlite.database', $this->originalSqliteDatabase);
});

/**
 * Insert a translates row with explicit timestamps.
 *
 * @param array<string, string> $text
 * @param array<string, mixed> $extra
 */
function rewordInsertRow(string $name, array $text, string $updatedAt, array $extra = []): int
{
    return (int) DB::table('translates')->insertGetId(array_merge([
        'modules' => json_encode(['CmsManagement']),
        'group_name' => 'text',
        'name' => $name,
        'text' => json_encode($text),
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-03-16 18:58:00',
        'updated_at' => $updatedAt,
    ], $extra));
}

/**
 * Build a sidecar record shaped like the `$entry()` helper output.
 *
 * @param array<string, string> $text
 * @return array<string, mixed>
 */
function rewordRecord(string $name, array $text, ?string $updatedAt = null, int $id = 993002): array
{
    $createdAt = '2026-03-16 18:58:00';

    return [
        'id' => $id,
        'modules' => ['CmsManagement'],
        'group_name' => 'text',
        'name' => $name,
        'text' => $text,
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt ?? $createdAt,
    ];
}

/**
 * Read the stored text of a key, including soft-deleted rows.
 *
 * @return array<string, mixed>
 */
function rewordStoredText(string $name): array
{
    return json_decode((string) DB::table('translates')->where('name', $name)->value('text'), true) ?? [];
}

/**
 * Write a PHP file returning the given records.
 *
 * @param array<int, array<string, mixed>> $records
 */
function rewordWriteSeeder(string $path, array $records): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php return ' . var_export($records, true) . ';');
}

test('authored sidecar record overwrites an existing row and is idempotent', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem'], '2026-03-16 18:58:00');
    $importer = new TranslationModuleSeederImporter();
    $authored = [rewordRecord('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết'], '2026-10-13 10:00:00')];

    $first = $importer->importRecords('CmsManagement', [], 'dummy', $authored);

    expect($first['updated'])->toBe(1)
        ->and($first['created'])->toBe(0)
        ->and($first['linked'])->toBe(0)
        ->and($first['duplicates'])->toBe([])
        ->and($first['rewords'][0]['name'])->toBe('text.cmsm.actions.view')
        ->and($first['rewords'][0]['before'])->toBe(['en' => 'View', 'vi' => 'Xem'])
        ->and($first['rewords'][0]['after'])->toBe(['en' => 'View details', 'vi' => 'Xem chi tiết'])
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View details', 'vi' => 'Xem chi tiết'])
        ->and((string) DB::table('translates')->where('name', 'text.cmsm.actions.view')->value('updated_at'))
        ->toBe('2026-10-14 09:00:00');

    Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', config('app.timezone')));
    $second = $importer->importRecords('CmsManagement', [], 'dummy', $authored);

    expect($second['updated'])->toBe(0)
        ->and($second['rewords'])->toBe([])
        ->and((string) DB::table('translates')->where('name', 'text.cmsm.actions.view')->value('updated_at'))
        ->toBe('2026-10-14 09:00:00');
});

test('authored record overwrites even when the row is newer and the record has no updatedAt marker', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem hồ sơ'], '2026-10-20 10:30:00');
    rewordInsertRow('text.cmsm.actions.edit', ['en' => 'Edit', 'vi' => 'Sửa'], '2026-10-20 10:30:00');

    // The first record is older than its row's updated_at; the second is a default entry
    // (updated_at equals created_at). Source wins in both cases.
    $result = (new TranslationModuleSeederImporter())->importRecords(
        'CmsManagement',
        [],
        'dummy',
        [
            rewordRecord('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết'], '2026-10-13 10:00:00'),
            rewordRecord('text.cmsm.actions.edit', ['en' => 'Edit', 'vi' => 'Chỉnh sửa']),
        ]
    );

    expect($result['updated'])->toBe(2)
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View details', 'vi' => 'Xem chi tiết'])
        ->and(rewordStoredText('text.cmsm.actions.edit'))->toBe(['en' => 'Edit', 'vi' => 'Chỉnh sửa']);
});

test('exported records never change text even when shaped as a reword', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem'], '2026-01-01 00:00:00');
    $reword = rewordRecord('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết'], '2026-10-13 10:00:00');
    $importer = new TranslationModuleSeederImporter();

    // Direct importRecords() callers such as TranslateTableSeed pass exported rows only.
    $direct = $importer->importRecords('Core', [$reword], 'dummy');

    // Module translate.php is exported data even when the sidecar directory is scanned.
    $sqlDir = $this->moduleRoot . '/database/seeders/sql';
    rewordWriteSeeder($sqlDir . '/translate.php', [$reword]);
    $fromFile = $importer->importFromFile('Widget', $sqlDir . '/translate.php', $sqlDir);

    expect($direct['updated'])->toBe(0)
        ->and($fromFile['updated'])->toBe(0)
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View', 'vi' => 'Xem']);
});

test('record with identical text is a no-op that keeps the row timestamp', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết'], '2026-01-01 00:00:00');

    $result = (new TranslationModuleSeederImporter())->importRecords(
        'CmsManagement',
        [],
        'dummy',
        [rewordRecord('text.cmsm.actions.view', ['en' => ' View details', 'vi' => 'Xem chi tiết '])]
    );

    expect($result['updated'])->toBe(0)
        ->and((string) DB::table('translates')->where('name', 'text.cmsm.actions.view')->value('updated_at'))
        ->toBe('2026-01-01 00:00:00');
});

test('soft-deleted row is neither revived nor reworded', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem'], '2026-01-01 00:00:00', [
        'deleted_at' => '2026-02-01 00:00:00',
    ]);

    $result = (new TranslationModuleSeederImporter())->importRecords(
        'CmsManagement',
        [],
        'dummy',
        [rewordRecord('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết'])]
    );

    expect($result['updated'])->toBe(0)
        ->and($result['created'])->toBe(0)
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View', 'vi' => 'Xem'])
        ->and(DB::table('translates')->where('name', 'text.cmsm.actions.view')->value('deleted_at'))->not->toBeNull();
});

test('record sets its locales, skips empty ones and keeps other locales of the row', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'ja' => '表示', 'vi' => 'Xem'], '2026-01-01 00:00:00');

    $result = (new TranslationModuleSeederImporter())->importRecords(
        'CmsManagement',
        [],
        'dummy',
        [rewordRecord('text.cmsm.actions.view', ['en' => '  ', 'vi' => 'Xem chi tiết'])]
    );

    expect($result['updated'])->toBe(1)
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View', 'ja' => '表示', 'vi' => 'Xem chi tiết']);
});

test('reword never applies through the id fallback to another key', function () {
    $otherId = rewordInsertRow('text.cmsm.other', ['en' => 'Other', 'vi' => 'Khác'], '2026-01-01 00:00:00');

    $result = (new TranslationModuleSeederImporter())->importRecords(
        'Webmail',
        [],
        'dummy',
        [rewordRecord('text.cmsm.renamed', ['en' => 'Renamed', 'vi' => 'Đổi tên'], '2026-10-13 10:00:00', $otherId)]
    );

    expect($result['updated'])->toBe(0)
        ->and($result['linked'])->toBe(1)
        ->and(rewordStoredText('text.cmsm.other'))->toBe(['en' => 'Other', 'vi' => 'Khác']);
});

test('duplicate authored names with different text apply the last one, report it and stay idempotent', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem'], '2026-01-01 00:00:00');
    $importer = new TranslationModuleSeederImporter();
    $authored = [
        rewordRecord('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Một']),
        rewordRecord('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Hai']),
        rewordRecord(' Text.Cmsm.Actions.View ', ['en' => 'View', 'vi' => 'Ba']),
        rewordRecord('text.cmsm.same', ['en' => 'Same', 'vi' => 'Giống'], null, 993003),
        rewordRecord('text.cmsm.same', ['en' => 'Same ', 'vi' => ' Giống'], null, 993003),
        rewordRecord('text.cmsm.fresh', ['en' => 'Fresh', 'vi' => 'Cũ'], null, 993004),
        rewordRecord('text.cmsm.fresh', ['en' => 'Fresh', 'vi' => 'Mới'], null, 993004),
    ];

    $first = $importer->importRecords('CmsManagement', [], 'dummy', $authored);

    expect($first['updated'])->toBe(1)
        ->and($first['created'])->toBe(2)
        ->and($first['total'])->toBe(7)
        ->and($first['duplicates'])->toBe([
            ['name' => 'Text.Cmsm.Actions.View', 'count' => 3],
            ['name' => 'text.cmsm.fresh', 'count' => 2],
        ])
        ->and(TranslationModuleSeederImporter::describeDuplicates($first))->toBe([
            'duplicate: Text.Cmsm.Actions.View defined 3 times with different text',
            'duplicate: text.cmsm.fresh defined 2 times with different text',
        ])
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View', 'vi' => 'Ba'])
        ->and(rewordStoredText('text.cmsm.fresh'))->toBe(['en' => 'Fresh', 'vi' => 'Mới']);

    $second = $importer->importRecords('CmsManagement', [], 'dummy', $authored);

    expect($second['updated'])->toBe(0)
        ->and($second['created'])->toBe(0)
        ->and($second['duplicates'])->toBe($first['duplicates'])
        ->and(rewordStoredText('text.cmsm.actions.view'))->toBe(['en' => 'View', 'vi' => 'Ba']);
});

test('duplicate names resolve by file order, files sorted by path', function () {
    rewordInsertRow('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Bố cục'], '2026-01-01 00:00:00', [
        'modules' => json_encode(['Widget']),
    ]);

    $sqlDir = $this->moduleRoot . '/database/seeders/sql';
    rewordWriteSeeder($sqlDir . '/translate.php', []);
    rewordWriteSeeder($sqlDir . '/translate-admin-b-later.php', [
        rewordRecord('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Từ tệp b']),
    ]);
    rewordWriteSeeder($sqlDir . '/translate-admin-a-first.php', [
        rewordRecord('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Từ tệp a']),
    ]);

    $result = (new TranslationModuleSeederImporter())->importFromModule('Widget', $this->moduleRoot);

    expect($result['updated'])->toBe(1)
        ->and($result['duplicates'])->toBe([['name' => 'widget.site_layout.title', 'count' => 2]])
        ->and(rewordStoredText('widget.site_layout.title'))->toBe(['en' => 'Site layout', 'vi' => 'Từ tệp b']);
});

test('dry run reports created, linked and updated keys without writing', function () {
    rewordInsertRow('text.cmsm.actions.view', ['en' => 'View', 'vi' => 'Xem'], '2026-01-01 00:00:00');
    rewordInsertRow('text.cmsm.shared', ['en' => 'Shared', 'vi' => 'Dùng chung'], '2026-01-01 00:00:00');
    $before = DB::table('translates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $result = (new TranslationModuleSeederImporter())->importRecords(
        'Webmail',
        [
            rewordRecord('text.cmsm.shared', ['en' => 'Shared', 'vi' => 'Dùng chung']),
            rewordRecord('text.cmsm.brand_new', ['en' => 'Old', 'vi' => 'Cũ']),
        ],
        'dummy',
        [
            rewordRecord('text.cmsm.actions.view', ['en' => 'View details', 'vi' => 'Xem chi tiết']),
            rewordRecord('text.cmsm.brand_new', ['en' => 'New', 'vi' => 'Mới']),
            rewordRecord('text.cmsm.brand_new', ['en' => 'New', 'vi' => 'Mới hơn']),
        ],
        true
    );

    expect($result['dry_run'])->toBeTrue()
        ->and($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(1)
        ->and($result['linked'])->toBe(2)
        ->and($result['duplicates'])->toBe([['name' => 'text.cmsm.brand_new', 'count' => 2]])
        ->and(array_column($result['rewords'], 'name'))->toBe(['text.cmsm.actions.view'])
        ->and(TranslationModuleSeederImporter::describeRewords($result))
        ->toBe(['text.cmsm.actions.view [vi] "Xem" → "Xem chi tiết"'])
        ->and(DB::table('translates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});

test('module import applies sql sidecar wording, lets sidecar wording win on creation and ignores exported sidecars', function () {
    rewordInsertRow('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Bố cục'], '2026-10-16 00:00:00', [
        'modules' => json_encode(['Widget']),
    ]);

    $sqlDir = $this->moduleRoot . '/database/seeders/sql';
    rewordWriteSeeder($sqlDir . '/translate.php', [
        rewordRecord('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Bố cục'], null, 1),
        rewordRecord('widget.site_layout.new', ['en' => 'Old wording', 'vi' => 'Cách gọi cũ'], null, 2),
    ]);
    rewordWriteSeeder($sqlDir . '/translate-admin-widget-site-layout.php', [
        rewordRecord('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Bố cục trang'], null, 1),
        rewordRecord('widget.site_layout.new', ['en' => 'New wording', 'vi' => 'Cách gọi mới'], null, 2),
    ]);
    rewordWriteSeeder($this->moduleRoot . '/database/seeders/exported/translates-admin-widget-site-layout.php', [
        rewordRecord('widget.site_layout.title', ['en' => 'Site layout', 'vi' => 'Bản xuất'], '2026-10-14 08:00:00', 1),
    ]);

    $result = (new TranslationModuleSeederImporter())->importFromModule('Widget', $this->moduleRoot);

    expect($result['updated'])->toBe(1)
        ->and($result['created'])->toBe(1)
        ->and(rewordStoredText('widget.site_layout.title'))->toBe(['en' => 'Site layout', 'vi' => 'Bố cục trang'])
        ->and(rewordStoredText('widget.site_layout.new'))->toBe(['en' => 'New wording', 'vi' => 'Cách gọi mới']);

    $dryRun = (new TranslationModuleSeederImporter())->importFromModule('Widget', $this->moduleRoot, true);

    expect($dryRun['updated'])->toBe(0)
        ->and($dryRun['created'])->toBe(0);
});
