<?php

declare(strict_types=1);

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Cms\Core\Console\Commands\BackupPruneCommand;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\RetentionPruner;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use League\Flysystem\UnableToDeleteFile;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function prunerRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? prunerRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

/** @return array<int, string> every file under a dir, relative */
function prunerFiles(string $dir): array
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

function prunerBackup(string $status = Backup::STATUS_SUCCEEDED): Backup
{
    return Backup::query()->create(['reason' => Backup::REASON_SCHEDULED, 'status' => $status, 'db_driver' => 'sqlite', 'db_name' => 'db', 'format' => 'sqlite.gz']);
}

/** Record a copy and (when a root is given) write the object plus its sidecars on disk. */
function prunerCopy(BackupDestination $dest, string $path, int $ageDays, array $attrs = [], ?string $root = null, bool $sidecars = true): BackupCopy
{
    if ($root !== null) {
        @mkdir(dirname($root . '/' . $path), 0755, true);
        file_put_contents($root . '/' . $path, 'data');
        if ($sidecars) {
            file_put_contents($root . '/' . $path . '.sha256', 'sum');
            file_put_contents($root . '/' . $path . '.FAILED', 'failed');
        }
    }

    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);
    $copy = BackupCopy::query()->create($attrs + [
        'backup_id' => array_key_exists('backup_id', $attrs) ? $attrs['backup_id'] : prunerBackup()->id,
        'destination_id' => $dest->id,
        'path' => $path,
        'status' => BackupCopy::STATUS_STORED,
        'uploaded_at' => now()->subDays($ageDays),
    ]);
    if ($createdAt !== null) {
        $copy->forceFill(['created_at' => $createdAt])->save();
    }

    return $copy;
}

/** A fake disk whose delete is refused by a 409 lock while $state['locked'] is true. */
function prunerLockedDisk(array &$state): Filesystem
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnUsing(function (string $p) use (&$state): bool {
        return isset($state['files'][$p]);
    });
    $disk->shouldReceive('delete')->andReturnUsing(function (string $p) use (&$state): bool {
        $state['deletes'][] = $p;
        if ($state['locked']) {
            $s3e = new S3Exception('ObjectLockedByBucketPolicy', new Command('DeleteObject'), ['response' => new Response(409), 'code' => 'ObjectLockedByBucketPolicy']);
            throw UnableToDeleteFile::atLocation($p, 'locked', $s3e);
        }
        unset($state['files'][$p]);

        return true;
    });

    return $disk;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->dir = sys_get_temp_dir() . '/pruner-' . bin2hex(random_bytes(6));
    mkdir($this->dir . '/dest', 0755, true);
    $this->dest = BackupDestination::query()->create(['name' => 'Local A', 'driver' => 'local', 'config' => ['root' => $this->dir . '/dest'], 'retention_days' => 3, 'lock_days' => 0]);
    $this->alerts = [];
    $this->pruner = fn (?Closure $disks = null) => new RetentionPruner(function (array $data): void {
        $this->alerts[] = $data;
    }, $disks);
});

afterEach(function (): void {
    Carbon::setTestNow();
    prunerRm($this->dir);
});

it('removes expired copies with their sidecars and marks them pruned', function (): void {
    $root = $this->dir . '/dest';
    prunerCopy($this->dest, 'site/newest.sql.gz', 0, [], $root);
    $old = prunerCopy($this->dest, 'site/old.sql.gz', 10, [], $root);
    $failed = prunerCopy($this->dest, 'site/broken.sql.gz', 10, ['status' => BackupCopy::STATUS_FAILED, 'uploaded_at' => null, 'created_at' => now()->subDays(10)], $root);

    $result = ($this->pruner)()->prune();

    expect($result)->toHaveCount(1)->and($result[0]['pruned'])->toBe(2)->and($result[0]['refused'])->toBe(0)
        ->and($result[0]['kept'])->toBe(1);
    expect(prunerFiles($root))->toBe(['site/newest.sql.gz', 'site/newest.sql.gz.FAILED', 'site/newest.sql.gz.sha256']);
    foreach ([$old, $failed] as $copy) {
        $copy->refresh();
        expect($copy->status)->toBe(BackupCopy::STATUS_PRUNED)->and($copy->pruned_at)->not->toBeNull()
            ->and($copy->prune_attempted_at)->not->toBeNull()->and($copy->error_code)->toBeNull();
    }
});

it('never deletes a file that has no copy row', function (): void {
    $root = $this->dir . '/dest';
    prunerCopy($this->dest, 'site/old.sql.gz', 10, [], $root, false);
    prunerCopy($this->dest, 'site/keep.sql.gz', 0, [], $root, false);
    file_put_contents($root . '/site/stranger.sql.gz', 'x');
    touch($root . '/site/stranger.sql.gz', time() - 86400 * 30);

    ($this->pruner)()->prune();

    expect(prunerFiles($root))->toBe(['site/keep.sql.gz', 'site/stranger.sql.gz']);
});

