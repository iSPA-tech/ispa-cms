<?php

declare(strict_types=1);

use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Cms\Core\Services\Backup\Destinations\SftpDriver;
use Cms\Core\Services\Backup\Destinations\WebdavDriver;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\WebDAV\WebDAVAdapter;

uses(Cms\Core\Tests\TestCase::class);

function nasSftp(array $config = [], array $credentials = ['password' => 'FAKEPASS']): BackupDestination
{
    return new BackupDestination(['name' => 'n', 'driver' => 'sftp', 'config' => $config + [
        'host' => 'nas.example.test', 'username' => 'backup', 'host_fingerprint' => 'aa:bb:cc', 'root' => '/vol1/bk',
    ], 'credentials' => $credentials]);
}

function nasWebdav(array $config = [], array $credentials = ['username' => 'fakeuser', 'password' => 'FAKEPASS']): BackupDestination
{
    return new BackupDestination(['name' => 'w', 'driver' => 'webdav', 'config' => $config + [
        'base_uri' => 'https://nas.example.test/dav/', 'path_prefix' => 'backups',
    ], 'credentials' => $credentials]);
}

it('builds the sftp disk config with a password', function (): void {
    expect((new SftpDriver())->diskConfig(nasSftp()))->toBe([
        'driver' => 'sftp', 'host' => 'nas.example.test', 'port' => 22, 'root' => '/vol1/bk', 'username' => 'backup',
        'hostFingerprint' => 'aa:bb:cc', 'password' => 'FAKEPASS', 'throw' => true,
    ]);
});

it('builds the sftp disk config with a private key', function (): void {
    $cfg = (new SftpDriver())->diskConfig(nasSftp(['port' => 2222], ['private_key' => 'FAKEKEY', 'passphrase' => 'FAKEPHRASE']));
    expect($cfg['port'])->toBe(2222)
        ->and($cfg['privateKey'])->toBe('FAKEKEY')
        ->and($cfg['passphrase'])->toBe('FAKEPHRASE')
        ->and($cfg)->not->toHaveKey('password');
});

it('validates sftp input and never trusts a host on first use', function (): void {
    $d = new SftpDriver();
    $ok = ['password' => 'FAKEPASS'];
    $cfg = ['host' => 'nas.example.test', 'username' => 'backup', 'host_fingerprint' => 'aa:bb'];
    expect($d->validate($cfg, $ok))->toBe([])
        ->and($d->validate($cfg, ['private_key' => 'FAKEKEY']))->toBe([])
        ->and($d->validate(['host_fingerprint' => ''] + $cfg, $ok))->toContain('BACKUP_SFTP_FINGERPRINT_REQUIRED')
        ->and($d->validate(['host' => ''] + $cfg, $ok))->toContain('BACKUP_HOST_REQUIRED')
        ->and($d->validate(['username' => ''] + $cfg, $ok))->toContain('BACKUP_USERNAME_REQUIRED')
        ->and($d->validate(['port' => 70000] + $cfg, $ok))->toContain('BACKUP_PORT_INVALID')
        ->and($d->validate($cfg, ['passphrase' => 'only']))->toContain('BACKUP_CREDENTIALS_REQUIRED');
    expect($d->key())->toBe('sftp')->and($d->credentialFields())->toBe(['password', 'private_key', 'passphrase']);
});

it('builds the webdav disk config', function (): void {
    expect((new WebdavDriver())->diskConfig(nasWebdav()))->toBe([
        'driver' => 'webdav', 'baseUri' => 'https://nas.example.test/dav/', 'userName' => 'fakeuser',
        'password' => 'FAKEPASS', 'pathPrefix' => 'backups', 'throw' => true,
    ]);
});

it('validates webdav input', function (): void {
    $d = new WebdavDriver();
    $ok = ['username' => 'u', 'password' => 'p'];
    expect($d->validate(['base_uri' => 'https://nas.example.test/dav'], $ok))->toBe([])
        ->and($d->validate(['base_uri' => 'http://nas.example.test/dav'], $ok))->toContain('BACKUP_ENDPOINT_NOT_HTTPS')
        ->and($d->validate([], $ok))->toContain('BACKUP_ENDPOINT_NOT_HTTPS')
        ->and($d->validate(['base_uri' => 'https://nas.example.test/dav'], ['username' => 'u']))->toContain('BACKUP_CREDENTIALS_REQUIRED');
    expect($d->key())->toBe('webdav')->and($d->credentialFields())->toBe(['username', 'password']);
});

it('resolves the webdav driver to a WebDAV adapter without a request', function (): void {
    $disk = Storage::build((new WebdavDriver())->diskConfig(nasWebdav()));
    expect($disk->getAdapter())->toBeInstanceOf(WebDAVAdapter::class);
});

it('registers sftp and webdav next to the built-in drivers', function (): void {
    expect((new DestinationRegistry())->keys())->toBe(['s3', 'local', 'sftp', 'webdav', 'gdrive', 'onedrive']);
});

it('never leaks secrets through validation output', function (): void {
    $out = json_encode([
        (new SftpDriver())->validate(['host' => ''], ['password' => 'SECRETVALUE', 'private_key' => 'SECRETKEY']),
        (new SftpDriver())->validate(['host' => ''], ['passphrase' => 'SECRETVALUE']),
        (new WebdavDriver())->validate(['base_uri' => 'http://x'], ['username' => 'SECRETUSER', 'password' => 'SECRETVALUE']),
    ]);
    expect($out)->not->toContain('SECRET');
});
