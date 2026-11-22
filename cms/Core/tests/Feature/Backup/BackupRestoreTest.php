<?php

declare(strict_types=1);

use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function restoreRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) && ! is_link($f) ? restoreRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

/** @return array<int, string> every file under a dir, relative */
function restoreFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[] = substr($f->getPathname(), strlen($dir) + 1);
    }

    return $out;
}

/** @return array<int, string> direct entries of a dir (files and subdirectories) */
function restoreEntries(string $dir): array
{
    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

function restoreTableCount(string $file): int
{
    $pdo = new PDO('sqlite:' . $file);

    return (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->dir = sys_get_temp_dir() . '/restore-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $source = $this->dir . '/source.sqlite';
    $pdo = new PDO('sqlite:' . $source);
    $pdo->exec('CREATE TABLE a (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE b (id INTEGER)');
    $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
    $pdo->exec("INSERT INTO a (name) VALUES ('x'), ('y')");
    $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('m1', 1), ('m2', 1), ('m3', 2)");
    unset($pdo);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'core.backup.min_free_bytes' => 0,
        'database.connections.bk_sqlite' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false],
    ]);
    $this->destination = BackupDestination::query()->create(['name' => 'Local A', 'driver' => 'local', 'config' => ['root' => $this->dir . '/dest']]);
    $this->backup = (new BackupRunner(null, null, null, fn () => null, 'bk_sqlite'))->run(Backup::REASON_MANUAL, null);
    expect($this->backup->status)->toBe(Backup::STATUS_SUCCEEDED);
    $this->backup->forceFill(['verified_at' => null])->save();
    $this->copy = BackupCopy::query()->where('backup_id', $this->backup->id)->sole();
    $this->stored = $this->dir . '/dest/' . $this->copy->path;
});

afterEach(function (): void {
    foreach (['bk_sqlite', 'scratch', 'scratch_bad', 'scratch_full', 'alias'] as $name) {
        DB::purge($name);
    }
    restoreRm($this->dir);
});

