<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\BackupGate;
use App\Support\Modules\RuntimeModules;
use App\Support\Modules\RuntimePrecedence;
use Cms\Core\Services\CoreUpgrade\CoreFileSwapper;
use Cms\Core\Services\CoreUpgrade\CoreHealthCheck;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CorePackageSource;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Support\Facades\Event;

// Safety: every test works in a temp tree with a fake signed package, fake runner, fake health and fake BackupGate.
// Nothing here touches base_path() of the repository.

const CU_OLD = [
    'app/X.php' => 'x-v1',
    'app/Gone.php' => 'gone-v1',
    'cms/Core/module.json' => '{}',
    'composer.json' => '{"v":1}',
    'config/setting.php' => 'setting-package-v1',
    'projects/Default/v.blade.php' => 'view-v1',
];
const CU_NEW = [
    'app/X.php' => 'x-v2',
    'app/New.php' => 'new-v2',
    'cms/Core/module.json' => '{}',
    'composer.json' => '{"v":2}',
    'config/setting.php' => 'setting-package-v2',
    'projects/Default/v.blade.php' => 'view-v2',
];

function cuRm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        cuRm($entry->getPathname());
    }
    rmdir($path);
}

function cuPut(string $root, string $rel, string $body): void
{
    @mkdir(dirname($root.'/'.$rel), 0755, true);
    file_put_contents($root.'/'.$rel, $body);
}

