<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Cms\Core\Services\Backup\Destinations\DestinationRules;
use Cms\Core\Services\Backup\Destinations\LocalDriver;
use Cms\Core\Services\Backup\Destinations\S3Driver;

uses(Cms\Core\Tests\TestCase::class);

function driversS3(array $config = [], array $credentials = ['access_key_id' => 'FAKEKEY', 'secret_access_key' => 'FAKESECRET']): BackupDestination
{
    return new BackupDestination(['name' => 's', 'driver' => 's3', 'config' => $config + ['endpoint' => 'https://acct.r2.example.com', 'bucket' => 'bk'], 'credentials' => $credentials]);
}

it('builds the s3 disk config', function (): void {
    expect((new S3Driver())->diskConfig(driversS3()))->toBe([
        'driver' => 's3', 'key' => 'FAKEKEY', 'secret' => 'FAKESECRET', 'region' => 'auto', 'bucket' => 'bk',
        'endpoint' => 'https://acct.r2.example.com', 'use_path_style_endpoint' => false, 'throw' => true,
    ]);
    $cfg = (new S3Driver())->diskConfig(driversS3(['region' => 'eu-1', 'path_style' => true]));
    expect($cfg['region'])->toBe('eu-1')->and($cfg['use_path_style_endpoint'])->toBeTrue();
});

it('validates s3 input', function (): void {
    $d = new S3Driver();
    $ok = ['access_key_id' => 'a', 'secret_access_key' => 'b'];
    expect($d->validate(['endpoint' => 'https://x.example', 'bucket' => 'b'], $ok))->toBe([])
        ->and($d->validate(['endpoint' => 'http://x.example', 'bucket' => 'b'], $ok))->toContain('BACKUP_ENDPOINT_NOT_HTTPS')
        ->and($d->validate(['endpoint' => 'https://x.example'], $ok))->toContain('BACKUP_BUCKET_REQUIRED')
        ->and($d->validate(['endpoint' => 'https://x.example', 'bucket' => 'b'], []))->toContain('BACKUP_CREDENTIALS_REQUIRED');
    expect($d->key())->toBe('s3')->and($d->credentialFields())->toBe(['access_key_id', 'secret_access_key']);
});

it('validates retention', function (): void {
    config(['core.backup.max_retention_days' => 90]);
    expect(DestinationRules::retention(3, 3))->toContain('BACKUP_RETENTION_NOT_ABOVE_LOCK')
        ->and(DestinationRules::retention(2, 3))->toContain('BACKUP_RETENTION_NOT_ABOVE_LOCK')
        ->and(DestinationRules::retention(91, 0))->toContain('BACKUP_RETENTION_TOO_LONG')
        ->and(DestinationRules::retention(30, 7))->toBe([])
        ->and(DestinationRules::retention(3, 0))->toBe([]);
});

it('combines driver and retention validation', function (): void {
    $rules = new DestinationRules(new DestinationRegistry());
    expect($rules->validate('s3', ['endpoint' => 'http://x'], [], 3, 3))
        ->toContain('BACKUP_ENDPOINT_NOT_HTTPS', 'BACKUP_BUCKET_REQUIRED', 'BACKUP_CREDENTIALS_REQUIRED', 'BACKUP_RETENTION_NOT_ABOVE_LOCK')
        ->and($rules->validate('ftp', [], [], 3, 0))->toBe(['BACKUP_DRIVER_UNSUPPORTED']);
});

it('refuses local roots in forbidden places', function (): void {
    $d = new LocalDriver();
    $codes = fn (string $root) => $d->validate(['root' => $root], []);
    expect($codes(storage_path('app/x')))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(storage_path('app')))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(public_path('x')))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(base_path('foo')))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(base_path()))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(storage_path('app/../app/y')))->toContain('BACKUP_LOCAL_ROOT_FORBIDDEN')
        ->and($codes(storage_path('backups/x')))->toBe([])
        ->and($codes(storage_path('backups') . '/'))->toBe([])
        ->and($codes('relative/path'))->toContain('BACKUP_LOCAL_ROOT_INVALID')
        ->and($codes(''))->toContain('BACKUP_LOCAL_ROOT_REQUIRED')
        ->and($codes(sys_get_temp_dir() . '/ispa-backup-outside'))->toBe([]);
});

it('does not treat a sibling with a shared name prefix as inside', function (): void {
    $original = base_path();
    $fake = sys_get_temp_dir() . '/ispa-fakebase-' . bin2hex(random_bytes(4));
    app()->setBasePath($fake);
    try {
        $d = new LocalDriver();
        expect($d->validate(['root' => $fake . 'c/y'], []))->toBe([])
            ->and($d->validate(['root' => $fake . '/y'], []))->toBe(['BACKUP_LOCAL_ROOT_FORBIDDEN']);
    } finally {
        app()->setBasePath($original);
    }
});

it('registers drivers and rejects unknown ones', function (): void {
    $r = new DestinationRegistry();
    expect($r->has('s3'))->toBeTrue()->and($r->has('local'))->toBeTrue()->and($r->has('ftp'))->toBeFalse()
        ->and($r->keys())->toBe(['s3', 'local', 'sftp', 'webdav', 'gdrive', 'onedrive'])
        ->and($r->get('s3'))->toBeInstanceOf(S3Driver::class);
    expect(fn () => $r->get('ftp'))->toThrow(InvalidArgumentException::class, 'BACKUP_DRIVER_UNSUPPORTED');
    $r->register(new class extends LocalDriver {
        public function key(): string
        {
            return 'custom';
        }
    });
    expect($r->has('custom'))->toBeTrue();
});

it('builds a working local disk', function (): void {
    $dir = sys_get_temp_dir() . '/ispa-drv-' . bin2hex(random_bytes(6));
    try {
        $disk = DestinationDisk::for(new BackupDestination(['name' => 'l', 'driver' => 'local', 'config' => ['root' => $dir . '/sub']]));
        $disk->put('a/b.txt', 'hello');
        expect($disk->get('a/b.txt'))->toBe('hello')
            ->and(is_dir($dir . '/sub'))->toBeTrue()
            ->and(substr(sprintf('%o', fileperms($dir . '/sub')), -4))->toBe('0700')
            ->and(substr(sprintf('%o', fileperms($dir . '/sub/a/b.txt')), -4))->toBe('0600');
    } finally {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
});

it('refuses to build a disk on a forbidden local root', function (): void {
    expect(fn () => DestinationDisk::for(new BackupDestination(['name' => 'l', 'driver' => 'local', 'config' => ['root' => public_path('x')]])))
        ->toThrow(InvalidArgumentException::class, 'BACKUP_LOCAL_ROOT_FORBIDDEN');
});
