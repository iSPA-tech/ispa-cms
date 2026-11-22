<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\ServerNightlyStatus;
use Cms\Core\Services\Modules\Install\InstallBackupAdvice;
use Illuminate\Support\Carbon;

/**
 * default of the pre-install backup switch and the site's last own backup.
 */
afterEach(function () {
    Carbon::setTestNow();
});

test('the switch defaults on for irreversible, unknown or table-altering migrations, off otherwise', function (bool $irreversible, ?array $migrations, array $ran, bool $recommended, string $reason) {
    $tables = ['users', 'posts'];
    $advice = InstallBackupAdvice::advise($irreversible, $migrations, $ran, fn (string $t): bool => in_array($t, $tables, true));

    expect($advice['recommended'])->toBe($recommended)->and($advice['reason'])->toBe($reason);
})->with([
    'irreversible wins' => [true, [], [], true, InstallBackupAdvice::REASON_IRREVERSIBLE],
    'hub without a migration list' => [false, null, [], true, InstallBackupAdvice::REASON_UNKNOWN],
    'no migrations at all' => [false, [], [], false, InstallBackupAdvice::REASON_NO_NEW_MIGRATIONS],
    'every migration already ran' => [false, ['2026_01_01_000000_add_x_to_posts_table'], ['2026_01_01_000000_add_x_to_posts_table'], false, InstallBackupAdvice::REASON_NO_NEW_MIGRATIONS],
    'new own tables only' => [false, ['2026_01_01_000000_create_probe_items_table', '2026_01_02_000000_create_probe_tags_table'], [], false, InstallBackupAdvice::REASON_OWN_TABLES_ONLY],
    'adds a column to an existing table' => [false, ['2026_01_01_000000_create_probe_items_table', '2026_01_02_000000_add_flag_to_posts_table'], [], true, InstallBackupAdvice::REASON_ALTERS_EXISTING],
    'create file for a table that exists' => [false, ['2026_01_01_000000_create_posts_table'], [], true, InstallBackupAdvice::REASON_ALTERS_EXISTING],
    'old alter already ran, new create only' => [false, ['2025_01_01_000000_add_x_to_posts_table', '2026_01_01_000000_create_probe_items_table'], ['2025_01_01_000000_add_x_to_posts_table'], false, InstallBackupAdvice::REASON_OWN_TABLES_ONLY],
]);

test('migration names come from manifest file paths and catalog lists, invalid entries dropped', function () {
    expect(InstallBackupAdvice::migrationsFromFiles([
        'Probe/database/migrations/2026_01_02_000000_b.php' => 'x',
        'Probe/database/migrations/2026_01_01_000000_a.php' => 'x',
        'Probe/database/migrations/sub/2026_01_03_000000_c.php' => 'x',
        'Probe/database/seeders/Seed.php' => 'x',
        'database/migrations/2026_01_04_000000_d.php' => 'x',
    ]))->toBe(['2026_01_01_000000_a', '2026_01_02_000000_b', '2026_01_04_000000_d'])
        ->and(InstallBackupAdvice::migrationsFromCatalog(null))->toBeNull()
        ->and(InstallBackupAdvice::migrationsFromCatalog(['a' => 'x']))->toBeNull()
        ->and(InstallBackupAdvice::migrationsFromCatalog(['2026_x', '../evil']))->toBeNull()
        ->and(InstallBackupAdvice::migrationsFromCatalog(['2026_x', '2026_y']))->toBe(['2026_x', '2026_y'])
        ->and(InstallBackupAdvice::migrationsFromCatalog(array_fill(0, InstallBackupAdvice::MAX_MIGRATIONS + 1, 'x')))->toBeNull();

    $many = [];
    for ($i = 0; $i <= InstallBackupAdvice::MAX_MIGRATIONS; $i++) {
        $many[sprintf('Probe/database/migrations/2026_01_01_%06d_m.php', $i)] = 'x';
    }
    expect(InstallBackupAdvice::migrationsFromFiles($many))->toBeNull();
});

