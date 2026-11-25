<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Backup\BackupRunner;
use Cms\Core\Services\Backup\Dump\DatabaseDumper;
use Cms\Core\Services\Backup\Dump\DumperFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function gateRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? gateRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->dir = sys_get_temp_dir() . '/gate-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $source = $this->dir . '/source.sqlite';
    $pdo = new PDO('sqlite:' . $source);
    $pdo->exec('CREATE TABLE a (id INTEGER PRIMARY KEY)');
    unset($pdo);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'core.backup.local_root' => $this->dir . '/builtin',
        'core.backup.min_free_bytes' => 0,
        'database.connections.bk_sqlite' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false],
    ]);
    $this->gate = fn (?DumperFactory $factory = null) => new BackupGate(new BackupRunner($factory, null, null, fn () => null, 'bk_sqlite'));
});

afterEach(function (): void {
    DB::purge('bk_sqlite');
    gateRm($this->dir);
});

it('stores the safety backup only on destinations marked for safety', function (): void {
    $safe = BackupDestination::query()->create(['name' => 'Safe', 'driver' => 'local', 'config' => ['root' => $this->dir . '/safe'], 'use_for_safety' => true]);
    $other = BackupDestination::query()->create(['name' => 'Other', 'driver' => 'local', 'config' => ['root' => $this->dir . '/other'], 'use_for_safety' => false]);

    $backup = ($this->gate)()->ensure(Backup::REASON_PRE_INSTALL, null);

    expect($backup->status)->toBe(Backup::STATUS_SUCCEEDED);
    $copies = BackupCopy::query()->where('backup_id', $backup->id)->get();
    expect($copies)->toHaveCount(1)->and($copies[0]->destination_id)->toBe($safe->id)
        ->and($other->fresh()->last_success_at)->toBeNull();
});

it('accepts the pre-purge reason and stores it on the backup', function (): void {
    $backup = ($this->gate)()->ensure(Backup::REASON_PRE_PURGE, null);

    expect($backup->reason)->toBe('pre_purge')
        ->and(BackupCopy::query()->where('backup_id', $backup->id)->where('status', BackupCopy::STATUS_STORED)->exists())->toBeTrue();
});

it('falls back to the built-in local destination when none is marked for safety', function (): void {
    $backup = ($this->gate)()->ensure(Backup::REASON_PRE_IMPORT, null);

    $builtin = BackupDestination::query()->where('name', BackupRunner::BUILTIN_LOCAL_NAME)->sole();
    $copy = BackupCopy::query()->where('backup_id', $backup->id)->sole();
    expect($copy->destination_id)->toBe($builtin->id)->and($copy->status)->toBe(BackupCopy::STATUS_STORED)
        ->and(is_file($this->dir . '/builtin/' . $copy->path))->toBeTrue();
});

it('rejects a non-safety reason', function (): void {
    ($this->gate)()->ensure(Backup::REASON_MANUAL, null);
})->throws(InvalidArgumentException::class);

it('throws BACKUP_REQUIRED_FAILED when the dump fails', function (): void {
    $factory = new class extends DumperFactory {
        public function make(string $connection): DatabaseDumper
        {
            return new class implements DatabaseDumper {
                public function dump(string $connection, string $targetFile): Cms\Core\Services\Backup\Dump\DumpResult
                {
                    throw new RuntimeException('BACKUP_TABLE_COUNT_MISMATCH: x');
                }
            };
        }
    };

    try {
        ($this->gate)($factory)->ensure(Backup::REASON_PRE_INSTALL, null);
        $this->fail('expected exception');
    } catch (BackupRequiredException $e) {
        expect($e->getMessage())->toBe('BACKUP_REQUIRED_FAILED')->and($e->reason())->toBe('BACKUP_TABLE_COUNT_MISMATCH');
    }
});

it('throws when every copy failed', function (): void {
    // A file where the destination root should be a directory makes every upload fail.
    file_put_contents($this->dir . '/blocked', 'x');
    BackupDestination::query()->create(['name' => 'Blocked', 'driver' => 'local', 'config' => ['root' => $this->dir . '/blocked'], 'use_for_safety' => true]);

    try {
        ($this->gate)()->ensure(Backup::REASON_PRE_INSTALL, null);
        $this->fail('expected exception');
    } catch (BackupRequiredException $e) {
        expect($e->getMessage())->toBe(BackupRequiredException::CODE)->and($e->reason())->toBe('BACKUP_ALL_DESTINATIONS_FAILED');
    }
});

it('accepts a prepared safety backup only when it is stored, of the same reason and recent', function (): void {
    $gate = ($this->gate)();
    $backup = $gate->ensure(Backup::REASON_PRE_CORE_UPGRADE, null);

    expect($gate->prepared($backup->uuid, Backup::REASON_PRE_CORE_UPGRADE)?->uuid)->toBe($backup->uuid)
        ->and($gate->prepared($backup->uuid, Backup::REASON_PRE_INSTALL))->toBeNull()
        ->and($gate->prepared('00000000-0000-0000-0000-000000000000', Backup::REASON_PRE_CORE_UPGRADE))->toBeNull();

    Backup::query()->whereKey($backup->getKey())->update(['created_at' => now()->subDays(2)]);
    expect($gate->prepared($backup->uuid, Backup::REASON_PRE_CORE_UPGRADE))->toBeNull();

    Backup::query()->whereKey($backup->getKey())->update(['created_at' => now()]);
    BackupCopy::query()->where('backup_id', $backup->getKey())->update(['status' => BackupCopy::STATUS_PRUNED]);
    expect($gate->prepared($backup->uuid, Backup::REASON_PRE_CORE_UPGRADE))->toBeNull();

    $failed = Backup::query()->create(['reason' => Backup::REASON_PRE_CORE_UPGRADE, 'status' => Backup::STATUS_FAILED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz']);
    expect($gate->prepared($failed->uuid, Backup::REASON_PRE_CORE_UPGRADE))->toBeNull();
});