function cuManifestJson(string $version, array $checked, string $lock): string
{
    return json_encode([
        'format' => 1, 'version' => $version, 'php_min' => '8.3', 'built_at' => '2026-11-18T00:00:00Z',
        'composer_lock_sha256' => hash('sha256', $lock),
        'checked' => array_map(static fn (string $b): string => hash('sha256', $b), $checked),
        'replaced' => ['public/build', 'public/iPanel', 'vendor', 'composer.lock'],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Build a site at $root (installed 2026.12.0).
 *
 * @param array<string, string> $extra More (or replaced) checked files of the installed package.
 */
function cuSite(string $root, array $extra = []): void
{
    $old = array_merge(CU_OLD, $extra);
    foreach ($old as $rel => $body) {
        cuPut($root, $rel, $body);
    }
    cuPut($root, 'composer.lock', 'lock-v1');
    cuPut($root, 'vendor/pkg/a.php', 'vendor-v1');
    cuPut($root, 'public/build/old.js', 'build-v1');
    cuPut($root, 'public/iPanel/index.html', 'admin-v1');
    cuPut($root, 'public/iPanel/config.js', 'SITE-CONFIG');
    cuPut($root, '.env', 'APP_KEY=secret');
    cuPut($root, 'storage/x', 'storage-data');
    cuPut($root, 'runtime/x', 'runtime-data');
    cuPut($root, 'projects/MySite/x', 'my-project');
    cuPut($root, 'cms/MyModule/x', 'my-module');
    cuPut($root, CoreManifest::FILE, cuManifestJson('2026.12.0', $old, 'lock-v1'));
}

/**
 * Build the signed 2026.12.1 package. Returns [sigJson, zipPath, trustedKeys].
 *
 * @param array<string, string> $extraZip Extra raw zip entries (name => body), for the unsafe-path test.
 */
function cuPackage(string $dir, array $extraZip = [], array $extra = []): array
{
    $checked = array_merge(CU_NEW, $extra);
    $files = $checked + [
        'composer.lock' => 'lock-v2', 'vendor/pkg/a.php' => 'vendor-v2',
        'public/build/new.js' => 'build-v2', 'public/iPanel/index.html' => 'admin-v2',
    ];
    $inner = cuManifestJson('2026.12.1', $checked, 'lock-v2');
    $files[CoreManifest::FILE] = $inner;
    $zipPath = $dir.'/core.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $unpacked = 0;
    foreach ($files + $extraZip as $name => $body) {
        $zip->addFromString($name, $body);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $unpacked += strlen($body);
    }
    $zip->close();

    $secret = sodium_crypto_sign_secretkey($pair = sodium_crypto_sign_keypair());
    $public = sodium_crypto_sign_publickey($pair);
    $keyId = KeyId::fromPublicKey($public);
    $manifest = json_encode([
        'format' => 1, 'name' => 'ispa-cms', 'version' => '2026.12.1', 'zip_sha256' => hash_file('sha256', $zipPath),
        'zip_size' => filesize($zipPath), 'unpacked_size' => $unpacked, 'files' => [CoreManifest::FILE => hash('sha256', $inner)],
        'requires' => (object) [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-18T00:00:00Z', 'key_id' => $keyId, 'kind' => 'core',
    ], JSON_UNESCAPED_SLASHES);

    return [(new PackageSigner())->envelope($manifest, $secret), $zipPath, [$keyId => base64_encode($public)]];
}

function cuRunner(int $nodeExit = 0, int $updateExit = 0): object
{
    return new class($nodeExit, $updateExit) extends ProcessRunner {
        /** @var list<list<string>> */
        public array $commands = [];

        /** @var list<?string> */
        public array $cwds = [];

        /** @var list<bool> Whether the site's own module composer.json was in the cwd when `composer update` ran. */
        public array $moduleJsonSeen = [];

        public function __construct(private int $nodeExit, private int $updateExit)
        {
        }

        public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
        {
            $this->commands[] = $cmd;
            $this->cwds[] = $cwd;
            if ($cmd[0] === 'composer' && $cmd[1] === 'update') {
                $this->moduleJsonSeen[] = is_file($cwd.'/cms/MyModule/composer.json');

                return $this->updateExit;
            }

            return $cmd[0] === 'node' ? $this->nodeExit : 0;
        }

        public function joined(): string
        {
            return implode("\n", array_map(static fn (array $c): string => implode(' ', array_slice($c, 1)), $this->commands));
        }
    };
}

function cuHealth(array $failures = []): object
{
    return new class($failures) extends CoreHealthCheck {
        public int $calls = 0;

        public function __construct(private array $failures)
        {
        }

        public function check(): array
        {
            $this->calls++;

            return $this->failures;
        }
    };
}

function cuGate(): object
{
    return new class extends BackupGate {
        public int $calls = 0;

        public function __construct()
        {
        }

        public function ensure(string $reason, ?int $userId, ?\Cms\Core\Models\BackgroundTask $task = null): Backup
        {
            expect($reason)->toBe(Backup::REASON_PRE_CORE_UPGRADE);
            $this->calls++;

            $backup = new Backup();
            $backup->uuid = 'backup-uuid-1';

            return $backup;
        }

        public function prepared(string $uuid, string $reason, int $maxAgeSeconds = 86400): ?Backup
        {
            if ($uuid !== 'prepared-ok') {
                return null;
            }
            $backup = new Backup();
            $backup->uuid = $uuid;

            return $backup;
        }
    };
}

function cuSource(string $sig, string $zip): object
{
    return new class($sig, $zip) extends CorePackageSource {
        public int $tickets = 0;

        public function __construct(private string $sig, private string $zip)
        {
        }

        public function ticket(string $version): array
        {
            $this->tickets++;

            return ['signature_json' => $this->sig, 'download' => fn ($manifest, string $target) => copy($this->zip, $target)];
        }
    };
}

/** @return array{0: CoreUpgrader, 1: object, 2: object, 3: object, 4: object} upgrader, runner, health, gate, source */
function cuUpgrader(string $tree, array $pkg, ?object $runner = null, ?object $health = null, bool $freeSpace = true): array
{
    $runner ??= cuRunner();
    $health ??= cuHealth();
    $gate = cuGate();
    $source = cuSource($pkg[0], $pkg[1]);
    $preflight = new CoreUpgradePreflight($tree, null, fn (): int => $freeSpace ? PHP_INT_MAX : 10, fn (): ?array => ['at' => time(), 'user' => 'owner', 'uid' => 1000, 'php_version' => PHP_VERSION], fn (): bool => false);

    return [new CoreUpgrader($tree, $preflight, $source, new PackageVerifier($pkg[2], []), $gate, $runner, $health), $runner, $health, $gate, $source];
}

/** Recorder that keeps what the engine records in memory and can run a hook at a given progress step. */
function cuRecorder(?Closure $at55 = null): object
{
    return new class($at55) extends \Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $fails = [];

        public function __construct(private ?Closure $at55)
        {
        }

        public function progress(\Cms\Core\Models\BackgroundTask $task, int $percent, ?string $message = null, array $params = []): \Cms\Core\Models\BackgroundTask
        {
            if ($percent === 55 && $this->at55 !== null) {
                ($this->at55)();
            }

            return $task;
        }

        public function succeed(\Cms\Core\Models\BackgroundTask $task, array $result = []): \Cms\Core\Models\BackgroundTask
        {
            return $task;
        }

        public function fail(\Cms\Core\Models\BackgroundTask $task, string $message, array $params = []): \Cms\Core\Models\BackgroundTask
        {
            $this->fails[] = [$message, $params];

            return $task;
        }
    };
}

/** The upgrader of cuUpgrader() with a recorder. */
function cuUpgraderWithRecorder(string $tree, array $pkg, object $recorder, ?object $health = null): CoreUpgrader
{
    $preflight = new CoreUpgradePreflight($tree, null, fn (): int => PHP_INT_MAX, fn (): ?array => null, fn (): bool => false);

    return new CoreUpgrader($tree, $preflight, cuSource($pkg[0], $pkg[1]), new PackageVerifier($pkg[2], []), cuGate(), cuRunner(), $health ?? cuHealth(), $recorder);
}

/** A ustar archive holding one regular file with a raw (possibly unsafe) name, or a link entry when $type is '2'. */
function cuRawTar(string $path, string $name, string $body, string $type = '0'): void
{
    $header = str_pad($name, 100, "\0").str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
        .str_pad(sprintf('%011o', strlen($body)), 12, "\0").str_pad(sprintf('%011o', 0), 12, "\0").'        '.$type
        .str_pad($type === '2' ? '/etc/passwd' : '', 100, "\0")."ustar\0".'00'.str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($header[$i]);
    }
    $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    $data = $type === '0' ? str_pad($body, (int) (ceil(strlen($body) / 512) * 512), "\0") : '';
    // Private folders, as the engine creates them; otherwise restore refuses before the tar is even read.
    @mkdir(dirname($path), 0700, true);
    @chmod(dirname($path), 0700);
    @chmod(dirname($path, 2), 0700);
    @chmod(dirname($path, 3), 0700);
    file_put_contents($path, $header.$data.str_repeat("\0", 1024));
}

/** Leave the runner's private state as a killed flat upgrade would, with the given overrides. */
function cuState(string $tree, array $overrides): string
{
    $path = $tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE;
    @mkdir(dirname($path), 0700, true);
    @chmod(dirname($path), 0700);
    @unlink($path);
    file_put_contents($path, json_encode($overrides + [
        'from' => '2026.12.0', 'to' => '2026.12.1', 'layout' => 'flat', 'stamp' => '20261118000000', 'run' => null,
        'backup_uuid' => 'backup-uuid-1', 'added' => [], 'aside_planned' => [],
    ]));
    chmod($path, 0600);

    return $path;
}

function cuTarNames(string $tarPath): array
{
    $names = [];
    $tar = new PharData($tarPath);
    foreach (new RecursiveIteratorIterator($tar) as $file) {
        $names[substr($file->getPathname(), strpos($file->getPathname(), '.tar/') + 5)] = file_get_contents($file->getPathname());
    }

    return $names;
}

beforeEach(function () {
    Event::fake([AuditLogEvent::class]);
    $this->dir = sys_get_temp_dir().'/core-upgrade-'.bin2hex(random_bytes(6));
    mkdir($this->dir.'/package', 0755, true);
    $this->tree = $this->dir.'/site';
    mkdir($this->tree, 0755, true);
    cuSite($this->tree);
    $this->pkg = cuPackage($this->dir.'/package');
    config(['core.module_packages.core_package_key_ids' => [array_key_first($this->pkg[2])]]);
});

afterEach(function () {
    cuRm($this->dir);
});

test('flat upgrade replaces changed and removes left files', function () {
    [$up, $runner, $health, $gate] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2')
        ->and(file_get_contents($this->tree.'/app/New.php'))->toBe('new-v2')
        ->and(file_exists($this->tree.'/app/Gone.php'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v2')
        ->and(file_exists($this->tree.'/public/build/old.js'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/public/build/new.js'))->toBe('build-v2')
        ->and(file_get_contents($this->tree.'/public/iPanel/index.html'))->toBe('admin-v2')
        ->and(file_get_contents($this->tree.'/public/iPanel/config.js'))->toBe('SITE-CONFIG')
        ->and(file_get_contents($this->tree.'/composer.lock'))->toBe('lock-v2')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.1')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse()
        ->and(glob($this->tree.'/{vendor,public/build,public/iPanel}.old-*', GLOB_BRACE))->toBe([])
        ->and(is_dir($this->tree.'/.ispa-runner/upgrade-staging/2026.12.1'))->toBeFalse()
        ->and($gate->calls)->toBe(1)
        ->and($health->calls)->toBe(1)
        ->and($runner->joined())->toContain('migrate --force')->toContain('cms:modules-sync-permissions')->toContain('optimize:clear')->toContain('cms:admin-config');

    $tar = cuTarNames($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar');
    expect($tar['app/X.php'])->toBe('x-v1')->and($tar['app/Gone.php'])->toBe('gone-v1');
    Event::assertDispatched(AuditLogEvent::class, fn ($e) => $e->action === 'core.upgraded');
});

test('never touched paths survive', function () {
    [$up] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    expect(file_get_contents($this->tree.'/.env'))->toBe('APP_KEY=secret')
        ->and(file_get_contents($this->tree.'/storage/x'))->toBe('storage-data')
        ->and(file_get_contents($this->tree.'/runtime/x'))->toBe('runtime-data')
        ->and(file_get_contents($this->tree.'/projects/MySite/x'))->toBe('my-project')
        ->and(file_get_contents($this->tree.'/cms/MyModule/x'))->toBe('my-module')
        // Listed in both manifests, but a per-site file: never overwritten.
        ->and(file_get_contents($this->tree.'/config/setting.php'))->toBe('setting-package-v1')
        ->and(file_get_contents($this->tree.'/public/iPanel/config.js'))->toBe('SITE-CONFIG');
    expect(CoreFileSwapper::isProtected('projects/Default/v.blade.php'))->toBeFalse()
        ->and(CoreFileSwapper::isProtected('projects/Other/v.blade.php'))->toBeTrue()
        ->and(CoreFileSwapper::isProtected('storage/app/x'))->toBeTrue()
        ->and(CoreFileSwapper::isProtected('bootstrap/cache/packages.php'))->toBeTrue();
});

test('modified core stops with the list', function () {
    file_put_contents($this->tree.'/app/X.php', 'edited by the site');
    [$up, , , $gate, $source] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade should have stopped.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_MODIFIED');
    }
    $result = $up->preflight('2026.12.1');

    expect($result->blockingCodes())->toContain('CORE_MODIFIED')
        ->and($result->blocking[0]['details']['files'])->toBe(['app/X.php'])
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('edited by the site')
        ->and($gate->calls)->toBe(0)
        ->and($source->tickets)->toBe(0);
});

test('overwrite modified backs them up then proceeds', function () {
    file_put_contents($this->tree.'/app/X.php', 'edited by the site');
    [$up] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', true);

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2');
    $tar = cuTarNames($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar');
    expect($tar['app/X.php'])->toBe('edited by the site');
});

function cuOwnPackage(string $tree): void
{
    cuPut($tree, 'cms/MyModule/composer.json', json_encode(['require' => ['php' => '^8.3', 'acme/extra' => '^1.0']]));
    file_put_contents($tree.'/composer.lock', 'lock-with-own-packages');
}

test('custom dependencies resolve own packages then install instead of swapping vendor', function () {
    cuOwnPackage($this->tree);
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg);

    expect($up->preflight('2026.12.1')->warningCodes())->toBe(['CORE_CUSTOM_DEPS'])
        ->and($up->preflight('2026.12.1', true)->blockingCodes())->toContain('CORE_CUSTOM_DEPS');

    $up->run('2026.12.1', false);

    $staging = $this->tree.'/.ispa-runner/upgrade-staging/2026.12.1/tree';
    expect($runner->commands[0])->toBe(['composer', 'update', 'acme/extra', '--with-all-dependencies', '--no-dev', '--no-install', '--no-interaction'])
        ->and($runner->cwds[0])->toBe($staging)
        ->and($runner->moduleJsonSeen)->toBe([true])
        ->and($runner->commands)->toContain(['composer', 'install', '--no-dev', '--no-interaction'])
        ->and($runner->cwds[array_search(['composer', 'install', '--no-dev', '--no-interaction'], $runner->commands, true)])->toBe($this->tree)
        ->and($runner->joined())->not->toContain('--optimize-autoloader')
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2')
        ->and(file_get_contents($this->tree.'/composer.json'))->toBe('{"v":2}');
});

test('a failing partial update stops before any file changes', function () {
    cuOwnPackage($this->tree);
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg, cuRunner(0, 2));

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade should have stopped.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_CUSTOM_DEPS_CONFLICT')->and($e->getMessage())->toContain('acme/extra');
    }

    expect($runner->commands)->toHaveCount(1)
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_get_contents($this->tree.'/composer.lock'))->toBe('lock-with-own-packages')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse();
});

test('lock hash differing without own packages skips the partial update', function () {
    file_put_contents($this->tree.'/composer.lock', 'lock-edited-by-hand');
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    expect(array_column($runner->commands, 1))->not->toContain('update')
        ->and($runner->commands)->toContain(['composer', 'install', '--no-dev', '--no-interaction'])
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1');
});

test('release layout switches the current symlink', function () {
    cuRm($this->tree);
    $releases = $this->dir.'/releases';
    mkdir($releases, 0755, true);
    $old = $releases.'/1';
    mkdir($old);
    cuSite($old);
    symlink($old, $this->dir.'/current');
    [$up, , $health] = cuUpgrader($old, $this->pkg);

    $up->run('2026.12.1', false);

    $new = $releases.'/1-core-2026.12.1';
    expect(realpath($this->dir.'/current'))->toBe(realpath($new))
        ->and(file_get_contents($new.'/app/X.php'))->toBe('x-v2')
        ->and(file_get_contents($new.'/vendor/pkg/a.php'))->toBe('vendor-v2')
        ->and(file_get_contents($new.'/public/iPanel/config.js'))->toBe('SITE-CONFIG')
        ->and(CoreManifest::installed($new)->version)->toBe('2026.12.1')
        ->and(is_link($new.'/storage'))->toBeTrue()
        ->and(realpath($new.'/storage'))->toBe(realpath($old.'/storage'))
        // The old release is untouched: it is the backup.
        ->and(file_get_contents($old.'/app/X.php'))->toBe('x-v1')
        ->and(CoreManifest::installed($old)->version)->toBe('2026.12.0')
        ->and(file_exists($old.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse()
        ->and($health->calls)->toBe(1);
});

test('release layout health failure switches the symlink back', function () {
    cuRm($this->tree);
    $releases = $this->dir.'/releases';
    mkdir($releases, 0755, true);
    $old = $releases.'/1';
    mkdir($old);
    cuSite($old);
    symlink($old, $this->dir.'/current');
    [$up] = cuUpgrader($old, $this->pkg, null, cuHealth(['GET / answered HTTP 500']));

    expect(fn () => $up->run('2026.12.1', false))->toThrow(CoreUpgradeException::class);

    expect(realpath($this->dir.'/current'))->toBe(realpath($old))
        ->and(file_exists($releases.'/1-core-2026.12.1'))->toBeFalse()
        ->and(file_get_contents($old.'/app/X.php'))->toBe('x-v1');
});

test('health failure restores files and keeps db backup', function () {
    [$up, , $health, $gate] = cuUpgrader($this->tree, $this->pkg, null, cuHealth(['GET / answered HTTP 500']));

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade should have failed.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_HEALTH_FAILED')->and($e->backupUuid)->toBe('backup-uuid-1');
    }

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($this->tree.'/app/Gone.php'))->toBe('gone-v1')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_get_contents($this->tree.'/public/build/old.js'))->toBe('build-v1')
        ->and(file_exists($this->tree.'/public/build/new.js'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/public/iPanel/config.js'))->toBe('SITE-CONFIG')
        ->and(file_get_contents($this->tree.'/composer.lock'))->toBe('lock-v1')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse()
        ->and(glob($this->tree.'/{vendor,public/build,public/iPanel}.old-*', GLOB_BRACE))->toBe([])
        ->and($gate->calls)->toBe(1);
    Event::assertDispatched(AuditLogEvent::class, fn ($e) => $e->action === 'core.upgrade_failed');
});

test('interrupted upgrade blocks next run and restore recovers', function () {
    // Leave what a process killed during the swap leaves: the state file, the backup tar and a half-swapped tree.
    $installed = CoreManifest::installed($this->tree);
    $newTree = $this->dir.'/new-tree';
    foreach (CU_NEW + ['composer.lock' => 'lock-v2', 'vendor/pkg/a.php' => 'vendor-v2', 'public/build/new.js' => 'build-v2', 'public/iPanel/index.html' => 'admin-v2'] as $rel => $body) {
        cuPut($newTree, $rel, $body);
    }
    cuPut($newTree, CoreManifest::FILE, cuManifestJson('2026.12.1', CU_NEW, 'lock-v2'));
    $new = CoreManifest::fromFile($newTree.'/'.CoreManifest::FILE);
    $swapper = new CoreFileSwapper($this->tree);
    $plan = $swapper->plan($installed, $new, $newTree, false);
    $tar = $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar';
    $swapper->backup($plan, $tar);
    $added = array_values(array_filter($plan['write'], fn ($p) => ! is_file($this->tree.'/'.$p)));
    chmod($this->tree.'/.ispa-runner', 0700);
    cuState($this->tree, [
        'run' => '2026.12.0-2026.12.1', 'added' => $added,
        'aside_planned' => array_map(fn ($p) => ['path' => $p, 'old' => $p.'.old-20261118000000'], $plan['replace']),
    ]);
    // The web side's marker, as the engine writes it next to the private state.
    cuPut($this->tree, CoreUpgradePreflight::STATE_FILE, '{"format":1,"from":"2026.12.0","to":"2026.12.1","started_at":"x"}');
    $swapper->apply($plan, $newTree, '20261118000000'); // ... and the process dies right after this

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2')
        ->and(file_exists($this->tree.'/app/Gone.php'))->toBeFalse();

    [$up, , , $gate] = cuUpgrader($this->tree, $this->pkg);
    expect($up->preflight('2026.12.1')->blockingCodes())->toContain('CORE_UPGRADE_INTERRUPTED');
    try {
        $up->run('2026.12.1', false);
        $this->fail('A new run must refuse while the state file exists.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_UPGRADE_INTERRUPTED');
    }
    expect($gate->calls)->toBe(0)->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2');

    $up->restore();

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($this->tree.'/app/Gone.php'))->toBe('gone-v1')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_get_contents($this->tree.'/public/build/old.js'))->toBe('build-v1')
        ->and(file_exists($this->tree.'/public/build/new.js'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/composer.lock'))->toBe('lock-v1')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0')
        ->and(file_get_contents($this->tree.'/public/iPanel/config.js'))->toBe('SITE-CONFIG')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse()
        ->and($up->preflight('2026.12.1')->blockingCodes())->not->toContain('CORE_UPGRADE_INTERRUPTED');
    expect(fn () => $up->restore())->toThrow(CoreUpgradeException::class);
});

test('finish runs migrate config and rebuild when marker says so', function () {
    cuPut($this->tree, 'storage/app/ispa/custom-build.json', json_encode(['site' => true, 'admin' => true]));
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    expect($runner->joined())->toContain('migrate --force')->toContain('cms:admin-config')->toContain('cms:build --site --admin')
        ->and($up->warnings())->toBe([]);

    // Without Node the run still succeeds and tells the person to build.
    $this->tree = $this->dir.'/site2';
    mkdir($this->tree);
    cuSite($this->tree);
    cuPut($this->tree, 'storage/app/ispa/custom-build.json', json_encode(['site' => true, 'admin' => false]));
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg, cuRunner(127));
    $up->run('2026.12.1', false);
    expect($runner->joined())->not->toContain('cms:build')->and($up->warnings()[0])->toContain('./ispa build');

    // No marker: no build.
    $this->tree = $this->dir.'/site3';
    mkdir($this->tree);
    cuSite($this->tree);
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg);
    $up->run('2026.12.1', false);
    expect($runner->joined())->not->toContain('cms:build');
});

test('a release-zip update imports the new translations and writes the admin language packs of the new version', function () {
    // demo.ispa.tech 2026-11-27: the zip site updated itself to 2026.11.07, but only the deploy-origin update rebuilt the
    // packs, so storage/app/public/i18n/2026.11.07/adm did not exist, every pack request was a 404 and the admin showed
    // humanized keys ("Create", "Search", "Filter") wherever the bundled fallback had no text.
    [$up, $runner] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    $commands = array_map(static fn (array $c): string => implode(' ', array_slice($c, 1)), $runner->commands);
    $sync = array_search('artisan cms:translation-sync --all', $commands, true);
    $generate = array_search('artisan cms:translation-generate --public-only', $commands, true);
    expect($sync)->toBeInt()
        ->and($generate)->toBeInt()
        ->and($generate)->toBeGreaterThan($sync)
        // Only the packs: the lang files and module seeders are core files the next update checks for changes.
        ->and($commands)->not->toContain('artisan cms:translation-generate')
        ->and($up->warnings())->toBe([]);
});

test('a release-zip update that cannot write the language packs only warns', function () {
    $runner = new class extends ProcessRunner {
        /** @var list<list<string>> */
        public array $commands = [];

        public function __construct()
        {
        }

        public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
        {
            $this->commands[] = $cmd;

            return in_array('cms:translation-generate', $cmd, true) ? 1 : 0;
        }
    };
    [$up] = cuUpgrader($this->tree, $this->pkg, $runner);

    $up->run('2026.12.1', false);

    expect(CoreManifest::installed($this->tree)->version)->toBe('2026.12.1')
        ->and(implode("\n", $up->warnings()))->toContain('cms:translation-generate --public-only');
});

test('check prints versions and preflight only', function () {
    [$up, $runner, $health, $gate, $source] = cuUpgrader($this->tree, $this->pkg);
    app()->instance(CoreUpgrader::class, $up);

    $this->artisan('cms:core-upgrade', ['--check' => true, '--to' => '2026.12.1'])
        ->expectsOutputToContain('Installed core: 2026.12.0')
        ->expectsOutputToContain('Available core: 2026.12.1')
        ->expectsOutputToContain('Preflight passed')
        ->assertExitCode(0);

    file_put_contents($this->tree.'/app/X.php', 'edited');
    $this->artisan('cms:core-upgrade', ['--check' => true, '--to' => '2026.12.1'])
        ->expectsOutputToContain('CORE_MODIFIED')
        ->assertExitCode(1);

    expect($source->tickets)->toBe(0)->and($gate->calls)->toBe(0)->and($runner->commands)->toBe([])
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('edited')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse();
});

test('dry run downloads and verifies but changes nothing', function () {
    [$up, $runner, , $gate] = cuUpgrader($this->tree, $this->pkg);

    $report = $up->dryRun('2026.12.1');

    expect($report['write'])->toBe(4)->and($report['remove'])->toBe(1)->and($report['to'])->toBe('2026.12.1')
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(is_dir($this->tree.'/.ispa-runner/upgrade-staging/2026.12.1'))->toBeFalse()
        ->and($gate->calls)->toBe(0)->and($runner->commands)->toBe([]);
});

test('a safety backup the site took is reused instead of a new one', function () {
    [$up, , , $gate] = cuUpgrader($this->tree, $this->pkg);

    $up->usePreparedBackup('prepared-ok');
    $up->run('2026.12.1', false);

    expect($gate->calls)->toBe(0)
        ->and(file_exists($this->tree.'/app/New.php'))->toBeTrue();
});

test('a prepared backup that does not qualify is replaced by a new one', function () {
    [$up, , , $gate] = cuUpgrader($this->tree, $this->pkg);

    $up->usePreparedBackup('missing-or-failed');
    $up->run('2026.12.1', false);

    expect($gate->calls)->toBe(1);
});

test('the upgrade stops before any change when it cannot write the backup folders', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root can write any folder');
    }
    $locked = $this->dir.'/locked-backups';
    mkdir($locked, 0500);
    config(['core.backup.staging_path' => $locked.'/staging', 'core.backup.local_root' => $locked.'/local']);
    [$up, , , $gate] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade must stop.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_BACKUP_FAILED')
            ->and($e->getMessage())->toContain('setfacl');
    } finally {
        chmod($locked, 0700);
    }
    expect($gate->calls)->toBe(0)
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse();
});

