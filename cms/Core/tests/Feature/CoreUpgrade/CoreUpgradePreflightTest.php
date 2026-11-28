<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;

// Every test works in a temp base with injected free space, heartbeat and lock closures.

function cpfRm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    chmod($path, 0755);
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        cpfRm($entry->getPathname());
    }
    rmdir($path);
}

/** A flat site at $base, installed 2026.12.0 with the given PHP minimum. */
function cpfSite(string $base, string $phpMin = '8.3'): CoreManifest
{
    @mkdir($base.'/app', 0755, true);
    file_put_contents($base.'/app/X.php', 'x-v1');
    file_put_contents($base.'/composer.lock', 'lock-v1');
    file_put_contents($base.'/'.CoreManifest::FILE, json_encode([
        'format' => 1, 'version' => '2026.12.0', 'php_min' => $phpMin, 'built_at' => '2026-11-18T00:00:00Z',
        'composer_lock_sha256' => hash('sha256', 'lock-v1'),
        'checked' => ['app/X.php' => hash('sha256', 'x-v1')],
        'replaced' => ['vendor'],
    ], JSON_UNESCAPED_SLASHES));

    return CoreManifest::installed($base);
}

function cpfHeartbeat(string $php = PHP_VERSION): array
{
    return ['at' => time(), 'user' => 'owner', 'uid' => posix_geteuid(), 'php_version' => $php];
}

function cpfPreflight(string $base, ?array $heartbeat, bool $lockHeld = false): CoreUpgradePreflight
{
    return new CoreUpgradePreflight($base, null, fn (): int => PHP_INT_MAX, fn (): ?array => $heartbeat, fn (): bool => $lockHeld);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/core-preflight-'.bin2hex(random_bytes(6));
    $this->base = $this->dir.'/site';
    mkdir($this->base, 0755, true);
    $this->manifest = cpfSite($this->base);
});

afterEach(function () {
    cpfRm($this->dir);
});

test('admin mode needs a fresh runner when the web user cannot run it', function () {
    // Code the web user cannot write: another user must run the upgrade (runner cron).
    chmod($this->base.'/app', 0555);
    try {
        $without = cpfPreflight($this->base, null)->check($this->manifest, '2026.12.1', true);
        $with = cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true);
    } finally {
        chmod($this->base.'/app', 0755);
    }

    expect($without->blockingCodes())->toContain('CORE_NO_RUNNER')
        ->and($with->blockingCodes())->not->toContain('CORE_NO_RUNNER');
});

test('simple hosting: the web user owns the code, so the admin needs no runner cron', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root never runs the upgrade');
    }
    $result = cpfPreflight($this->base, null)->check($this->manifest, '2026.12.1', true);

    expect($result->blockingCodes())->not->toContain('CORE_NO_RUNNER')
        ->and($result->blockingCodes())->not->toContain('CORE_CODE_DIR_SHARED');
});

test('admin mode ignores web-user writability', function () {
    chmod($this->base.'/app', 0555);

    $admin = cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true);
    $cli = cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', false);

    expect($admin->blockingCodes())->not->toContain('CORE_NOT_WRITABLE')
        ->and($cli->blockingCodes())->toContain('CORE_NOT_WRITABLE');
});

test('changed core files block the admin and the CLI until the overwrite is confirmed', function () {
    file_put_contents($this->base.'/app/X.php', 'x-hot-patch');
    $preflight = cpfPreflight($this->base, cpfHeartbeat());

    foreach ([true, false] as $forAdmin) {
        $plain = $preflight->check($this->manifest, '2026.12.1', $forAdmin);
        $overwrite = $preflight->check($this->manifest, '2026.12.1', $forAdmin, true);

        expect($plain->blockingCodes())->toContain('CORE_MODIFIED')
            ->and($overwrite->blockingCodes())->not->toContain('CORE_MODIFIED')
            ->and(array_column($overwrite->warnings, 'code'))->toContain('CORE_MODIFIED')
            ->and($overwrite->warnings[array_search('CORE_MODIFIED', array_column($overwrite->warnings, 'code'), true)]['details']['files'])->toBe(['app/X.php']);
    }
});

test('admin mode refuses a release layout', function () {
    $release = $this->dir.'/releases/r1';
    mkdir($release, 0755, true);
    $manifest = cpfSite($release);
    symlink($release, $this->dir.'/current');

    $result = cpfPreflight($release, cpfHeartbeat())->check($manifest, '2026.12.1', true);

    expect($result->blockingCodes())->toContain('CORE_RELEASE_LAYOUT');
});

