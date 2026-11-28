<?php

declare(strict_types=1);

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupRunner;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\Dump\DatabaseDumper;
use Cms\Core\Services\Backup\Dump\DumperFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function runnerRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? runnerRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

/** @return array<int, string> every file under a dir, relative */
function runnerFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[] = substr($f->getPathname(), strlen($dir) + 1);
    }
    sort($out);

    return $out;
}

function runnerDestination(string $name, string $root): BackupDestination
{
    return BackupDestination::query()->create(['name' => $name, 'driver' => 'local', 'config' => ['root' => $root]]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->dir = sys_get_temp_dir() . '/runner-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $source = $this->dir . '/source.sqlite';
    $pdo = new PDO('sqlite:' . $source);
    $pdo->exec('CREATE TABLE a (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE b (id INTEGER)');
    unset($pdo);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'core.backup.min_free_bytes' => 0,
        'database.connections.bk_sqlite' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false],
    ]);
    $this->alerts = [];
    $this->runner = fn (?Closure $alertSink = null, ?DumperFactory $factory = null) => new BackupRunner(
        $factory,
        null,
        null,
        function (array $data): void {
            $this->alerts[] = $data;
        },
        'bk_sqlite',
    );
});

afterEach(function (): void {
    DB::purge('bk_sqlite');
    runnerRm($this->dir);
});

it('stores the dump on two local destinations with sidecars, copy rows and an empty staging dir', function (): void {
    $a = runnerDestination('A', $this->dir . '/dest-a');
    $b = runnerDestination('B', $this->dir . '/dest-b');
    $task = (new BackgroundTaskRecorder())->start('core.backup', 'Core', null);

    $backup = ($this->runner)()->run(Backup::REASON_MANUAL, null, [], $task);

    expect($backup->status)->toBe(Backup::STATUS_SUCCEEDED)
        ->and($backup->tables_expected)->toBe(2)->and($backup->tables_dumped)->toBe(2)
        ->and($backup->error_code)->toBeNull()
        ->and($backup->format)->toBe('sqlite.gz');
    $copies = BackupCopy::query()->where('backup_id', $backup->id)->orderBy('destination_id')->get();
    expect($copies)->toHaveCount(2);

    foreach ([[$a, 'dest-a'], [$b, 'dest-b']] as $i => [$dest, $folder]) {
        $copy = $copies[$i];
        expect($copy->status)->toBe(BackupCopy::STATUS_STORED)->and($copy->destination_id)->toBe($dest->id);
        $file = $this->dir . '/' . $folder . '/' . $copy->path;
        expect(is_file($file))->toBeTrue()
            ->and(hash_file('sha256', $file))->toBe($backup->sha256)
            ->and(filesize($file))->toBe($backup->size_bytes)
            ->and(file_get_contents($file . '.sha256'))->toBe($backup->sha256 . '  ' . basename($copy->path) . "\n")
            ->and(is_file($file . '.FAILED'))->toBeFalse();
        expect($dest->fresh()->last_success_at)->not->toBeNull();
    }
    expect(runnerFiles($this->dir . '/staging'))->toBe([])
        ->and($this->alerts)->toBe([]);
});

it('records task progress 10, 60, 60-95 and 100', function (): void {
    runnerDestination('A', $this->dir . '/dest-a');
    runnerDestination('B', $this->dir . '/dest-b');
    $seen = [];
    $recorder = new class($seen) extends BackgroundTaskRecorder {
        public function __construct(public array &$seen)
        {
        }

        public function progress(BackgroundTask $task, int $percent, ?string $message = null, array $params = []): BackgroundTask
        {
            $this->seen[] = $percent;

            return parent::progress($task, $percent, $message, $params);
        }
    };
    $task = $recorder->start('core.backup', 'Core', null);

    (new BackupRunner(null, null, $recorder, fn () => null, 'bk_sqlite'))->run(Backup::REASON_MANUAL, null, [], $task);

    expect($seen)->toBe([10, 60, 60, 77]);
    $task->refresh();
    expect($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)->and($task->progress)->toBe(100)
        ->and($task->result['destinations_stored'])->toBe(2);
});

