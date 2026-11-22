<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\Dump\DumpProcessRunner;
use Cms\Core\Services\Backup\Dump\MysqlDumper;

uses(Cms\Core\Tests\TestCase::class);

const MYSQL_TEST_PASSWORD = 'p@ss"w\\ord-SECRET';

function mysqlTestDir(): string
{
    $dir = sys_get_temp_dir() . '/mysqldumper-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

function mysqlTestRm(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_dir($f)) {
            if (! in_array(basename($f), ['.', '..'], true)) {
                mysqlTestRm($f);
            }
        } else {
            @unlink($f);
        }
    }
    @rmdir($dir);
}

/** Fake runner that records argv/env and feeds the given chunks to the stdout callback. */
function fakeMysqlRunner(array $chunks, int $exit = 0, string $version = 'mysqldump  Ver 9.6.0 for macos26.4 on arm64 (Homebrew)'): object
{
    return new class ($chunks, $exit, $version) extends DumpProcessRunner {
        public int $versionCalls = 0;
        public array $argv = [];
        public array $env = [];
        public ?string $credFile = null;
        public ?string $credMode = null;
        public string $credContent = '';

        public function __construct(private array $chunks, private int $exit, private string $version)
        {
        }

        public function run(array $argv, array $env = [], ?callable $onChunk = null): array
        {
            if (($argv[1] ?? '') === '--version') {
                $this->versionCalls++;
                $onChunk($this->version . "\n");

                return ['exitCode' => 0, 'stderr' => ''];
            }
            $this->argv = $argv;
            $this->env = $env;
            $this->credFile = substr($argv[1], strlen('--defaults-extra-file='));
            clearstatcache();
            $this->credMode = substr(sprintf('%o', fileperms($this->credFile)), -4);
            $this->credContent = (string) file_get_contents($this->credFile);
            foreach ($this->chunks as $c) {
                $onChunk($c);
            }

            return ['exitCode' => $this->exit, 'stderr' => $this->exit ? 'denied for ' . MYSQL_TEST_PASSWORD : ''];
        }
    };
}

function mysqlDumperFor(object $runner, int $expected): MysqlDumper
{
    return new class ($runner, $expected) extends MysqlDumper {
        public function __construct(DumpProcessRunner $runner, private int $expected)
        {
            parent::__construct($runner);
        }

        protected function expectedTables(string $connection, string $database): int
        {
            return $this->expected;
        }
    };
}

beforeEach(function (): void {
    $this->dir = mysqlTestDir();
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'database.connections.bk_my' => [
            'driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3307, 'database' => 'site_db',
            'username' => 'dumper', 'password' => MYSQL_TEST_PASSWORD,
        ],
    ]);
    $this->dump = "-- MySQL dump\nCREATE TABLE `a` (id int);\nINSERT INTO a VALUES (1);\nCREATE TABLE `b` (id int);\n-- Dump completed on 2026-11-06\n";
});

afterEach(fn () => mysqlTestRm($this->dir));

it('runs mysqldump with a defaults file first and no password in argv or env', function (): void {
    $runner = fakeMysqlRunner([$this->dump]);
    $result = mysqlDumperFor($runner, 2)->dump('bk_my', $this->dir . '/out/db.sql.gz');

    expect($runner->argv[1])->toStartWith('--defaults-extra-file=')
        ->and($runner->argv)->toContain('--single-transaction', '--quick', '--triggers', '--hex-blob', '--no-tablespaces',
            '--default-character-set=utf8mb4', '--skip-routines', '--skip-events')
        ->and(end($runner->argv))->toBe('site_db')
        ->and(implode(' ', $runner->argv))->not->toContain('SECRET')
        ->and(implode(' ', $runner->argv))->not->toContain('--password')
        ->and(array_filter($runner->argv, fn ($a) => $a === '-p' || str_starts_with($a, '-p')))->toBeEmpty()
        ->and(json_encode($runner->env))->not->toContain('SECRET')
        ->and($runner->credMode)->toBe('0600')
        ->and($runner->credContent)->toContain('[client]')->toContain('host="10.0.0.5"')->toContain('port=3307')
        ->and($runner->credContent)->toContain('password="p@ss\\"w\\\\ord-SECRET"')
        ->and(file_exists($runner->credFile))->toBeFalse()
        ->and(substr(sprintf('%o', fileperms($this->dir . '/staging')), -4))->toBe('0700')
        ->and(substr(sprintf('%o', fileperms($this->dir . '/out/db.sql.gz')), -4))->toBe('0600');

    expect(gzdecode((string) file_get_contents($this->dir . '/out/db.sql.gz')))->toBe($this->dump)
        ->and($result->sha256)->toBe(hash_file('sha256', $this->dir . '/out/db.sql.gz'))
        ->and($result->format)->toBe('sql.gz')
        ->and($result->tablesExpected)->toBe(2)
        ->and($result->tablesDumped)->toBe(2)
        ->and($result->sizeBytes)->toBe(filesize($this->dir . '/out/db.sql.gz'));
});

