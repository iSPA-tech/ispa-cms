<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function commandRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? commandRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->dir = sys_get_temp_dir() . '/runcmd-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $source = $this->dir . '/source.sqlite';
    $pdo = new PDO('sqlite:' . $source);
    $pdo->exec('CREATE TABLE a (id INTEGER)');
    unset($pdo);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'core.backup.min_free_bytes' => 0,
        'database.connections.bk_sqlite' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false],
    ]);
    app()->bind(BackupRunner::class, fn () => new BackupRunner(null, null, null, fn () => null, 'bk_sqlite'));
    $this->a = BackupDestination::query()->create(['name' => 'Alpha', 'driver' => 'local', 'config' => ['root' => $this->dir . '/a'], 'credentials' => ['password' => 'cmd-secret-value-77']]);
    $this->b = BackupDestination::query()->create(['name' => 'Beta', 'driver' => 'local', 'config' => ['root' => $this->dir . '/b']]);
});

afterEach(function (): void {
    DB::purge('bk_sqlite');
    commandRm($this->dir);
});

it('runs a backup to all enabled destinations', function (): void {
    $this->artisan('cms:backup-run')->assertExitCode(0);

    $backup = Backup::query()->sole();
    expect($backup->status)->toBe('succeeded')->and($backup->copies()->count())->toBe(2);
});

it('limits the run to the chosen destinations by name or uuid', function (): void {
    $this->artisan('cms:backup-run', ['--destination' => ['Alpha']])->assertExitCode(0);
    expect(Backup::query()->sole()->copies()->sole()->destination_id)->toBe($this->a->id);

    $this->artisan('cms:backup-run', ['--destination' => [$this->b->uuid]])->assertExitCode(0);
    expect(Backup::query()->count())->toBe(2);
});

it('never prints credential values', function (): void {
    $this->artisan('cms:backup-run')
        ->doesntExpectOutputToContain('cmd-secret-value-77')
        ->doesntExpectOutputToContain($this->dir)
        ->expectsOutputToContain('succeeded')
        ->expectsOutputToContain('Alpha')
        ->assertExitCode(0);
});

it('rejects an unknown reason or destination', function (): void {
    $this->artisan('cms:backup-run', ['--reason' => 'nightly'])->expectsOutputToContain('BACKUP_REASON_INVALID')->assertExitCode(1);
    $this->artisan('cms:backup-run', ['--destination' => ['Nope']])->expectsOutputToContain('BACKUP_DESTINATION_NOT_FOUND')->assertExitCode(1);
    expect(Backup::query()->count())->toBe(0);
});

it('reports a parallel run with its code and a non-zero exit', function (): void {
    $held = Cache::lock(BackupRunner::lockKey(), 30);
    expect($held->get())->toBeTrue();

    $this->artisan('cms:backup-run')->expectsOutputToContain('BACKUP_ALREADY_RUNNING')->assertExitCode(1);
    $held->release();
});