it('verifies a good backup and sets verified_at', function (): void {
    $this->artisan('cms:backup-verify', ['uuid' => $this->backup->uuid])
        ->expectsOutputToContain('verified')
        ->doesntExpectOutputToContain($this->dir)
        ->assertExitCode(0);

    expect($this->backup->fresh()->verified_at)->not->toBeNull()
        ->and($this->backup->fresh()->restore_tested_at)->toBeNull()
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('fails verification on a flipped byte', function (): void {
    $bytes = file_get_contents($this->stored);
    $bytes[(int) (strlen($bytes) / 2)] = chr(ord($bytes[(int) (strlen($bytes) / 2)]) ^ 0xFF);
    file_put_contents($this->stored, $bytes);

    $this->artisan('cms:backup-verify', ['uuid' => $this->backup->uuid])
        ->expectsOutputToContain('BACKUP_VERIFY_HASH_MISMATCH')
        ->assertExitCode(1);

    expect($this->backup->fresh()->verified_at)->toBeNull()
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('fails verification when the sidecar does not match', function (): void {
    file_put_contents($this->stored . '.sha256', str_repeat('0', 64) . '  ' . basename($this->copy->path) . "\n");

    $this->artisan('cms:backup-verify', ['uuid' => $this->backup->uuid, '--destination' => 'Local A'])
        ->expectsOutputToContain('BACKUP_VERIFY_SIDECAR_MISMATCH')
        ->assertExitCode(1);

    expect($this->backup->fresh()->verified_at)->toBeNull();
});

it('restores into an empty scratch sqlite file and matches table and migration counts', function (): void {
    $target = $this->dir . '/x_restore_test.sqlite';
    config(['database.connections.scratch' => ['driver' => 'sqlite', 'database' => $target, 'prefix' => '', 'foreign_key_constraints' => false]]);

    $this->artisan('cms:backup-restore-test', ['uuid' => $this->backup->uuid, '--into' => 'scratch'])
        ->expectsOutputToContain('restore_tested')
        ->doesntExpectOutputToContain($this->dir)
        ->assertExitCode(0);

    $fresh = $this->backup->fresh();
    expect($fresh->restore_tested_at)->not->toBeNull()
        ->and($fresh->verified_at)->not->toBeNull()
        ->and(restoreTableCount($target))->toBe(3)
        ->and(DB::connection('scratch')->table('migrations')->count())->toBe(3)
        ->and(DB::connection('scratch')->table('a')->count())->toBe(2)
        ->and(restoreEntries($this->dir . '/staging'))->toBe([])
        ->and(AuditLog::query()->where('action', 'action.backup-restore-test')->where('target_id', $this->backup->id)->exists())->toBeTrue();
});

it('refuses the default connection', function (): void {
    $this->artisan('cms:backup-restore-test', ['uuid' => $this->backup->uuid, '--into' => (string) config('database.default')])
        ->expectsOutputToContain('BACKUP_RESTORE_TARGET_IS_LIVE')
        ->assertExitCode(1);

    expect($this->backup->fresh()->restore_tested_at)->toBeNull()
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('refuses the default database under another connection name', function (): void {
    $live = $this->dir . '/live_restore_test.sqlite';
    touch($live);
    $default = (string) config('database.default');
    config([
        "database.connections.{$default}.database" => $live,
        'database.connections.alias' => ['driver' => 'sqlite', 'database' => $this->dir . '/./live_restore_test.sqlite', 'prefix' => ''],
    ]);

    $this->artisan('cms:backup-restore-test', ['uuid' => $this->backup->uuid, '--into' => 'alias'])
        ->expectsOutputToContain('BACKUP_RESTORE_TARGET_IS_LIVE')
        ->assertExitCode(1);

    expect(filesize($live))->toBe(0)
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('refuses a database name without the _restore_test suffix', function (): void {
    config(['database.connections.scratch_bad' => ['driver' => 'sqlite', 'database' => $this->dir . '/x_scratch.sqlite', 'prefix' => '']]);

    $this->artisan('cms:backup-restore-test', ['uuid' => $this->backup->uuid, '--into' => 'scratch_bad'])
        ->expectsOutputToContain('BACKUP_RESTORE_TARGET_NAME')
        ->assertExitCode(1);

    expect(is_file($this->dir . '/x_scratch.sqlite'))->toBeFalse()
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('refuses a target that already has tables and leaves it untouched', function (): void {
    $target = $this->dir . '/y_restore_test.sqlite';
    $pdo = new PDO('sqlite:' . $target);
    $pdo->exec('CREATE TABLE keep_me (id INTEGER)');
    $pdo->exec('INSERT INTO keep_me VALUES (7)');
    unset($pdo);
    $before = hash_file('sha256', $target);
    config(['database.connections.scratch_full' => ['driver' => 'sqlite', 'database' => $target, 'prefix' => '']]);

    $this->artisan('cms:backup-restore-test', ['uuid' => $this->backup->uuid, '--into' => 'scratch_full'])
        ->expectsOutputToContain('BACKUP_RESTORE_TARGET_NOT_EMPTY')
        ->assertExitCode(1);

    expect(hash_file('sha256', $target))->toBe($before)
        ->and($this->backup->fresh()->restore_tested_at)->toBeNull()
        ->and(restoreEntries($this->dir . '/staging'))->toBe([]);
});

it('fails verification on a truncated dump whose hashes were rewritten to match', function (): void {
    $bytes = substr((string) file_get_contents($this->stored), 0, (int) (filesize($this->stored) / 2));
    file_put_contents($this->stored, $bytes);
    $sha = hash('sha256', $bytes);
    file_put_contents($this->stored . '.sha256', $sha . '  ' . basename($this->copy->path) . "\n");
    $this->backup->forceFill(['sha256' => $sha, 'size_bytes' => strlen($bytes)])->save();
    $this->copy->forceFill(['size_bytes' => strlen($bytes)])->save();

    $this->artisan('cms:backup-verify', ['uuid' => $this->backup->uuid])
        ->expectsOutputToContain('BACKUP_VERIFY_DECOMPRESS_FAILED')
        ->assertExitCode(1);

    expect(restoreEntries($this->dir . '/staging'))->toBe([]);
});