test('the last own backup is the newer of a scheduled app backup and an ok nightly server backup', function () {
    Carbon::setTestNow('2026-11-14 10:00:00');
    $path = sys_get_temp_dir() . '/nightly-' . bin2hex(random_bytes(4)) . '.json';
    app()->instance(ServerNightlyStatus::class, new ServerNightlyStatus($path));

    expect(InstallBackupAdvice::lastBackup())->toBe(['at' => null, 'source' => null]);

    // Pre-install and failed backups do not count.
    Backup::query()->create(['reason' => Backup::REASON_PRE_INSTALL, 'status' => Backup::STATUS_SUCCEEDED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'finished_at' => '2026-11-14 09:00:00']);
    Backup::query()->create(['reason' => Backup::REASON_SCHEDULED, 'status' => Backup::STATUS_FAILED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'finished_at' => '2026-11-14 08:00:00']);
    Backup::query()->create(['reason' => Backup::REASON_SCHEDULED, 'status' => Backup::STATUS_PARTIAL, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'finished_at' => '2026-11-13 02:00:00']);
    expect(InstallBackupAdvice::lastBackup()['source'])->toBe('scheduled');

    file_put_contents($path, json_encode(['schema' => 1, 'label' => 'site', 'date' => '2026-11-14', 'finished_at' => '2026-11-14T03:00:00+07:00', 'objects' => []]));
    $last = InstallBackupAdvice::lastBackup();
    @unlink($path);

    expect($last['source'])->toBe('nightly')
        ->and(Carbon::parse($last['at'])->equalTo(Carbon::parse('2026-11-14T03:00:00+07:00')))->toBeTrue();
});

test('addendum: the advice says whether the database changes and which existing tables', function () {
    $tables = ['users', 'posts', 'post_meta'];
    $has = fn (string $t): bool => in_array($t, $tables, true);

    $advice = InstallBackupAdvice::advise(false, [
        '2026_01_01_000000_create_probe_items_table',
        '2026_01_02_000000_add_flag_to_posts_table',
        '2026_01_03_000000_alter_post_meta_table',
        '2026_01_04_000000_backfill_probe_slugs',
        '2026_01_05_000000_add_x_to_ghost_table',
    ], [], $has);
    expect($advice)->toMatchArray(['reason' => 'alters_existing', 'new_migrations' => 5, 'changes_database' => true, 'altered_tables' => ['post_meta', 'posts']]);

    expect(InstallBackupAdvice::advise(false, ['2026_01_01_000000_create_probe_items_table'], [], $has))
        ->toMatchArray(['changes_database' => true, 'altered_tables' => [], 'reason' => 'own_tables_only'])
        ->and(InstallBackupAdvice::advise(false, ['2026_01_01_000000_add_flag_to_posts_table'], ['2026_01_01_000000_add_flag_to_posts_table'], $has))
        ->toMatchArray(['changes_database' => false, 'new_migrations' => 0])
        ->and(InstallBackupAdvice::advise(false, null, [], $has))
        ->toMatchArray(['changes_database' => true, 'reason' => 'unknown'])
        ->and(InstallBackupAdvice::advise(true, [], [], $has))
        ->toMatchArray(['changes_database' => true, 'reason' => 'irreversible']);
});

test('on a first install the tables the package creates are new, so both drawers agree', function () {
    $tables = ['users'];
    $has = fn (string $t): bool => in_array($t, $tables, true);

    // A package that creates a table and changes it again later: nothing old is altered.
    $migrations = ['2026_01_01_000000_create_probe_items_table', '2026_01_02_000000_add_flag_to_probe_items_table'];
    expect(InstallBackupAdvice::advise(false, $migrations, [], $has, true))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_OWN_TABLES_ONLY, 'altered_tables' => [], 'recommended' => false, 'earlier_tables' => [], 'conflicting_tables' => []])
        // A later migration of the package that changes a table it does not create is still an alteration.
        ->and(InstallBackupAdvice::advise(false, ['2026_01_01_000000_create_probe_tags_table', '2026_01_02_000000_add_flag_to_users_table'], [], $has, true))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_ALTERS_EXISTING, 'altered_tables' => ['users']]);

    // Alter of a table created in the same package, table not there yet (fresh database), also an upgrade.
    expect(InstallBackupAdvice::advise(false, ['2026_01_01_000000_create_fresh_items_table', '2026_01_02_000000_add_flag_to_fresh_items_table'], [], $has))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_OWN_TABLES_ONLY, 'altered_tables' => []]);
});

