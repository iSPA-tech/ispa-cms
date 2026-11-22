<?php

use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;

const CRT_TASK = '0b6f2c1e-4a7d-4c3e-9f10-2a3b4c5d6e7f';

function crtRemoveTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if (! $item->isLink()) {
            chmod($item->getPathname(), 0775);
        }
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function crtBase(string $root, bool $manifest = true): string
{
    mkdir($root.'/app', 0775, true);
    file_put_contents($root.'/app/X.php', '<?php');
    if ($manifest) {
        file_put_contents($root.'/core-manifest.json', json_encode([
            'format' => 1, 'version' => '2026.12.0', 'php_min' => '8.2', 'built_at' => '2026-11-22T00:00:00+00:00',
            'composer_lock_sha256' => '', 'checked' => ['app/X.php' => str_repeat('a', 64)], 'replaced' => [],
        ]));
    }

    return $root;
}

function crtWriteRawRequest(string $base, array|string $data): void
{
    is_dir($base.'/storage/app/ispa') || mkdir($base.'/storage/app/ispa', 0775, true);
    file_put_contents($base.'/'.CoreRunnerTick::REQUEST_FILE, is_string($data) ? $data : json_encode($data));
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/core-runner-tick-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0775, true);
    $this->base = crtBase($this->dir.'/site');
    $this->now = 1_790_000_000;
});

afterEach(function () {
    crtRemoveTree($this->dir);
});

it('canRun is false without a manifest, on a release layout, or with an unwritable top path', function () {
    expect((new CoreRunnerTick($this->base))->canRun())->toBeTrue();

    $bare = crtBase($this->dir.'/bare', false);
    expect((new CoreRunnerTick($bare))->canRun())->toBeFalse();

    $release = crtBase($this->dir.'/releases/r1');
    symlink($release, $this->dir.'/releases/current');
    expect((new CoreRunnerTick($release))->canRun())->toBeFalse();

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('chmod does not stop root.');
    }
    chmod($this->base.'/app', 0555);
    expect(CoreRunnerTick::unwritablePaths($this->base, \Cms\Core\Services\CoreUpgrade\CoreManifest::installed($this->base)))->toBe(['app'])
        ->and((new CoreRunnerTick($this->base))->canRun())->toBeFalse();
    chmod($this->base.'/app', 0775);
});

it('unwritablePaths checks the runner folder without creating it', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('chmod does not stop root.');
    }
    $manifest = \Cms\Core\Services\CoreUpgrade\CoreManifest::installed($this->base);
    mkdir($this->base.'/storage/app', 0775, true);
    chmod($this->base.'/storage/app', 0555);

    expect(CoreRunnerTick::unwritablePaths($this->base, $manifest))->toBe(['storage/app'])
        ->and(is_dir($this->base.'/'.CoreRunnerTick::DIR))->toBeFalse();
    chmod($this->base.'/storage/app', 0775);
});

it('preBoot writes a heartbeat only when it can run', function () {
    $bare = crtBase($this->dir.'/bare', false);
    expect((new CoreRunnerTick($bare))->preBoot($this->now))->toBe('cannot_run')
        ->and(file_exists($bare.'/'.CoreRunnerTick::HEARTBEAT_FILE))->toBeFalse();

    expect((new CoreRunnerTick($this->base))->preBoot($this->now))->toBe('idle');
    $beat = CoreRunnerTick::freshHeartbeat($this->base, $this->now);
    expect($beat['php_version'])->toBe(PHP_VERSION)
        ->and($beat['at'])->toBe($this->now)
        ->and($beat['user'])->toBeString()->not->toBe('')
        ->and($beat['uid'])->toBeInt()
        ->and(fileperms($this->base.'/'.CoreRunnerTick::HEARTBEAT_FILE) & 0777)->toBe(0664);
});

it('freshHeartbeat is null after 180 seconds', function () {
    (new CoreRunnerTick($this->base))->writeHeartbeat($this->now);

    expect(CoreRunnerTick::freshHeartbeat($this->base, $this->now + 181))->toBeNull()
        ->and(CoreRunnerTick::freshHeartbeat($this->base, $this->now + 179))->not->toBeNull()
        ->and(CoreRunnerTick::freshHeartbeat($this->base, $this->now - 181))->toBeNull();

    file_put_contents($this->base.'/'.CoreRunnerTick::HEARTBEAT_FILE, '{"format":1,"at":"x"}');
    expect(CoreRunnerTick::freshHeartbeat($this->base, $this->now))->toBeNull();
});

it('preBoot answers run only with a request file', function () {
    $tick = new CoreRunnerTick($this->base);
    expect($tick->preBoot($this->now))->toBe('idle');

    CoreRunnerTick::writeRequest($this->base, 'upgrade', CRT_TASK, '2026.12.1', '/usr/bin/php8.3', $this->now);
    expect($tick->preBoot($this->now))->toBe('run');
});

