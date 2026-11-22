<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function makeBackupDestination(array $credentials = ['access_key' => 'AKIA-PLAIN-SECRET', 'secret' => '']): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'primary',
        'driver' => 'local',
        'config' => ['bucket' => 'b'],
        'credentials' => $credentials,
    ]);
}

it('stores credentials encrypted and never serializes them', function (): void {
    $destination = makeBackupDestination();

    $raw = (string) DB::table(CoreTableConstant::BACKUP_DESTINATIONS)->where('id', $destination->id)->value('credentials');
    expect($raw)->not->toBe('')->and($raw)->not->toContain('AKIA-PLAIN-SECRET');

    $fresh = BackupDestination::query()->findOrFail($destination->id);
    expect($fresh->credentials['access_key'])->toBe('AKIA-PLAIN-SECRET')
        ->and($fresh->toArray())->not->toHaveKey('credentials')
        ->and($fresh->toJson())->not->toContain('AKIA-PLAIN-SECRET')
        ->and($fresh->toJson())->not->toContain('credentials')
        ->and($fresh->uuid)->not->toBeEmpty()
        ->and($fresh->retention_days)->toBe(3)
        ->and($fresh->enabled)->toBeTrue()
        ->and($fresh->use_for_safety)->toBeFalse();
});

it('reports which credential fields are set', function (): void {
    expect(makeBackupDestination()->credentialsSet())->toBe(['access_key' => true, 'secret' => false]);

    $none = BackupDestination::query()->create(['name' => 'none', 'driver' => 'local', 'config' => []]);
    expect($none->credentialsSet())->toBe([]);
});

it('links backups, copies and destinations', function (): void {
    $destination = makeBackupDestination();
    $backup = Backup::query()->create([
        'reason' => Backup::REASON_MANUAL, 'status' => Backup::STATUS_RUNNING,
        'db_driver' => 'mysql', 'db_name' => 'x', 'format' => 'sql.gz', 'started_at' => now(),
    ]);
    $copy = BackupCopy::query()->create([
        'backup_id' => $backup->id, 'destination_id' => $destination->id, 'path' => 'a/b.sql.gz',
        'status' => BackupCopy::STATUS_UPLOADING,
    ]);

    expect($backup->copies)->toHaveCount(1)
        ->and($copy->backup->is($backup))->toBeTrue()
        ->and($copy->destination->is($destination))->toBeTrue()
        ->and($copy->kind)->toBe('backup');

    $probe = BackupCopy::query()->create([
        'destination_id' => $destination->id, 'kind' => 'probe', 'path' => 'p', 'status' => 'stored',
    ]);
    expect($probe->backup_id)->toBeNull();
});

it('resolves config defaults and the site slug', function (): void {
    expect(config('core.backup.min_free_bytes'))->toBe(1073741824)
        ->and(config('core.backup.process_timeout'))->toBe(780)
        ->and(config('core.backup.job_timeout'))->toBe(840)
        ->and(config('core.backup.max_retention_days'))->toBe(90)
        ->and(config('core.backup.binaries.pg_dump'))->toBe('pg_dump')
        ->and(config('core.backup.oauth.gdrive'))->toHaveKeys(['client_id', 'client_secret']);

    config(['app.url' => 'https://Example.COM:8080/x', 'core.backup.site_slug' => null]);
    expect(Backup::siteSlug())->toBe('example.com');

    config(['core.backup.site_slug' => 'Custom_Site!']);
    expect(Backup::siteSlug())->toBe('customsite');
});

it('migrates up, down and up again', function (): void {
    $migration = require base_path('cms/Core/database/migrations/2026_11_06_090000_create_core_backup_tables.php');
    $tables = [CoreTableConstant::BACKUP_DESTINATIONS, CoreTableConstant::BACKUPS, CoreTableConstant::BACKUP_COPIES];

    $migration->down();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    $migration->down();
    $migration->up();
    $migration->up();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