test('a reinstall over tables kept by an earlier uninstall names them and defaults the backup on', function () {
    // Uninstall without dropping data keeps the tables and their migration rows (ModuleUninstaller keep-data path).
    $tables = ['users', 'probe_items', 'probe_tags'];
    $has = fn (string $t): bool => in_array($t, $tables, true);
    $migrations = ['2026_01_01_000000_create_probe_items_table', '2026_01_02_000000_create_probe_tags_table'];

    // Same version again: nothing new runs, yet the old data is there and the module will use it.
    expect(InstallBackupAdvice::advise(false, $migrations, $migrations, $has, true))
        ->toMatchArray([
            'recommended' => true,
            'reason' => InstallBackupAdvice::REASON_EARLIER_DATA,
            'new_migrations' => 0,
            'changes_database' => false,
            'altered_tables' => [],
            'earlier_tables' => ['probe_items', 'probe_tags'],
            'conflicting_tables' => [],
        ]);

    // A newer version that also changes a kept table: still earlier data first, the changed table listed too.
    $newer = [...$migrations, '2026_02_01_000000_add_flag_to_probe_items_table', '2026_02_02_000000_create_probe_notes_table'];
    expect(InstallBackupAdvice::advise(false, $newer, $migrations, $has, true))
        ->toMatchArray([
            'recommended' => true,
            'reason' => InstallBackupAdvice::REASON_EARLIER_DATA,
            'new_migrations' => 2,
            'changes_database' => true,
            'altered_tables' => ['probe_items'],
            'earlier_tables' => ['probe_items', 'probe_tags'],
        ]);

    // Irreversible and unknown keep their own reason (both default on as well).
    expect(InstallBackupAdvice::advise(true, $migrations, $migrations, $has, true)['reason'])->toBe(InstallBackupAdvice::REASON_IRREVERSIBLE)
        ->and(InstallBackupAdvice::advise(false, null, $migrations, $has, true)['reason'])->toBe(InstallBackupAdvice::REASON_UNKNOWN);

    // A kept table dropped by hand since then is not reported; an upgrade never reports earlier data (it is the module's own live data).
    expect(InstallBackupAdvice::advise(false, ['2026_01_03_000000_create_probe_gone_table'], ['2026_01_03_000000_create_probe_gone_table'], $has, true))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_NO_NEW_MIGRATIONS, 'recommended' => false, 'earlier_tables' => []])
        ->and(InstallBackupAdvice::advise(false, $migrations, $migrations, $has))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_NO_NEW_MIGRATIONS, 'earlier_tables' => []]);
});

test('a first install whose create-table migration has not run but the table exists reports a conflict', function () {
    // Migration rows gone but the table kept (or another source created a table of that name): `create` would fail.
    $tables = ['users', 'probe_items'];
    $has = fn (string $t): bool => in_array($t, $tables, true);
    $migrations = ['2026_01_01_000000_create_probe_items_table', '2026_01_02_000000_add_flag_to_probe_items_table', '2026_01_03_000000_create_probe_tags_table'];

    expect(InstallBackupAdvice::advise(false, $migrations, [], $has, true))
        ->toMatchArray([
            'recommended' => true,
            'reason' => InstallBackupAdvice::REASON_ALTERS_EXISTING,
            'altered_tables' => ['probe_items'],
            'earlier_tables' => [],
            'conflicting_tables' => ['probe_items'],
        ]);

    // Upgrades do not report conflicts (rule: a create file for an existing table reads as altering it).
    expect(InstallBackupAdvice::advise(false, $migrations, [], $has))
        ->toMatchArray(['reason' => InstallBackupAdvice::REASON_ALTERS_EXISTING, 'conflicting_tables' => []]);
});
