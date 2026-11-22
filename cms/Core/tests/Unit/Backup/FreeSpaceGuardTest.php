<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\Dump\FreeSpaceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function guardFor(float $free, int $raw = 4000): FreeSpaceGuard
{
    return new class ($free, $raw) extends FreeSpaceGuard {
        public function __construct(private float $free, private int $raw)
        {
        }

        protected function freeBytes(string $path): float
        {
            return $this->free;
        }

        protected function rawSizeBytes(string $connection): int
        {
            return $this->raw;
        }
    };
}

function guardBackup(string $db, string $status, int $size): void
{
    Backup::query()->create([
        'reason' => Backup::REASON_MANUAL, 'status' => $status, 'db_driver' => 'mysql', 'db_name' => $db,
        'format' => 'sql.gz', 'size_bytes' => $size, 'started_at' => now(),
    ]);
}

beforeEach(function (): void {
    config([
        'core.backup.staging_path' => sys_get_temp_dir() . '/guard-never-created-' . bin2hex(random_bytes(4)),
        'core.backup.min_free_bytes' => 1000,
        'database.connections.bk_guard' => ['driver' => 'mysql', 'database' => 'guarded'],
    ]);
});

it('estimates raw size divided by four without a previous backup', function (): void {
    expect(guardFor(0)->estimate('bk_guard'))->toBe(1000);
});

it('estimates 1.3 times the last succeeded backup of the same database', function (): void {
    guardBackup('guarded', Backup::STATUS_SUCCEEDED, 5000);
    guardBackup('guarded', Backup::STATUS_SUCCEEDED, 10000);
    guardBackup('guarded', Backup::STATUS_FAILED, 99999);
    guardBackup('other', Backup::STATUS_SUCCEEDED, 77777);

    expect(guardFor(0)->estimate('bk_guard'))->toBe(13000);
});

it('refuses with BACKUP_NO_SPACE when free space is below estimate plus minimum', function (): void {
    guardBackup('guarded', Backup::STATUS_SUCCEEDED, 10000);

    expect(fn () => guardFor(13999)->ensure('bk_guard'))->toThrow(RuntimeException::class, 'BACKUP_NO_SPACE');
});

it('allows when free space covers estimate plus minimum', function (): void {
    guardBackup('guarded', Backup::STATUS_SUCCEEDED, 10000);
    guardFor(14000)->ensure('bk_guard');

    expect(true)->toBeTrue();
});

it('uses raw size divided by four in the check when no backup exists', function (): void {
    expect(fn () => guardFor(1999, 4000)->ensure('bk_guard'))->toThrow(RuntimeException::class, 'BACKUP_NO_SPACE');
    guardFor(2000, 4000)->ensure('bk_guard');
    expect(true)->toBeTrue();
});

it('measures the real disk through the nearest existing parent', function (): void {
    config(['core.backup.min_free_bytes' => PHP_INT_MAX]);

    expect(fn () => (new class extends FreeSpaceGuard {
        protected function rawSizeBytes(string $connection): int
        {
            return 0;
        }
    })->ensure('bk_guard'))->toThrow(RuntimeException::class, 'BACKUP_NO_SPACE');
});