it('counts CREATE TABLE lines split across chunks', function (): void {
    $chunks = ['-- x\nCRE', 'ATE TA', "BLE `a` (id int);\nINSERT 1;\nCREATE TABLE `b`", " (id int);\nCREATE TABLE `c` (i int);\n-- Dump comp", 'leted on x'];
    $chunks = array_map(fn ($c) => str_replace('\n', "\n", $c), $chunks);
    $result = mysqlDumperFor(fakeMysqlRunner($chunks), 3)->dump('bk_my', $this->dir . '/db.sql.gz');

    expect($result->tablesDumped)->toBe(3);
});

it('does not count CREATE TABLE inside longer lines', function (): void {
    $dump = "INSERT INTO t VALUES ('\nCREATE TABLE no');\n/*!50001 CREATE TABLE `view` */;\nCREATE TABLE `a` (i int);\n-- Dump completed\n";
    $result = mysqlDumperFor(fakeMysqlRunner([$dump]), 2)->dump('bk_my', $this->dir . '/db.sql.gz');

    expect($result->tablesDumped)->toBe(2);
});

it('fails on a table count mismatch and removes the target and credential file', function (): void {
    $runner = fakeMysqlRunner([$this->dump]);
    $target = $this->dir . '/db.sql.gz';

    expect(fn () => mysqlDumperFor($runner, 5)->dump('bk_my', $target))
        ->toThrow(RuntimeException::class, 'BACKUP_TABLE_COUNT_MISMATCH');
    expect(file_exists($target))->toBeFalse()->and(file_exists($runner->credFile))->toBeFalse();
});

it('fails when the completion trailer is missing', function (): void {
    $target = $this->dir . '/db.sql.gz';
    $runner = fakeMysqlRunner(["CREATE TABLE `a` (i int);\n"]);

    expect(fn () => mysqlDumperFor($runner, 1)->dump('bk_my', $target))
        ->toThrow(RuntimeException::class, 'BACKUP_DUMP_INCOMPLETE');
    expect(file_exists($target))->toBeFalse()->and(file_exists($runner->credFile))->toBeFalse();
});

it('fails with BACKUP_DUMP_FAILED on a non-zero exit without leaking the password', function (): void {
    $target = $this->dir . '/db.sql.gz';
    $runner = fakeMysqlRunner([$this->dump], 2);
    $message = '';
    try {
        mysqlDumperFor($runner, 2)->dump('bk_my', $target);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toStartWith('BACKUP_DUMP_FAILED')->not->toContain('SECRET')->toContain('***')
        ->and(file_exists($target))->toBeFalse()->and(file_exists($runner->credFile))->toBeFalse();
});

it('adds --set-gtid-purged=OFF for the Oracle client only', function (string $version, bool $expected): void {
    $runner = fakeMysqlRunner([$this->dump], 0, $version);
    mysqlDumperFor($runner, 2)->dump('bk_my', $this->dir . '/db.sql.gz');

    expect(in_array('--set-gtid-purged=OFF', $runner->argv, true))->toBe($expected)
        ->and($runner->argv[1])->toStartWith('--defaults-extra-file=');
})->with([
    'oracle 9.6' => ['mysqldump  Ver 9.6.0 for macos26.4 on arm64 (Homebrew)', true],
    'oracle 8.0' => ['mysqldump  Ver 8.0.36 for Linux on x86_64 (MySQL Community Server - GPL)', true],
    'mariadb legacy' => ['mysqldump  Ver 10.19 Distrib 10.6.18-MariaDB, for debian-linux-gnu (x86_64)', false],
    'mariadb-dump' => ['mariadb-dump from 11.4.2-MariaDB, client 10.19 for Linux (x86_64)', false],
]);

it('detects the client version once per instance', function (): void {
    $runner = fakeMysqlRunner([$this->dump]);
    $dumper = mysqlDumperFor($runner, 2);
    $dumper->dump('bk_my', $this->dir . '/a.sql.gz');
    $dumper->dump('bk_my', $this->dir . '/b.sql.gz');

    expect($runner->versionCalls)->toBe(1);
});