test('upgrade to the installed version does nothing and reports the core is up to date', function () {
    $upgrader = new class($this->tree) extends CoreUpgrader {
        public int $runs = 0;

        public function run(string $targetVersion, bool $overwriteModified, ?\Cms\Core\Models\BackgroundTask $task = null, ?int $userId = null, array $extraPhpVersions = []): void
        {
            $this->runs++;
        }
    };
    app()->instance(CoreUpgrader::class, $upgrader);

    $this->artisan('cms:core-upgrade', ['--to' => '2026.12.0'])
        ->expectsOutputToContain('already on 2026.12.0')
        ->doesntExpectOutputToContain('Upgrading the core')
        ->assertExitCode(0);
    expect($upgrader->runs)->toBe(0);
});

test('git checkout guard refuses a real swap unless allowed', function () {
    mkdir($this->tree.'/.git');
    $upgrader = new class($this->tree) extends CoreUpgrader {
        public int $runs = 0;

        public function run(string $targetVersion, bool $overwriteModified, ?\Cms\Core\Models\BackgroundTask $task = null, ?int $userId = null, array $extraPhpVersions = []): void
        {
            $this->runs++;
        }

        public function restore(): void
        {
            $this->runs++;
        }
    };
    app()->instance(CoreUpgrader::class, $upgrader);

    $this->artisan('cms:core-upgrade', ['--to' => '2026.12.1'])
        ->expectsOutputToContain('git checkout')
        ->assertExitCode(1);
    $this->artisan('cms:core-upgrade', ['--restore' => true])->assertExitCode(1);
    expect($upgrader->runs)->toBe(0);

    $this->artisan('cms:core-upgrade', ['--to' => '2026.12.1', '--allow-git-checkout' => true])->assertExitCode(0);
    expect($upgrader->runs)->toBe(1);
});

