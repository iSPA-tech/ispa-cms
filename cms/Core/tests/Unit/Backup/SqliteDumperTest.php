<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\Dump\DumperFactory;
use Cms\Core\Services\Backup\Dump\MysqlDumper;
use Cms\Core\Services\Backup\Dump\PostgresDumper;
use Cms\Core\Services\Backup\Dump\SqliteDumper;

uses(Cms\Core\Tests\TestCase::class);

function sqliteTestRm(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? sqliteTestRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/sqlitedumper-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $source = $this->dir . '/source.sqlite';
    $pdo = new PDO('sqlite:' . $source);
    $pdo->exec('CREATE TABLE a (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $pdo->exec('CREATE TABLE b (id INTEGER)');
    $pdo->exec('CREATE TABLE c (id INTEGER)');
    $pdo->exec("INSERT INTO a (name) VALUES ('one'), ('two')");
    unset($pdo);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'database.connections.bk_sqlite' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => false],
    ]);
});

afterEach(function (): void {
    DB::purge('bk_sqlite');
    sqliteTestRm($this->dir);
});

it('dumps a real sqlite file to gzip and round-trips it', function (): void {
    $target = $this->dir . '/out/db.sqlite.gz';
    $result = (new SqliteDumper())->dump('bk_sqlite', $target);

    expect($result->format)->toBe('sqlite.gz')
        ->and($result->tablesExpected)->toBe(3)
        ->and($result->tablesDumped)->toBe(3)
        ->and($result->sha256)->toBe(hash_file('sha256', $target))
        ->and($result->sizeBytes)->toBe(filesize($target))
        ->and(substr(sprintf('%o', fileperms($target)), -4))->toBe('0600')
        ->and(glob($this->dir . '/staging/*'))->toBeEmpty();

    $restored = $this->dir . '/restored.sqlite';
    file_put_contents($restored, gzdecode((string) file_get_contents($target)));
    $pdo = new PDO('sqlite:' . $restored);
    expect((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn())->toBe(3)
        ->and((int) $pdo->query('SELECT COUNT(*) FROM a')->fetchColumn())->toBe(2);
});

it('refuses an in-memory database and leaves no target', function (): void {
    config(['database.connections.bk_mem' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    expect(fn () => (new SqliteDumper())->dump('bk_mem', $this->dir . '/x.gz'))
        ->toThrow(RuntimeException::class, 'BACKUP_DUMP_FAILED');
    expect(file_exists($this->dir . '/x.gz'))->toBeFalse();
});

it('picks the dumper by driver', function (): void {
    config([
        'database.connections.f_my' => ['driver' => 'mysql'],
        'database.connections.f_maria' => ['driver' => 'mariadb'],
        'database.connections.f_pg' => ['driver' => 'pgsql'],
        'database.connections.f_bad' => ['driver' => 'sqlsrv'],
    ]);
    $factory = new DumperFactory();

    expect($factory->make('f_my'))->toBeInstanceOf(MysqlDumper::class)
        ->and($factory->make('f_maria'))->toBeInstanceOf(MysqlDumper::class)
        ->and($factory->make('f_pg'))->toBeInstanceOf(PostgresDumper::class)
        ->and($factory->make('bk_sqlite'))->toBeInstanceOf(SqliteDumper::class)
        ->and(fn () => $factory->make('f_bad'))->toThrow(RuntimeException::class, 'BACKUP_DUMP_FAILED');
});