it('keeps the newest stored copy of a succeeded backup even when expired', function (): void {
    $root = $this->dir . '/dest';
    $older = prunerCopy($this->dest, 'site/older.sql.gz', 20, [], $root, false);
    $last = prunerCopy($this->dest, 'site/last-good.sql.gz', 10, [], $root, false);
    $partial = prunerCopy($this->dest, 'site/partial.sql.gz', 5, ['backup_id' => prunerBackup(Backup::STATUS_PARTIAL)->id], $root, false);

    $result = ($this->pruner)()->prune();

    expect($last->refresh()->status)->toBe(BackupCopy::STATUS_STORED)
        ->and($older->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED)
        ->and($partial->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED)
        ->and(prunerFiles($root))->toBe(['site/last-good.sql.gz'])
        ->and($result[0]['kept'])->toBe(1);
});

it('deletes nothing and writes nothing in dry-run mode', function (): void {
    $root = $this->dir . '/dest';
    prunerCopy($this->dest, 'site/new.sql.gz', 0, [], $root);
    $old = prunerCopy($this->dest, 'site/old.sql.gz', 10, [], $root);
    $before = BackupCopy::query()->orderBy('id')->get()->toArray();
    $filesBefore = prunerFiles($root);

    $result = ($this->pruner)()->prune(null, true);

    expect($result[0]['would_delete'])->toBe(['site/old.sql.gz'])->and($result[0]['pruned'])->toBe(0)
        ->and(prunerFiles($root))->toBe($filesBefore)
        ->and(BackupCopy::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and($old->refresh()->prune_attempted_at)->toBeNull();
});

it('marks a lock refusal prune_refused, retries next run and warns once a day past lock_days + 2', function (): void {
    // Mid-morning, so the hour added below never crosses midnight into a new warning day.
    Carbon::setTestNow(now()->setTime(10, 0));
    $this->dest->forceFill(['lock_days' => 3])->save();
    $state = ['locked' => true, 'files' => ['site/old.sql.gz' => true, 'site/old.sql.gz.sha256' => true], 'deletes' => []];
    $disk = prunerLockedDisk($state);
    prunerCopy($this->dest, 'site/new.sql.gz', 0);
    $copy = prunerCopy($this->dest, 'site/old.sql.gz', 6);
    $pruner = ($this->pruner)(fn () => $disk);

    $result = $pruner->prune();
    $copy->refresh();
    expect($result[0]['refused'])->toBe(1)->and($copy->status)->toBe(BackupCopy::STATUS_PRUNE_REFUSED)
        ->and($copy->error_code)->toBe('BACKUP_PRUNE_LOCKED')->and($copy->pruned_at)->toBeNull()
        ->and($state['deletes'])->toBe(['site/old.sql.gz'])
        ->and($this->alerts)->toHaveCount(1);
    $alert = json_encode($this->alerts);
    expect($alert)->toContain('BACKUP_PRUNE_LOCKED')->not->toContain('site/old.sql.gz')->not->toContain($this->dir);
    $firstAttempt = $copy->prune_attempted_at;

    Carbon::setTestNow(now()->addHour());
    $pruner->prune();
    expect($copy->refresh()->status)->toBe(BackupCopy::STATUS_PRUNE_REFUSED)
        ->and($copy->prune_attempted_at->greaterThan($firstAttempt))->toBeTrue()
        ->and($this->alerts)->toHaveCount(1);

    $state['locked'] = false;
    Carbon::setTestNow(now()->addDay());
    $pruner->prune();
    $copy->refresh();
    expect($copy->status)->toBe(BackupCopy::STATUS_PRUNED)->and($copy->error_code)->toBeNull()
        ->and($state['files'])->toBe([])
        ->and($this->alerts)->toHaveCount(1);
});

it('does not warn while a refused copy is younger than lock_days + 2', function (): void {
    $this->dest->forceFill(['lock_days' => 5])->save();
    $state = ['locked' => true, 'files' => ['site/old.sql.gz' => true], 'deletes' => []];
    $disk = prunerLockedDisk($state);
    prunerCopy($this->dest, 'site/new.sql.gz', 0);
    prunerCopy($this->dest, 'site/old.sql.gz', 6);

    $result = ($this->pruner)(fn () => $disk)->prune();

    expect($result[0]['refused'])->toBe(1)->and($this->alerts)->toBe([]);
});

it('refuses when delete reports success but the object still exists', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturn(true);
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $p): bool => $p === 'site/old.sql.gz');
    prunerCopy($this->dest, 'site/new.sql.gz', 0);
    $copy = prunerCopy($this->dest, 'site/old.sql.gz', 10);

    $result = ($this->pruner)(fn () => $disk)->prune();

    $copy->refresh();
    expect($result[0]['refused'])->toBe(1)->and($copy->status)->toBe(BackupCopy::STATUS_PRUNE_REFUSED)
        ->and($copy->error_code)->toBe('BACKUP_PRUNE_NOT_DELETED')->and($copy->prune_attempted_at)->not->toBeNull();
});