test('unsafe zip entry is refused and the site is untouched', function () {
    $pkg = cuPackage($this->dir.'/package', ['../evil.php' => 'evil']);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up, , , $gate] = cuUpgrader($this->tree, $pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('The package must be refused.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_PACKAGE_ENTRY_PATH');
    }

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_exists($this->dir.'/evil.php'))->toBeFalse()
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse();
});

test('preflight reports low disk space and an unsigned package stops the run', function () {
    [$up] = cuUpgrader($this->tree, $this->pkg, null, null, false);
    expect($up->preflight('2026.12.1')->blockingCodes())->toContain('CORE_DISK');

    [$up] = cuUpgrader($this->tree, [$this->pkg[0], $this->pkg[1], []]);
    expect(fn () => $up->run('2026.12.1', false))->toThrow(CoreUpgradeException::class);
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');
});

test('a core package signed with a countersign key is refused', function () {
    config(['core.module_packages.countersign_key_ids' => [array_key_first($this->pkg[2])]]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('A core package signed with a countersign key must be refused.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_PACKAGE_KEY');
    }
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse();
});

test('run passes extra PHP versions to the preflight', function () {
    [$up, , , $gate, $source] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->run('2026.12.1', false, extraPhpVersions: ['5.6.0']);
        $this->fail('An old extra PHP version must block the upgrade.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_PHP');
    }
    expect($source->tickets)->toBe(0)->and($gate->calls)->toBe(0);
});

