<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\Dump\DumpProcessRunner;
use Cms\Core\Services\Backup\Dump\PostgresDumper;

uses(Cms\Core\Tests\TestCase::class);

function pgTestRm(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? pgTestRm($f) : @unlink($f);
    }
    @rmdir($dir);
}

/** Fake runner: pg_dump writes the target file, pg_restore --list prints the given listing. */
function fakePgRunner(string $listing, int $dumpExit = 0): object
{
    return new class ($listing, $dumpExit) extends DumpProcessRunner {
        public array $calls = [];
        public ?string $passFile = null;
        public ?string $passMode = null;
        public string $passContent = '';

        public function __construct(private string $listing, private int $dumpExit)
        {
        }

        public function run(array $argv, array $env = [], ?callable $onChunk = null): array
        {
            $this->calls[] = ['argv' => $argv, 'env' => $env];
            $this->passFile = $env['PGPASSFILE'];
            clearstatcache();
            $this->passMode = substr(sprintf('%o', fileperms($this->passFile)), -4);
            $this->passContent = (string) file_get_contents($this->passFile);
            if (str_contains($argv[0], 'pg_dump')) {
                if ($this->dumpExit === 0) {
                    file_put_contents($argv[array_search('-f', $argv, true) + 1], 'PGDMP-binary');
                }

                return ['exitCode' => $this->dumpExit, 'stderr' => 'fatal: password MYPGSECRET:with\\chars rejected'];
            }
            $onChunk($this->listing);

            return ['exitCode' => 0, 'stderr' => ''];
        }
    };
}

function pgDumperFor(object $runner, int $expected): PostgresDumper
{
    return new class ($runner, $expected) extends PostgresDumper {
        public function __construct(DumpProcessRunner $runner, private int $expected)
        {
            parent::__construct($runner);
        }

        protected function expectedTables(string $connection): int
        {
            return $this->expected;
        }
    };
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/pgdumper-' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    config([
        'core.backup.staging_path' => $this->dir . '/staging',
        'database.connections.bk_pg' => [
            'driver' => 'pgsql', 'host' => 'db.local', 'port' => 5433, 'database' => 'site', 'username' => 'me',
            'password' => 'MYPGSECRET:with\\chars',
        ],
    ]);
    $this->listing = <<<'LIST'
;
; Archive created
215; 1259 16385 TABLE public users me
216; 1259 16390 TABLE public posts me
4001; 0 16385 TABLE DATA public users me
4002; 0 16390 TABLE DATA public posts me
217; 1259 16395 VIEW public v me
218; 1259 16396 SEQUENCE public users_id_seq me
LIST;
});

afterEach(fn () => pgTestRm($this->dir));

it('runs pg_dump with PGPASSFILE and counts TABLE definitions only', function (): void {
    $runner = fakePgRunner($this->listing);
    $target = $this->dir . '/out/site.dump';
    $result = pgDumperFor($runner, 2)->dump('bk_pg', $target);

    [$dump, $restore] = $runner->calls;
    expect($dump['argv'])->toBe(['pg_dump', '-Fc', '--no-owner', '-h', 'db.local', '-p', '5433', '-U', 'me', '-d', 'site', '-f', $target])
        ->and(implode(' ', $dump['argv']))->not->toContain('MYPGSECRET')
        ->and($restore['argv'])->toBe(['pg_restore', '--list', $target])
        ->and($dump['env'])->toHaveKey('PGPASSFILE')
        ->and(json_encode($dump['env']))->not->toContain('MYPGSECRET')
        ->and($runner->passMode)->toBe('0600')
        ->and($runner->passContent)->toBe("db.local:5433:site:me:MYPGSECRET\\:with\\\\chars\n")
        ->and(file_exists($runner->passFile))->toBeFalse()
        ->and($result->format)->toBe('pgdump')
        ->and($result->tablesDumped)->toBe(2)
        ->and($result->sha256)->toBe(hash_file('sha256', $target))
        ->and(substr(sprintf('%o', fileperms($target)), -4))->toBe('0660');
});

it('fails with a table count mismatch and removes the dump', function (): void {
    $runner = fakePgRunner($this->listing);
    $target = $this->dir . '/site.dump';

    expect(fn () => pgDumperFor($runner, 3)->dump('bk_pg', $target))
        ->toThrow(RuntimeException::class, 'BACKUP_TABLE_COUNT_MISMATCH');
    expect(file_exists($target))->toBeFalse()->and(file_exists($runner->passFile))->toBeFalse();
});

it('fails with BACKUP_DUMP_FAILED without leaking the password', function (): void {
    $runner = fakePgRunner($this->listing, 1);
    $message = '';
    try {
        pgDumperFor($runner, 2)->dump('bk_pg', $this->dir . '/site.dump');
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toStartWith('BACKUP_DUMP_FAILED')->not->toContain('MYPGSECRET')
        ->and(file_exists($runner->passFile))->toBeFalse();
});