it('records BACKUP_PRUNE_FAILED on other delete errors and goes on with the next copy', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturnUsing(function (string $p): bool {
        if (str_starts_with($p, 'site/a.sql.gz')) {
            throw new RuntimeException('boom secret-token-9');
        }

        return true;
    });
    $disk->shouldReceive('exists')->andReturn(false);
    prunerCopy($this->dest, 'site/new.sql.gz', 0);
    $a = prunerCopy($this->dest, 'site/a.sql.gz', 10);
    $b = prunerCopy($this->dest, 'site/b.sql.gz', 10);

    $result = ($this->pruner)(fn () => $disk)->prune();

    expect($result[0]['refused'])->toBe(1)->and($result[0]['pruned'])->toBe(1)
        ->and($a->refresh()->status)->toBe(BackupCopy::STATUS_PRUNE_REFUSED)->and($a->error_code)->toBe('BACKUP_PRUNE_FAILED')
        ->and($b->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED);
});

it('prunes probe copies the same way', function (): void {
    $root = $this->dir . '/dest';
    $probe = prunerCopy($this->dest, '_probe/p1.txt', 10, ['backup_id' => null, 'kind' => BackupCopy::KIND_PROBE], $root, false);
    $fresh = prunerCopy($this->dest, '_probe/p2.txt', 1, ['backup_id' => null, 'kind' => BackupCopy::KIND_PROBE], $root, false);

    ($this->pruner)()->prune();

    expect($probe->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED)
        ->and($fresh->refresh()->status)->toBe(BackupCopy::STATUS_STORED)
        ->and(prunerFiles($root))->toBe(['_probe/p2.txt']);
});

it('leaves copies that are not expired untouched', function (): void {
    $root = $this->dir . '/dest';
    $a = prunerCopy($this->dest, 'site/a.sql.gz', 2, [], $root);
    $b = prunerCopy($this->dest, 'site/b.sql.gz', 1, ['status' => BackupCopy::STATUS_FAILED], $root);
    $uploading = prunerCopy($this->dest, 'site/c.sql.gz', 30, ['status' => BackupCopy::STATUS_UPLOADING], $root, false);
    $filesBefore = prunerFiles($root);

    $result = ($this->pruner)()->prune();

    expect(prunerFiles($root))->toBe($filesBefore)->and($result[0]['pruned'])->toBe(0)
        ->and($a->refresh()->prune_attempted_at)->toBeNull()
        ->and($b->refresh()->status)->toBe(BackupCopy::STATUS_FAILED)
        ->and($uploading->refresh()->status)->toBe(BackupCopy::STATUS_UPLOADING);
});

it('limits the run to one destination', function (): void {
    $other = BackupDestination::query()->create(['name' => 'Other', 'driver' => 'local', 'config' => ['root' => $this->dir . '/other'], 'retention_days' => 1, 'enabled' => false]);
    prunerCopy($other, 'site/new.sql.gz', 0, [], $this->dir . '/other', false);
    $old = prunerCopy($other, 'site/old.sql.gz', 9, [], $this->dir . '/other', false);
    $mine = prunerCopy($this->dest, 'site/old.sql.gz', 9, ['backup_id' => prunerBackup(Backup::STATUS_FAILED)->id], $this->dir . '/dest', false);

    $result = ($this->pruner)()->prune($this->dest);

    expect($result)->toHaveCount(1)->and($mine->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED)
        ->and($old->refresh()->status)->toBe(BackupCopy::STATUS_STORED);

    ($this->pruner)()->prune();
    expect($old->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED);
});

it('prints a dry-run table from the command, deletes nothing and rejects an unknown destination', function (): void {
    $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($this->app->make(BackupPruneCommand::class));
    $this->app->bind(RetentionPruner::class, fn () => new RetentionPruner(fn () => null));
    $this->dest->forceFill(['credentials' => ['password' => 'prune-secret-value-42']])->save();
    $root = $this->dir . '/dest';
    prunerCopy($this->dest, 'site/new.sql.gz', 0, [], $root);
    $old = prunerCopy($this->dest, 'site/old.sql.gz', 10, [], $root);
    $filesBefore = prunerFiles($root);

    $this->artisan('cms:backup-prune', ['--dry-run' => true, '--destination' => 'Local A'])
        ->expectsOutputToContain('Local A')
        ->doesntExpectOutputToContain('prune-secret-value-42')
        ->assertExitCode(0);
    \Illuminate\Support\Facades\Artisan::call('cms:backup-prune', ['--dry-run' => true]);
    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('site/old.sql.gz')->not->toContain('prune-secret-value-42');
    expect(prunerFiles($root))->toBe($filesBefore)->and($old->refresh()->status)->toBe(BackupCopy::STATUS_STORED);

    $this->artisan('cms:backup-prune', ['--destination' => 'Nope'])->expectsOutputToContain('BACKUP_DESTINATION_NOT_FOUND')->assertExitCode(1);
    expect($old->refresh()->status)->toBe(BackupCopy::STATUS_STORED);

    $this->artisan('cms:backup-prune', ['--destination' => $this->dest->uuid])->assertExitCode(0);
    expect($old->refresh()->status)->toBe(BackupCopy::STATUS_PRUNED);
});
