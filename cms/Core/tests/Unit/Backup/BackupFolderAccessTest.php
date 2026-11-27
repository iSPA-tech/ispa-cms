<?php

declare(strict_types=1);

use Cms\Core\Services\Backup\BackupFolderAccess;
use Illuminate\Support\Facades\File;

uses(Cms\Core\Tests\TestCase::class);

// Spec 2026-11-26 core-admin-update-automatic: backup folders made 0700 by older code lock the core upgrade runner (the
// deploy user, in the web group) out of its pre-upgrade backup; the web user opens them to its group.

beforeEach(function () {
    $this->root = realpath(sys_get_temp_dir()) . '/bfa-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/local/app/site/2026-11-12', 0700, true);
    mkdir($this->root . '/staging', 0700);
    chmod($this->root, 02700);
    foreach (['/local', '/local/app', '/local/app/site', '/local/app/site/2026-11-12', '/staging'] as $dir) {
        chmod($this->root . $dir, 0700);
    }
    file_put_contents($this->root . '/local/app/site/2026-11-12/db.sql.gz', 'x');
    chmod($this->root . '/local/app/site/2026-11-12/db.sql.gz', 0600);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    if (isset($this->outside)) {
        File::deleteDirectory($this->outside);
    }
});

function bfaMode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 07777;
}

it('gives the group full access to every backup folder this process owns and keeps setgid', function (): void {
    $changed = BackupFolderAccess::openForRunner($this->root);

    expect($changed)->toBe(6)
        ->and(bfaMode($this->root))->toBe(02770)
        ->and(bfaMode($this->root . '/staging'))->toBe(0770)
        ->and(bfaMode($this->root . '/local/app/site/2026-11-12'))->toBe(0770)
        ->and(BackupFolderAccess::openForRunner($this->root))->toBe(0);
});

it('leaves backup files alone', function (): void {
    BackupFolderAccess::openForRunner($this->root);

    expect(bfaMode($this->root . '/local/app/site/2026-11-12/db.sql.gz'))->toBe(0600);
});

it('never follows a link out of the backup folder', function (): void {
    $this->outside = realpath(sys_get_temp_dir()) . '/bfa-out-' . bin2hex(random_bytes(6));
    mkdir($this->outside, 0700);
    symlink($this->outside, $this->root . '/local/elsewhere');

    BackupFolderAccess::openForRunner($this->root);

    expect(bfaMode($this->outside))->toBe(0700);
});

it('changes nothing for a folder another user owns, as root, or when the folder is missing', function (): void {
    $other = posix_geteuid() + 4242;

    expect(BackupFolderAccess::openForRunner($this->root, $other))->toBe(0)
        ->and(BackupFolderAccess::openForRunner($this->root, 0))->toBe(0)
        ->and(bfaMode($this->root . '/staging'))->toBe(0700)
        ->and(BackupFolderAccess::openForRunner($this->root . '/missing'))->toBe(0);
});