test('a failed upgrade records a translation key and the code on the task, not the raw message', function () {
    $recorder = cuRecorder();
    $up = cuUpgraderWithRecorder($this->tree, $this->pkg, $recorder, cuHealth(['GET / answered HTTP 500 at /var/www']));

    expect(fn () => $up->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask()))->toThrow(CoreUpgradeException::class);
    expect($recorder->fails)->toBe([['text.modules.core_update.result.failed', ['code' => 'CORE_HEALTH_FAILED']]]);
});

test('a symlinked state file is refused by restore', function () {
    $real = cuState($this->dir, ['added' => ['app/X.php']]);
    @mkdir($this->tree.'/.ispa-runner', 0700, true);
    symlink($real, $this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->restore();
        $this->fail('A symlinked state file must be refused.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_STATE_INVALID');
    }
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');
});

test('a state file naming a path outside the site is refused and nothing is deleted', function () {
    cuPut($this->dir, 'victim.txt', 'keep me');
    cuState($this->tree, ['added' => ['app/X.php', '../victim.txt']]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_get_contents($this->dir.'/victim.txt'))->toBe('keep me')
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');

    cuState($this->tree, ['aside_planned' => [['path' => 'vendor', 'old' => '/tmp/elsewhere']]]);
    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));

    cuState($this->tree, ['added' => ['.env']]);
    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_get_contents($this->tree.'/.env'))->toBe('APP_KEY=secret');
});

test('a backup run name that is not one plain segment is refused', function () {
    cuRawTar($this->tree.'/.ispa-runner/elsewhere/files.tar', 'app/X.php', 'evil');
    [$up] = cuUpgrader($this->tree, $this->pkg);

    foreach (['../elsewhere', 'a/b', '..', '/tmp', ''] as $run) {
        cuState($this->tree, ['run' => $run]);
        expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    }
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');
});

test('a backup tar with an unsafe or link entry is refused', function () {
    $tar = $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar';
    cuRawTar($tar, '../escaped.txt', 'evil');
    cuState($this->tree, ['run' => basename(dirname($tar))]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_exists($this->tree.'/.ispa-runner/upgrade-backups/escaped.txt'))->toBeFalse()
        ->and(file_exists($this->dir.'/escaped.txt'))->toBeFalse();

    cuRawTar($tar, 'app/link.php', '', '2');
    cuState($this->tree, ['run' => basename(dirname($tar))]);
    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_exists($this->tree.'/app/link.php') || is_link($this->tree.'/app/link.php'))->toBeFalse();
});

test('a safe backup tar is still restored', function () {
    $tar = $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar';
    cuRawTar($tar, 'app/X.php', 'x-from-tar');
    cuState($this->tree, ['run' => basename(dirname($tar))]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    $up->restore();

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-from-tar')
        ->and(file_exists($this->tree.'/storage/app/ispa/core-upgrade-state.json'))->toBeFalse();
});

test('writing the state file does not follow a planted temp symlink', function () {
    cuPut($this->dir, 'victim.txt', 'keep me');
    @mkdir($this->tree.'/storage/app/ispa', 0755, true);
    symlink($this->dir.'/victim.txt', $this->tree.'/storage/app/ispa/core-upgrade-state.json.tmp');
    [$up] = cuUpgrader($this->tree, $this->pkg);

    $up->run('2026.12.1', false);

    expect(file_get_contents($this->dir.'/victim.txt'))->toBe('keep me')
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v2');
});

test('a staged file changed after verification is refused and the old files stay', function () {
    $staged = $this->tree.'/.ispa-runner/upgrade-staging/2026.12.1/tree/app/X.php';
    $recorder = cuRecorder(function () use ($staged): void {
        file_put_contents($staged, '<?php evil();');
    });
    $up = cuUpgraderWithRecorder($this->tree, $this->pkg, $recorder);

    try {
        $up->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask());
        $this->fail('A tampered staged file must be refused.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_FILE_HASH');
    }
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse()
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0');
});

test('a core package signed with a trusted key that is not a core release key is refused', function () {
    config(['core.module_packages.core_package_key_ids' => ['1dc45f3eec76dd72']]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('A core package signed with a non-listed key must be refused.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_PACKAGE_KEY');
    }
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0');
});