it('request round trip and file modes', function () {
    CoreRunnerTick::writeRequest($this->base, 'upgrade', CRT_TASK, '2026.12.1', '/usr/bin/php8.3', $this->now);
    $tick = new CoreRunnerTick($this->base);

    expect($tick->readRequest())->toBe([
        'action' => 'upgrade', 'task_uuid' => CRT_TASK, 'version' => '2026.12.1',
        'web_php' => '/usr/bin/php8.3', 'requested_at' => date('c', $this->now),
    ])
        ->and(fileperms($this->base.'/'.CoreRunnerTick::REQUEST_FILE) & 0777)->toBe(0664)
        ->and(fileperms($this->base.'/'.CoreRunnerTick::DIR) & 0777)->toBe(0775)
        ->and(glob($this->base.'/'.CoreRunnerTick::DIR.'/*'))->toHaveCount(1);

    CoreRunnerTick::writeRequest($this->base, 'restore', CRT_TASK, null, '/usr/bin/php', $this->now);
    expect($tick->readRequest()['action'])->toBe('restore')
        ->and($tick->readRequest()['version'])->toBeNull();
});

it('invalid request files read as null', function (array|string $data) {
    crtWriteRawRequest($this->base, $data);
    $tick = new CoreRunnerTick($this->base);

    expect($tick->hasRequestFile())->toBeTrue()
        ->and($tick->readRequest())->toBeNull();
})->with(function () {
    $ok = ['format' => 1, 'action' => 'upgrade', 'task_uuid' => CRT_TASK, 'version' => '2026.12.1', 'web_php' => '/usr/bin/php', 'requested_at' => '2026-11-22T00:00:00+00:00'];

    return [
        'bad json' => ['{"format":1,'],
        'over 4096 bytes' => [['web_php' => str_repeat('p', 4100)] + $ok],
        'unknown action' => [['action' => 'delete'] + $ok],
        'version escapes' => [['version' => '../x'] + $ok],
        'version on restore' => [['action' => 'restore'] + $ok],
        'bad task uuid' => [['task_uuid' => 'not-a-uuid'] + $ok],
        'format 2' => [['format' => 2] + $ok],
    ];
});

it('removeRequest keeps another task\'s request', function () {
    CoreRunnerTick::writeRequest($this->base, 'upgrade', CRT_TASK, null, '/usr/bin/php', $this->now);
    $tick = new CoreRunnerTick($this->base);

    $tick->removeRequest('11111111-2222-3333-4444-555555555555');
    expect($tick->hasRequestFile())->toBeTrue();

    $tick->removeRequest(CRT_TASK);
    expect($tick->hasRequestFile())->toBeFalse();

    crtWriteRawRequest($this->base, 'garbage');
    $tick->removeRequest();
    expect($tick->hasRequestFile())->toBeFalse();
});

it('lock: acquire, held, release', function () {
    expect(CoreRunnerTick::lockHeld($this->base))->toBeFalse();
    (new CoreRunnerTick($this->base))->writeHeartbeat($this->now);

    $handle = CoreRunnerTick::acquireLock($this->base);
    expect($handle)->not->toBeNull()
        ->and(CoreRunnerTick::lockHeld($this->base))->toBeTrue()
        ->and(CoreRunnerTick::acquireLock($this->base))->toBeNull();

    CoreRunnerTick::releaseLock($handle);
    expect(CoreRunnerTick::lockHeld($this->base))->toBeFalse();

    $again = CoreRunnerTick::acquireLock($this->base);
    expect($again)->not->toBeNull();
    CoreRunnerTick::releaseLock($again);
});

it('lockHeld fails closed when the lock file cannot be opened', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('chmod does not stop root.');
    }
    (new CoreRunnerTick($this->base))->writeHeartbeat($this->now);
    $lock = $this->base.'/'.CoreRunnerTick::LOCK_FILE;
    touch($lock);
    chmod($lock, 0000);

    expect(CoreRunnerTick::lockHeld($this->base))->toBeTrue();
    chmod($lock, 0664);
});

it('refuses a symlinked lock file', function () {
    mkdir($this->base.'/'.CoreRunnerTick::DIR, 0775, true);
    touch($this->dir.'/elsewhere.lock');
    symlink($this->dir.'/elsewhere.lock', $this->base.'/'.CoreRunnerTick::LOCK_FILE);

    expect(CoreRunnerTick::acquireLock($this->base))->toBeNull();
});

it('cannot run and writes nothing through a symlinked runner folder', function () {
    mkdir($this->base.'/storage/app', 0775, true);
    mkdir($this->dir.'/outside', 0775, true);
    symlink($this->dir.'/outside', $this->base.'/'.CoreRunnerTick::DIR);

    expect((new CoreRunnerTick($this->base))->canRun())->toBeFalse()
        ->and((new CoreRunnerTick($this->base))->preBoot($this->now))->toBe('cannot_run')
        ->and(CoreRunnerTick::acquireLock($this->base))->toBeNull()
        ->and(fn () => (new CoreRunnerTick($this->base))->writeHeartbeat($this->now))->toThrow(RuntimeException::class)
        ->and(fn () => CoreRunnerTick::writeRequest($this->base, 'restore', CRT_TASK, null, PHP_VERSION, $this->now))->toThrow(RuntimeException::class)
        ->and(glob($this->dir.'/outside/*'))->toBe([]);
});

it('never runs as root: no heartbeat is written', function () {
    $tick = new CoreRunnerTick($this->base, 0);

    expect($tick->canRun())->toBeFalse()
        ->and($tick->preBoot())->toBe('cannot_run')
        ->and(is_file($this->base.'/'.CoreRunnerTick::HEARTBEAT_FILE))->toBeFalse()
        ->and((new CoreRunnerTick($this->base, 1000))->canRun())->toBeTrue();
});