it('fails with the dump error code, stores nothing and leaves no copies', function (): void {
    $dest = runnerDestination('A', $this->dir . '/dest-a');
    $factory = new class extends DumperFactory {
        public function make(string $connection): DatabaseDumper
        {
            return new class implements DatabaseDumper {
                public function dump(string $connection, string $targetFile): Cms\Core\Services\Backup\Dump\DumpResult
                {
                    file_put_contents($targetFile, 'partial');
                    throw new RuntimeException('BACKUP_TABLE_COUNT_MISMATCH: 1 of 2 tables dumped');
                }
            };
        }
    };
    $task = (new BackgroundTaskRecorder())->start('core.backup', 'Core', null);

    $backup = ($this->runner)(null, $factory)->run(Backup::REASON_MANUAL, null, [], $task);

    expect($backup->status)->toBe(Backup::STATUS_FAILED)->and($backup->error_code)->toBe('BACKUP_TABLE_COUNT_MISMATCH')
        ->and(BackupCopy::query()->count())->toBe(0)
        ->and(runnerFiles($this->dir . '/dest-a'))->toBe([]);
    expect($task->refresh()->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and($task->message_params)->toBe(['code' => 'BACKUP_TABLE_COUNT_MISMATCH']);
    expect($dest->fresh()->last_failure_at)->toBeNull();
});

it('ends partial when one destination fails, keeps the other and writes a FAILED marker', function (): void {
    $good = runnerDestination('Good', $this->dir . '/dest-good');
    $bad = runnerDestination('Bad', $this->dir . '/dest-bad');
    $store = [];
    $fake = Mockery::mock(Filesystem::class);
    $fake->shouldReceive('exists')->andReturn(false);
    $fake->shouldReceive('writeStream')->andThrow(new RuntimeException('connection reset secret-token-123'));
    $fake->shouldReceive('put')->andReturnUsing(function (string $p, $c) use (&$store): bool {
        $store[$p] = $c;

        return true;
    });
    $real = Storage::getFacadeRoot();
    Storage::shouldReceive('build')->andReturnUsing(function (array $cfg) use ($real, $fake) {
        return str_contains((string) ($cfg['root'] ?? ''), 'dest-bad') ? $fake : $real->build($cfg);
    });

    $backup = ($this->runner)()->run(Backup::REASON_SCHEDULED, null);

    expect($backup->status)->toBe(Backup::STATUS_PARTIAL);
    $badCopy = BackupCopy::query()->where('destination_id', $bad->id)->sole();
    $goodCopy = BackupCopy::query()->where('destination_id', $good->id)->sole();
    expect($badCopy->status)->toBe(BackupCopy::STATUS_FAILED)->and($badCopy->error_code)->toBe('BACKUP_UPLOAD_FAILED')
        ->and($goodCopy->status)->toBe(BackupCopy::STATUS_STORED)
        ->and(is_file($this->dir . '/dest-good/' . $goodCopy->path))->toBeTrue()
        ->and(array_keys($store))->toBe([$badCopy->path . '.FAILED'])
        ->and($bad->fresh()->last_error_code)->toBe('BACKUP_UPLOAD_FAILED')
        ->and($bad->fresh()->last_failure_at)->not->toBeNull()
        ->and(runnerFiles($this->dir . '/staging'))->toBe([]);
    expect($this->alerts)->toHaveCount(1);
    $alert = json_encode($this->alerts);
    expect($alert)->not->toContain($this->dir)->not->toContain('secret-token-123')->not->toContain('storage/')
        ->and($alert)->toContain('BACKUP_PARTIAL');
});

it('refuses a parallel run with BACKUP_ALREADY_RUNNING and releases the lock afterwards', function (): void {
    runnerDestination('A', $this->dir . '/dest-a');
    $held = Cache::lock(BackupRunner::lockKey(), 30);
    expect($held->get())->toBeTrue();

    expect(fn () => ($this->runner)()->run(Backup::REASON_MANUAL, null))->toThrow(RuntimeException::class, 'BACKUP_ALREADY_RUNNING');
    expect(Backup::query()->count())->toBe(0);
    $held->release();

    expect(($this->runner)()->run(Backup::REASON_MANUAL, null)->status)->toBe(Backup::STATUS_SUCCEEDED);
    expect(Cache::lock(BackupRunner::lockKey(), 5)->get())->toBeTrue();
});

it('fails a manual backup with BACKUP_NO_DESTINATION when no destination is enabled', function (): void {
    $backup = ($this->runner)()->run(Backup::REASON_MANUAL, null);
    expect($backup->status)->toBe(Backup::STATUS_FAILED)->and($backup->error_code)->toBe('BACKUP_NO_DESTINATION')
        ->and($this->alerts)->toBe([]);
});

it('stores the built-in root behind the storage symlink, not the release path, and repoints an old release root', function (): void {
    // storage_path() on a release deploy is releases/<n>/storage -> the shared storage folder.
    mkdir($this->dir . '/shared/backups', 0770, true);
    symlink($this->dir . '/shared', $this->dir . '/release-storage');
    config(['core.backup.local_root' => $this->dir . '/release-storage/backups/local']);
    $shared = realpath($this->dir . '/shared') . '/backups/local';

    try {
        $backup = ($this->runner)()->run(Backup::REASON_PRE_INSTALL, null);
        $builtin = BackupDestination::query()->where('name', BackupRunner::BUILTIN_LOCAL_NAME)->sole();
        expect($backup->status)->toBe(Backup::STATUS_SUCCEEDED)
            ->and($builtin->config['root'])->toBe($shared)
            ->and(runnerFiles($shared))->not->toBe([]);

        $builtin->forceFill(['config' => ['root' => $this->dir . '/releases/168/storage/backups/local']])->save();
        $other = runnerDestination('Other', $this->dir . '/elsewhere/storage/backups/local');
        (require base_path('cms/Core/database/migrations/2026_11_28_090000_repoint_builtin_local_backup_root.php'))->up();

        expect($builtin->refresh()->config['root'])->toBe($shared)
            ->and($other->refresh()->config['root'])->toBe($this->dir . '/elsewhere/storage/backups/local');

        // A database restored from an older release brings the release root back: the next safety backup repoints it.
        $builtin->forceFill(['config' => ['root' => $this->dir . '/releases/170/storage/backups/local']])->save();
        expect(($this->runner)()->run(Backup::REASON_PRE_INSTALL, null)->status)->toBe(Backup::STATUS_SUCCEEDED)
            ->and($builtin->refresh()->config['root'])->toBe($shared)
            ->and(is_dir($this->dir . '/releases'))->toBeFalse();
    } finally {
        @unlink($this->dir . '/release-storage');
    }
});

it('sends a safety backup to the built-in local destination when none is marked for safety', function (): void {
    config(['core.backup.local_root' => $this->dir . '/builtin']);
    runnerDestination('Other', $this->dir . '/dest-other');

    $backup = ($this->runner)()->run(Backup::REASON_PRE_INSTALL, null);

    expect($backup->status)->toBe(Backup::STATUS_SUCCEEDED);
    $copy = BackupCopy::query()->sole();
    expect($copy->destination->name)->toBe(BackupRunner::BUILTIN_LOCAL_NAME)->and($copy->destination->retention_days)->toBe(2)
        ->and(runnerFiles($this->dir . '/dest-other'))->toBe([]);
});