/** The upgrader of cuUpgrader() whose download runs $after(stagingFolder) once the zip is written. */
function cuUpgraderAfterDownload(string $tree, array $pkg, Closure $after, ?object $recorder = null): CoreUpgrader
{
    $source = new class($pkg[0], $pkg[1], $after) extends CorePackageSource {
        public function __construct(private string $sig, private string $zip, private Closure $after)
        {
        }

        public function ticket(string $version): array
        {
            return ['signature_json' => $this->sig, 'download' => function ($manifest, string $target): void {
                copy($this->zip, $target);
                ($this->after)(dirname($target));
            }];
        }
    };
    $preflight = new CoreUpgradePreflight($tree, null, fn (): int => PHP_INT_MAX, fn (): ?array => null, fn (): bool => false);

    return new CoreUpgrader($tree, $preflight, $source, new PackageVerifier($pkg[2], []), cuGate(), cuRunner(), cuHealth(), $recorder);
}

function cuExpectUntouched(string $tree): void
{
    expect(file_get_contents($tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_exists($tree.'/app/New.php'))->toBeFalse()
        ->and(CoreManifest::installed($tree)->version)->toBe('2026.12.0');
}

test('a staging root other users can enter is refused before extraction', function () {
    $up = cuUpgraderAfterDownload($this->tree, $this->pkg, fn (string $staging) => chmod($staging, 0775));

    expect(fn () => $up->run('2026.12.1', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STAGING'));
    expect(is_dir($this->tree.'/.ispa-runner/upgrade-staging/2026.12.1/tree'))->toBeFalse();
    cuExpectUntouched($this->tree);
});

test('a symlinked staging root is refused before extraction', function () {
    $moved = $this->dir.'/moved-staging';
    $up = cuUpgraderAfterDownload($this->tree, $this->pkg, function (string $staging) use ($moved): void {
        rename($staging, $moved);
        symlink($moved, $staging);
    });

    expect(fn () => $up->run('2026.12.1', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STAGING'));
    expect(is_dir($moved.'/tree'))->toBeFalse();
    cuExpectUntouched($this->tree);
});

test('a staging root opened up after extraction is refused before the swap', function () {
    $staging = $this->tree.'/.ispa-runner/upgrade-staging/2026.12.1';
    $up = cuUpgraderWithRecorder($this->tree, $this->pkg, cuRecorder(fn () => chmod($staging, 0775)));

    expect(fn () => $up->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask()))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STAGING'));
    cuExpectUntouched($this->tree);
});

test('regression guard: staging and backups live under .ispa-runner and every folder there is 0700', function () {
    $staging = $this->tree.'/.ispa-runner/upgrade-staging/2026.12.1';
    $seen = null;
    $umask = umask(0);
    try {
        $up = cuUpgraderWithRecorder($this->tree, $this->pkg, cuRecorder(function () use ($staging, &$seen): void {
            $seen = fileperms($staging) & 0777;
        }));
        $up->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask());
    } finally {
        umask($umask);
    }

    expect($seen)->toBe(0700)
        ->and(fileperms($this->tree.'/.ispa-runner') & 0777)->toBe(0700)
        ->and(fileperms($this->tree.'/.ispa-runner/upgrade-staging') & 0777)->toBe(0700)
        ->and(fileperms($this->tree.'/.ispa-runner/upgrade-backups') & 0777)->toBe(0700)
        ->and(is_file($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar'))->toBeTrue()
        ->and(glob($this->tree.'/storage/app/ispa/upgrade-*'))->toBe([])
        ->and(fileperms($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1') & 0777)->toBe(0700);
});

test('a backup folder other users can enter is refused by restore', function () {
    $tar = $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar';
    cuRawTar($tar, 'app/X.php', 'x-from-tar');
    chmod(dirname($tar, 2), 0750); // others can enter (a 0775 folder is already refused as CORE_CODE_DIR_SHARED)
    cuState($this->tree, ['run' => basename(dirname($tar))]);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');
});

test('a failing replaced-folder check leaves every target file untouched', function () {
    $installed = CoreManifest::installed($this->tree);
    $newTree = $this->dir.'/new-tree';
    foreach (CU_NEW + ['composer.lock' => 'lock-v2', 'public/build/new.js' => 'build-v2', 'public/iPanel/index.html' => 'admin-v2'] as $rel => $body) {
        cuPut($newTree, $rel, $body);
    }
    // A replaced folder that is a link: the ownership/link check fails.
    mkdir($this->dir.'/elsewhere/pkg', 0755, true);
    file_put_contents($this->dir.'/elsewhere/pkg/a.php', 'evil');
    symlink($this->dir.'/elsewhere', $newTree.'/vendor');
    cuPut($newTree, CoreManifest::FILE, cuManifestJson('2026.12.1', CU_NEW, 'lock-v2'));
    $new = CoreManifest::fromFile($newTree.'/'.CoreManifest::FILE);
    $swapper = new CoreFileSwapper($this->tree);
    $plan = $swapper->plan($installed, $new, $newTree, false);
    expect($plan['replace'])->toContain('vendor')->and($plan['write'])->not->toBe([])->and($plan['remove'])->not->toBe([]);

    expect(fn () => $swapper->apply($plan, $newTree, '20261122000000', $new->checked))
        ->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STAGING'));
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(file_get_contents($this->tree.'/app/Gone.php'))->toBe('gone-v1')
        ->and(file_exists($this->tree.'/app/New.php'))->toBeFalse()
        ->and(file_get_contents($this->tree.'/vendor/pkg/a.php'))->toBe('vendor-v1')
        ->and(file_get_contents($this->tree.'/public/build/old.js'))->toBe('build-v1');
});

test('a group-writable code folder refuses with CORE_CODE_DIR_SHARED and changes nothing', function () {
    chmod($this->tree, 0775);
    [$up, , , $gate, $source] = cuUpgrader($this->tree, $this->pkg);

    try {
        expect(fn () => $up->run('2026.12.1', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_CODE_DIR_SHARED'));
        expect(fn () => $up->dryRun('2026.12.1'))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_CODE_DIR_SHARED'));
        cuState($this->tree, ['run' => null]);
        expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_CODE_DIR_SHARED'));
    } finally {
        chmod($this->tree, 0755);
    }
    expect($gate->calls)->toBe(0)
        ->and($source->tickets)->toBe(0)
        ->and(glob($this->tree.'/.ispa-runner/upgrade-*'))->toBe([])
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeTrue();
    cuExpectUntouched($this->tree);
});

test('a linked staging root under .ispa-runner is refused and nothing behind it is removed', function () {
    mkdir($this->dir.'/outside/2026.12.1', 0700, true);
    file_put_contents($this->dir.'/outside/2026.12.1/keep.txt', 'keep');
    mkdir($this->tree.'/.ispa-runner', 0700);
    symlink($this->dir.'/outside', $this->tree.'/.ispa-runner/upgrade-staging');
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->run('2026.12.1', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_CODE_DIR_SHARED'));
    expect(file_get_contents($this->dir.'/outside/2026.12.1/keep.txt'))->toBe('keep');
    cuExpectUntouched($this->tree);
});

test('.ispa-runner is a protected path the upgrade never touches', function () {
    expect(CoreFileSwapper::isProtected('.ispa-runner'))->toBeTrue()
        ->and(CoreFileSwapper::isProtected('.ispa-runner/upgrade-backups/x/files.tar'))->toBeTrue();
});

test('a web-writable marker with planted paths changes nothing a restore does', function () {
    // The real backup of the interrupted run, and an attacker's tar reachable through a link in storage/.
    $tar = $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1/files.tar';
    cuRawTar($tar, 'app/X.php', 'x-from-tar');
    cuRawTar($this->dir.'/evil/files.tar', 'app/X.php', 'evil');
    @mkdir($this->tree.'/storage/app/ispa', 0775, true);
    symlink($this->dir.'/evil', $this->tree.'/storage/app/ispa/L');
    $marker = $this->tree.'/'.CoreUpgradePreflight::STATE_FILE;
    $planted = json_encode([
        'from' => '2026.12.0', 'to' => '2026.12.1', 'layout' => 'flat', 'tar' => $this->tree.'/storage/app/ispa/L/files.tar',
        'run' => '../../storage/app/ispa/L', 'added' => ['app/X.php'], 'aside_planned' => [], 'staging' => $this->tree.'/app',
    ]);
    file_put_contents($marker, $planted);
    chmod($marker, 0666);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    // Only the marker: nothing to restore, nothing touched.
    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_NOTHING_TO_RESTORE'));
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and($up->pendingState())->toBeNull()
        // The lone marker is gone, so the admin stops offering a restore.
        ->and(file_exists($marker))->toBeFalse()
        ->and(file_get_contents($this->dir.'/evil/files.tar'))->toContain('evil');

    // With the private state: the literal backups path of its run is used; the marker and the link are never read.
    cuState($this->tree, ['run' => '2026.12.0-2026.12.1']);
    file_put_contents($marker, $planted);
    $up->restore();

    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-from-tar')
        ->and(file_get_contents($this->dir.'/evil/files.tar'))->toContain('evil')
        ->and(file_exists($marker))->toBeFalse()
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeFalse()
        ->and(is_dir($this->tree.'/app'))->toBeTrue();
});

test('a backup run folder that is a link is never followed', function () {
    cuRawTar($this->dir.'/evil/files.tar', 'app/X.php', 'evil');
    mkdir($this->tree.'/.ispa-runner/upgrade-backups', 0700, true);
    chmod($this->tree.'/.ispa-runner', 0700);
    symlink($this->dir.'/evil', $this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1');
    cuState($this->tree, ['run' => '2026.12.0-2026.12.1']);
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
    expect(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1');
});

test('the engine keeps the state private and gives the admin a marker without paths', function () {
    // The health check runs after the swap, while the state and the marker exist.
    $health = new class($this->tree) extends CoreHealthCheck {
        public array $seen = [];

        public function __construct(private string $tree)
        {
        }

        public function check(): array
        {
            $private = $this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE;
            $marker = $this->tree.'/'.CoreUpgradePreflight::STATE_FILE;
            $this->seen = [
                'private_mode' => fileperms($private) & 0777,
                'private' => json_decode((string) file_get_contents($private), true),
                'marker_mode' => fileperms($marker) & 0777,
                'marker' => json_decode((string) file_get_contents($marker), true),
            ];

            return [];
        }
    };
    $up = cuUpgraderWithRecorder($this->tree, $this->pkg, cuRecorder(), $health);
    $up->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask());

    expect($health->seen['private_mode'])->toBe(0600)
        ->and($health->seen['private']['run'])->toBe('2026.12.0-2026.12.1')
        ->and($health->seen['private'])->not->toHaveKey('tar')
        ->and($health->seen['marker_mode'])->toBe(0664)
        ->and(array_keys($health->seen['marker']))->toBe(['format', 'from', 'to', 'started_at'])
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::STATE_FILE))->toBeFalse()
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeFalse();
});

test('release keys of the state must name sibling releases and the current link', function () {
    cuRm($this->tree);
    $releases = $this->dir.'/releases';
    mkdir($releases, 0755, true);
    $old = $releases.'/1';
    mkdir($old);
    cuSite($old);
    symlink($old, $this->dir.'/current');
    mkdir($this->dir.'/elsewhere', 0755);
    [$up] = cuUpgrader($old, $this->pkg);
    $valid = ['layout' => 'release', 'previous_release' => $old, 'new_release' => $releases.'/1-core-2026.12.1', 'link' => $this->dir.'/current'];

    foreach ([
        ['previous_release' => $this->dir.'/elsewhere'],
        ['new_release' => $this->dir.'/elsewhere'],
        ['new_release' => $releases.'/..'],
        ['link' => $this->dir.'/elsewhere'],
    ] as $bad) {
        cuState($old, $bad + $valid);
        expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_STATE_INVALID'));
        expect(realpath($this->dir.'/current'))->toBe(realpath($old))
            ->and(is_dir($this->dir.'/elsewhere'))->toBeTrue();
    }

    cuState($old, $valid);
    $up->restore();
    expect(realpath($this->dir.'/current'))->toBe(realpath($old))
        ->and(file_exists($old.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeFalse();
});

test('a lone marker is cleared by a restore that finds nothing, and the admin no longer sees an interrupted upgrade', function () {
    $marker = $this->tree.'/'.CoreUpgradePreflight::STATE_FILE;
    cuPut($this->tree, CoreUpgradePreflight::STATE_FILE, '{"format":1,"from":"2026.12.0","to":"2026.12.1","started_at":"x"}');
    $admin = new CoreUpgradePreflight($this->tree, null, fn (): int => PHP_INT_MAX, fn (): ?array => ['at' => time(), 'user' => 'owner', 'uid' => posix_geteuid(), 'php_version' => PHP_VERSION], fn (): bool => false);
    expect($admin->interruptedForAdmin())->toBeTrue();
    [$up] = cuUpgrader($this->tree, $this->pkg);

    expect(fn () => $up->restore())->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_NOTHING_TO_RESTORE'));
    expect(file_exists($marker))->toBeFalse()
        ->and($admin->interruptedForAdmin())->toBeFalse();
});

test('a release upgrade killed after the link switch is restored from the new release', function () {
    cuRm($this->tree);
    $releases = $this->dir.'/releases';
    mkdir($releases, 0755, true);
    $old = $releases.'/1';
    mkdir($old);
    cuSite($old);
    symlink($old, $this->dir.'/current');
    $new = $releases.'/1-core-2026.12.1';
    $files = [
        'old' => $old.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE,
        'new' => $new.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE,
        'marker' => $old.'/'.CoreUpgradePreflight::STATE_FILE,
    ];
    // The health check runs after the switch: keep what a process killed right there leaves behind.
    $health = new class($files) extends CoreHealthCheck {
        public array $kept = [];

        public function __construct(private array $files)
        {
        }

        public function check(): array
        {
            foreach ($this->files as $key => $path) {
                $this->kept[$key] = is_file($path) ? (string) file_get_contents($path) : null;
            }

            return [];
        }
    };
    [$up] = cuUpgrader($old, $this->pkg, null, $health);
    $up->run('2026.12.1', false);
    expect($health->kept['old'])->not->toBeNull()
        ->and($health->kept['new'])->toBe($health->kept['old'])
        ->and($health->kept['marker'])->not->toBeNull()
        ->and(realpath($this->dir.'/current'))->toBe(realpath($new));
    foreach ($files as $key => $path) {
        file_put_contents($path, $health->kept[$key]);
        chmod($path, $key === 'marker' ? 0664 : 0600);
    }

    // `./ispa upgrade --restore` from `current`, which is now the new release.
    [$fromNew] = cuUpgrader($new, $this->pkg);
    expect($fromNew->pendingState())->not->toBeNull();
    $fromNew->restore();

    expect(realpath($this->dir.'/current'))->toBe(realpath($old))
        ->and(file_exists($new))->toBeFalse()
        ->and(file_exists($files['old']))->toBeFalse()
        ->and(file_exists($files['marker']))->toBeFalse()
        ->and(file_get_contents($old.'/app/X.php'))->toBe('x-v1')
        ->and(CoreManifest::installed($old)->version)->toBe('2026.12.0');
});

function cuModuleJson(string $name, string $version, array $more = []): string
{
    return json_encode(['name' => $name, 'version' => $version] + $more, JSON_UNESCAPED_SLASHES);
}

/**
 * A site whose package ships Core 2026.16.0 and Content 2026.11.7, with Content 2026.11.8 updated on its own
 * (`runtime-modules/.versions/Content-2026.11.8` + link, listed in runtime_modules.json) declaring $range as its Core
 * range. Returns the signed package carrying Core $newCore and Content $newContent plus $pkgExtra.
 */
function cuRuntimeContentSite(string $dir, string $tree, string $range, string $newCore, string $newContent = '2026.11.7', array $pkgExtra = []): array
{
    $provider = ['providers' => ['Cms\\Content\\Providers\\ContentServiceProvider']];
    cuSite($tree, [
        'cms/Core/module.json' => cuModuleJson('Core', '2026.16.0'),
        'cms/Content/module.json' => cuModuleJson('Content', '2026.11.7', $provider),
    ]);
    file_put_contents($tree.'/runtime_modules.json', json_encode(['Content']));
    $copy = $tree.'/runtime-modules/.versions/Content-2026.11.8';
    cuPut($copy, 'module.json', cuModuleJson('Content', '2026.11.8', $provider + ['files' => [], 'requires' => ['core' => $range]]));
    cuPut($copy, 'composer.json', json_encode(['autoload' => ['psr-4' => ['Cms\\Content\\' => 'app/']]]));
    cuPut($copy, 'app/Providers/ContentServiceProvider.php', "<?php\n// runtime copy\n");
    symlink('.versions/Content-2026.11.8', $tree.'/runtime-modules/Content');
    RuntimeModules::resetPath();
    RuntimePrecedence::forget();
    expect(RuntimePrecedence::decide($tree, 'Content')['runtime'])->toBeTrue();

    return cuPackage($dir.'/package', [], [
        'cms/Core/module.json' => cuModuleJson('Core', $newCore),
        'cms/Content/module.json' => cuModuleJson('Content', $newContent, $provider),
    ] + $pkgExtra);
}

test('an upgrade keeps a newer runtime Content and touches nothing under runtime-modules', function () {
    $pkg = cuRuntimeContentSite($this->dir, $this->tree, '>=2026.16.0,<2027.0.0', '2026.16.5', '2026.11.7', [
        'runtime-modules/from-package.txt' => 'package-owned?',
    ]);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    $before = file_get_contents($this->tree.'/runtime-modules/Content/module.json');
    [$up] = cuUpgrader($this->tree, $pkg);

    $up->run('2026.12.1', false);
    RuntimePrecedence::forget();

    expect(CoreManifest::installed($this->tree)->version)->toBe('2026.12.1')
        ->and(json_decode(file_get_contents($this->tree.'/cms/Core/module.json'), true)['version'])->toBe('2026.16.5')
        ->and(file_exists($this->tree.'/runtime-modules/from-package.txt'))->toBeFalse()
        ->and(is_link($this->tree.'/runtime-modules/Content'))->toBeTrue()
        ->and(file_get_contents($this->tree.'/runtime-modules/Content/module.json'))->toBe($before)
        ->and(file_get_contents($this->tree.'/runtime_modules.json'))->toBe('["Content"]')
        ->and(CoreFileSwapper::isProtected('runtime-modules/Content/module.json'))->toBeTrue()
        ->and(RuntimePrecedence::decide($this->tree, 'Content'))->toMatchArray(['runtime' => true, 'runtime_version' => '2026.11.8']);
});

test('CORE_MODULE_NEEDS_UPDATE when an updated module\'s core range rejects the new Core, before any file changes', function () {
    $pkg = cuRuntimeContentSite($this->dir, $this->tree, '>=2026.16.0,<2026.17.0', '2026.17.0');
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up, $runner, $health, $gate, $source] = cuUpgrader($this->tree, $pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade should have stopped.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_MODULE_NEEDS_UPDATE')
            ->and($e->details)->toBe(['modules' => ['Content']])
            ->and($e->getMessage())->toContain('Content');
    }

    // Downloaded and the DB backup kept, but no backup tar, no state file and no file changed.
    expect($source->tickets)->toBe(1)
        ->and($gate->calls)->toBe(1)
        ->and($health->calls)->toBe(0)
        ->and($runner->commands)->toBe([])
        ->and(is_dir($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1'))->toBeFalse()
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeFalse()
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::STATE_FILE))->toBeFalse()
        ->and(file_get_contents($this->tree.'/app/X.php'))->toBe('x-v1')
        ->and(json_decode(file_get_contents($this->tree.'/cms/Core/module.json'), true)['version'])->toBe('2026.16.0')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0')
        ->and(is_dir($this->tree.'/.ispa-runner/upgrade-staging/2026.12.1'))->toBeFalse();

    // The admin task keeps the translation key with the module names as a param.
    $recorder = cuRecorder();
    expect(fn () => cuUpgraderWithRecorder($this->tree, $pkg, $recorder)->run('2026.12.1', false, new \Cms\Core\Models\BackgroundTask()))
        ->toThrow(CoreUpgradeException::class);
    expect($recorder->fails)->toBe([['text.modules.core_update.needs_module_update', ['code' => 'CORE_MODULE_NEEDS_UPDATE', 'modules' => 'Content']]]);
});

test('CORE_MODULE_NEEDS_UPDATE when the shipped runtime_modules.json no longer lists a module that runs from its own copy', function () {
    // The range accepts the new Core, but the package's list would drop Content: boot would fall back to the older copy.
    $pkg = cuRuntimeContentSite($this->dir, $this->tree, '>=2026.16.0,<2027.0.0', '2026.16.5', '2026.11.7', [
        'runtime_modules.json' => '["Widget"]',
    ]);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up, , , $gate] = cuUpgrader($this->tree, $pkg);

    try {
        $up->run('2026.12.1', false);
        $this->fail('The upgrade should have stopped.');
    } catch (CoreUpgradeException $e) {
        expect($e->errorCode)->toBe('CORE_MODULE_NEEDS_UPDATE')
            ->and($e->details)->toBe(['modules' => ['Content']]);
    }

    expect($gate->calls)->toBe(1)
        ->and(is_dir($this->tree.'/.ispa-runner/upgrade-backups/2026.12.0-2026.12.1'))->toBeFalse()
        ->and(file_exists($this->tree.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE))->toBeFalse()
        ->and(file_get_contents($this->tree.'/runtime_modules.json'))->toBe('["Content"]')
        ->and(CoreManifest::installed($this->tree)->version)->toBe('2026.12.0');
});

test('a package that ships a Content at least as new as the runtime copy upgrades even when the old range rejects the new Core', function () {
    $pkg = cuRuntimeContentSite($this->dir, $this->tree, '>=2026.16.0,<2026.17.0', '2026.17.0', '2026.11.9');
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up] = cuUpgrader($this->tree, $pkg);

    $up->run('2026.12.1', false);
    RuntimePrecedence::forget();

    // The package's newer Content takes over: an upgrade, not a downgrade.
    expect(CoreManifest::installed($this->tree)->version)->toBe('2026.12.1')
        ->and(RuntimePrecedence::decide($this->tree, 'Content')['runtime'])->toBeFalse();
});
