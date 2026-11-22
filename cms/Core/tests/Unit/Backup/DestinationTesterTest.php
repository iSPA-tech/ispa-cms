<?php

declare(strict_types=1);

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationTester;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\UnableToDeleteFile;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function testerDestination(string $root): BackupDestination
{
    return BackupDestination::query()->create(['name' => 'probe-' . bin2hex(random_bytes(4)), 'driver' => 'local', 'config' => ['root' => $root]]);
}

function testerFakeDisk(Throwable $deleteError): Filesystem
{
    $store = [];
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnUsing(function (string $p) use (&$store): bool { return isset($store[$p]); });
    $disk->shouldReceive('put')->andReturnUsing(function (string $p, $c) use (&$store): bool { $store[$p] = $c; return true; });
    $disk->shouldReceive('get')->andReturnUsing(function (string $p) use (&$store) { return $store[$p] ?? null; });
    $disk->shouldReceive('delete')->andThrow($deleteError);

    return $disk;
}

it('passes a probe on a real local dir and leaves nothing behind', function (): void {
    $dir = sys_get_temp_dir() . '/ispa-tester-' . bin2hex(random_bytes(6));
    try {
        $result = (new DestinationTester())->test(testerDestination($dir));
        expect($result)->toMatchArray(['ok' => true, 'write' => true, 'read' => true, 'delete' => true, 'lock_active' => false, 'error_code' => null])
            ->and($result['path'])->toContain('_probe/')
            ->and(BackupCopy::query()->count())->toBe(0);
        $files = is_dir($dir) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))) : [];
        expect($files)->toBe([]);
    } finally {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
});

it('reports an active lock and records a probe copy for later pruning', function (): void {
    $s3e = new S3Exception('ObjectLockedByBucketPolicy', new Command('DeleteObject'), ['response' => new Response(409), 'code' => 'ObjectLockedByBucketPolicy']);
    $destination = testerDestination('/unused');
    $result = (new DestinationTester())->test($destination, testerFakeDisk(UnableToDeleteFile::atLocation('p', 'locked', $s3e)));

    expect($result['ok'])->toBeTrue()->and($result['lock_active'])->toBeTrue()->and($result['delete'])->toBeFalse();
    $copy = BackupCopy::query()->sole();
    expect($copy->kind)->toBe(BackupCopy::KIND_PROBE)
        ->and($copy->backup_id)->toBeNull()
        ->and($copy->destination_id)->toBe($destination->id)
        ->and($copy->status)->toBe(BackupCopy::STATUS_STORED)
        ->and($copy->path)->toContain('_probe/')
        ->and($copy->path)->toBe($result['path'])
        ->and($copy->size_bytes)->toBeGreaterThan(0)
        ->and($copy->uploaded_at)->not->toBeNull();
});

it('fails without leaking text on a non-lock delete failure', function (): void {
    $result = (new DestinationTester())->test(testerDestination('/unused'), testerFakeDisk(new RuntimeException('boom SECRET-TOKEN-123')));

    expect($result['ok'])->toBeFalse()->and($result['lock_active'])->toBeFalse()
        ->and($result['error_code'])->toBe('BACKUP_PROBE_DELETE_FAILED')
        ->and(json_encode($result))->not->toContain('SECRET-TOKEN-123')
        ->and(BackupCopy::query()->count())->toBe(0);
});

it('fails on write errors and read mismatches', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('put')->andThrow(new RuntimeException('nope SECRET-TOKEN-123'));
    $r = (new DestinationTester())->test(testerDestination('/unused'), $disk);
    expect($r['ok'])->toBeFalse()->and($r['write'])->toBeFalse()->and($r['error_code'])->toBe('BACKUP_PROBE_WRITE_FAILED')
        ->and(json_encode($r))->not->toContain('SECRET');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('put')->andReturn(true);
    $disk->shouldReceive('get')->andReturn('different');
    $disk->shouldReceive('delete')->andReturn(true);
    $r = (new DestinationTester())->test(testerDestination('/unused'), $disk);
    expect($r['ok'])->toBeFalse()->and($r['error_code'])->toBe('BACKUP_PROBE_READ_MISMATCH');
});