test('a held lock hides the state file in admin mode', function () {
    @mkdir($this->base.'/storage/app/ispa', 0775, true);
    file_put_contents($this->base.'/'.CoreUpgradePreflight::STATE_FILE, '{}');
    @mkdir($this->base.'/.ispa-runner', 0700, true);
    file_put_contents($this->base.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE, '{}');

    $held = cpfPreflight($this->base, cpfHeartbeat(), true);
    $free = cpfPreflight($this->base, cpfHeartbeat(), false);

    expect($held->interruptedForAdmin())->toBeFalse()
        ->and($free->interruptedForAdmin())->toBeTrue()
        ->and($held->check($this->manifest, '2026.12.1', true)->blockingCodes())->not->toContain('CORE_UPGRADE_INTERRUPTED')
        ->and($free->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED')
        ->and($held->check($this->manifest, '2026.12.1', false)->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED')
        ->and($free->check($this->manifest, '2026.12.1', false)->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED');
});

test('CORE_PHP when the runner PHP is too old', function () {
    $manifest = cpfSite($this->base, '^8.3');

    $old = cpfPreflight($this->base, cpfHeartbeat('8.1.0'))->check($manifest, '2026.12.1', true);
    $current = cpfPreflight($this->base, cpfHeartbeat())->check($manifest, '2026.12.1', true);

    expect($old->blockingCodes())->toContain('CORE_PHP')
        ->and($current->blockingCodes())->not->toContain('CORE_PHP');
});

test('CORE_PHP when an extra PHP version is too old', function () {
    $manifest = cpfSite($this->base, '^8.3');

    $result = cpfPreflight($this->base, null)->check($manifest, '2026.12.1', false, extraPhpVersions: ['8.2.0']);

    expect($result->blockingCodes())->toContain('CORE_PHP');
});

test('CORE_NO_QUEUE is never produced', function () {
    config(['queue.default' => 'sync']);

    $admin = (new CoreUpgradePreflight($this->base, null, fn (): int => PHP_INT_MAX, fn (): ?array => null, fn (): bool => false))
        ->check($this->manifest, '2026.12.1', true);
    $cli = (new CoreUpgradePreflight($this->base, null, fn (): int => PHP_INT_MAX))
        ->check($this->manifest, '2026.12.1', false);

    expect($admin->blockingCodes())->not->toContain('CORE_NO_QUEUE')
        ->and($cli->blockingCodes())->not->toContain('CORE_NO_QUEUE');
});

test('the admin sees an interrupted upgrade only for a plain state file owned by the runner', function () {
    @mkdir($this->base.'/storage/app/ispa', 0775, true);
    $state = $this->base.'/'.CoreUpgradePreflight::STATE_FILE;
    file_put_contents($state, '{}');

    expect(cpfPreflight($this->base, cpfHeartbeat())->interruptedForAdmin())->toBeTrue()
        ->and(cpfPreflight($this->base, null)->interruptedForAdmin())->toBeFalse()
        ->and(cpfPreflight($this->base, ['uid' => posix_geteuid() + 1] + cpfHeartbeat())->interruptedForAdmin())->toBeFalse();

    unlink($state);
    file_put_contents($this->dir.'/planted.json', '{}');
    symlink($this->dir.'/planted.json', $state);
    expect(cpfPreflight($this->base, cpfHeartbeat())->interruptedForAdmin())->toBeFalse()
        ->and(cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true)->blockingCodes())->not->toContain('CORE_UPGRADE_INTERRUPTED');
});

test('an unfinished module install blocks the admin, the runner and the CLI', function () {
    $busy = new CoreUpgradePreflight($this->base, null, fn (): int => PHP_INT_MAX, fn (): ?array => cpfHeartbeat(), fn (): bool => false, fn (): bool => true);
    $free = new CoreUpgradePreflight($this->base, null, fn (): int => PHP_INT_MAX, fn (): ?array => cpfHeartbeat(), fn (): bool => false, fn (): bool => false);

    expect($busy->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_MODULE_INSTALL_RUNNING')
        ->and($busy->check($this->manifest, '2026.12.1', false)->blockingCodes())->toContain('CORE_MODULE_INSTALL_RUNNING')
        ->and($free->check($this->manifest, '2026.12.1', true)->blockingCodes())->not->toContain('CORE_MODULE_INSTALL_RUNNING');
});

test('the admin preflight shows CORE_CODE_DIR_SHARED for a group-writable base or a base the runner does not own', function () {
    expect(cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true)->blockingCodes())->not->toContain('CORE_CODE_DIR_SHARED');

    chmod($this->base, 0775);
    try {
        expect(cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_CODE_DIR_SHARED')
            ->and(cpfPreflight($this->base, null)->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_CODE_DIR_SHARED');
    } finally {
        chmod($this->base, 0755);
    }

    $other = ['uid' => posix_geteuid() + 1] + cpfHeartbeat();
    expect(cpfPreflight($this->base, $other)->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_CODE_DIR_SHARED');
});

test('the admin reads the web marker, the CLI only the private state', function () {
    @mkdir($this->base.'/storage/app/ispa', 0775, true);
    file_put_contents($this->base.'/'.CoreUpgradePreflight::STATE_FILE, '{"format":1}');

    expect(cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', true)->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED')
        ->and(cpfPreflight($this->base, cpfHeartbeat())->check($this->manifest, '2026.12.1', false)->blockingCodes())->not->toContain('CORE_UPGRADE_INTERRUPTED');

    unlink($this->base.'/'.CoreUpgradePreflight::STATE_FILE);
    @mkdir($this->base.'/.ispa-runner', 0700, true);
    file_put_contents($this->base.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE, '{}');
    expect(cpfPreflight($this->base, null)->check($this->manifest, '2026.12.1', false)->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED');
});
