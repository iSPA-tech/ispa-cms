<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\Backup\Destinations\ObjectKey;
use Cms\Core\Models\Backup\BackupDestination;

uses(Cms\Core\Tests\TestCase::class);

function objectKeyTempDir(): string
{
    $dir = sys_get_temp_dir() . '/ispa-objkey-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir;
}

function objectKeyRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-06 03:04:05', 'UTC'));
    config(['core.backup.server_label' => 'srv140', 'core.backup.site_slug' => 'example.com']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('builds the default layout with label prefix', function (): void {
    expect(ObjectKey::build('ispa_db', 'manual'))->toBe('srv140/app/example.com/2026-11-06/ispa_db-030405-manual.sql.gz');
    expect(ObjectKey::build('ispa_db', 'scheduled', 'pgdump'))->toEndWith('/ispa_db-030405-scheduled.pgdump');
    expect(ObjectKey::build('ispa_db', 'manual', 'sqlite.gz'))->toEndWith('-manual.sqlite.gz');
});

it('uses the app prefix without a label', function (): void {
    config(['core.backup.server_label' => null]);
    expect(ObjectKey::build('db', 'manual'))->toBe('app/example.com/2026-11-06/db-030405-manual.sql.gz');
    config(['core.backup.server_label' => '']);
    expect(ObjectKey::defaultPrefix())->toBe('app');
});

it('expands tokens in a custom prefix and sanitizes segments', function (): void {
    expect(ObjectKey::build('my db!', 'pre/install', 'sql.gz', 'x/{label}/{site}'))
        ->toBe('x/srv140/example.com/example.com/2026-11-06/mydb-030405-preinstall.sql.gz');
});

it('rejects unsafe prefixes', function (string $prefix): void {
    expect(fn () => ObjectKey::build('db', 'manual', 'sql.gz', $prefix))
        ->toThrow(InvalidArgumentException::class, 'BACKUP_KEY_INVALID');
})->with(['dotdot' => 'a/../b', 'leading slash' => '/abs', 'empty segment' => 'a//b', 'unknown token' => 'a/{nope}']);

it('returns the same key when it is free and suffixes when taken', function (): void {
    $dir = objectKeyTempDir();
    try {
        $disk = DestinationDisk::for(new BackupDestination(['name' => 't', 'driver' => 'local', 'config' => ['root' => $dir]]));
        $key = 'a/b/db-030405-manual.sql.gz';
        expect(ObjectKey::fresh($disk, $key))->toBe($key);

        $disk->put($key, 'x');
        $second = ObjectKey::fresh($disk, $key);
        expect($second)->toBe('a/b/db-030405-manual-030405.sql.gz');

        $disk->put($second, 'x');
        $third = ObjectKey::fresh($disk, $key);
        expect($third)->toBe('a/b/db-030405-manual-030405-2.sql.gz')->and($disk->exists($third))->toBeFalse();
    } finally {
        objectKeyRemoveDir($dir);
    }
});

it('builds a probe key', function (): void {
    expect(ObjectKey::probe('srv140/app', 'example.com'))->toMatch('#^srv140/app/example\.com/_probe/[0-9a-f-]{36}\.txt$#');
});
