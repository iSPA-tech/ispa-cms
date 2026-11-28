<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\CoreUpgrade\CoreFileSwapper;
use Cms\Core\Services\CoreUpgrade\CoreHealthCheck;
use Cms\Core\Services\CoreUpgrade\CoreIntegrity;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CorePackageSource;
use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Support\Facades\Event;

// A CI-deployed release (releases/<n> behind `current`) whose core-manifest.json the deploy hook wrote with
// origin "deploy": `checked` holds only the release files whose path is in the public core build. rule
// (2026-11-26): an upgrade from the public core package writes only paths inside that package and never deletes or
// overwrites a file the package does not have; a file gone from the new package is removed only when the generated
// manifest proves it came from a core build. Every test works in a temp tree; nothing touches base_path().

/** Files of the release that the public core build also has (the hook hashes these into `checked`). */
const CDO_PROVEN = [
    'app/X.php' => 'x-v1',
    'app/Gone.php' => 'gone-v1',
    'cms/Core/module.json' => '{"name":"Core","version":"2026.11.09"}',
    'cms/Content/module.json' => '{"name":"Content","version":"2026.11.01"}',
    'VERSION' => "2026.11.05\n",
];

/** The public core package 2026.11.55 (checked set). */
const CDO_PACKAGE = [
    'app/X.php' => 'x-v2',
    'app/New.php' => 'new-v2',
    'cms/Core/module.json' => '{"name":"Core","version":"2026.11.10"}',
    'cms/Core/app/Added.php' => 'added-v2',
    'cms/Content/module.json' => '{"name":"Content","version":"2026.11.01"}',
    'VERSION' => "2026.11.55\n",
    // Paths the package has but the site owns: generated lang packs (the export filters them) and the composer file.
    'lang/vi/text.php' => 'public-filtered-lang',
    'cms/Core/lang/vi/text.php' => 'public-filtered-module-lang',
    'composer.json' => '{"public":true}',
    // A root file and a folder the release never had from a core build: not added (the site never owned them).
    'NOTICE' => 'notice',
    'admin/src/main.js' => 'admin-source',
];

/** Site files the generated manifest does not list: they must stay byte-identical. */
const CDO_SITE_ONLY = [
    'cms/Core/app/PrivateOnly.php' => 'private-only-core-file',
    'cms/Acme/module.json' => '{"name":"Acme","version":"1.0.0"}',
    'cms/Acme/app/Thing.php' => 'client-module',
    'projects/Acme/views/home.blade.php' => 'client-project',
    'lang/vi/text.php' => 'site-generated-lang-with-private-keys',
    'cms/Core/lang/vi/text.php' => 'site-generated-module-lang',
    'composer.json' => '{"private":true}',
    'composer.lock' => '{"packages":[{"name":"laravel/framework"},{"name":"acme/private"}]}',
    'vendor/acme/private/src/A.php' => 'private-vendor',
    'vendor/laravel/framework/x.php' => 'framework-site',
    'public/build/manifest.json' => 'site-theme-build',
    'runtime_modules.json' => '["Account"]',
];

function cdoRm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        cdoRm($entry->getPathname());
    }
    rmdir($path);
}

function cdoPut(string $root, string $rel, string $body): void
{
    @mkdir(dirname($root.'/'.$rel), 0755, true);
    file_put_contents($root.'/'.$rel, $body);
}

/** @return array<string, string> path => sha256 of every regular file under $root (links not followed). */
function cdoSnapshot(string $root, string $prefix): array
{
    $out = [];
    $dir = $root.'/'.$prefix;
    if (is_file($dir)) {
        return [$prefix => hash_file('sha256', $dir)];
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($out);

    return $out;
}

/** The CI release: releases/1 behind `current`, shared runtime/modules beside it, a deploy-origin manifest. */
function cdoRelease(string $dir): string
{
    $release = $dir.'/releases/1';
    foreach (CDO_PROVEN + CDO_SITE_ONLY as $rel => $body) {
        cdoPut($release, $rel, $body);
    }
    cdoPut($dir, 'runtime/modules/Account/module.json', '{"name":"Account","version":"2026.11.05"}');
    symlink('../../runtime/modules', $release.'/runtime-modules');
    cdoPut($dir, 'storage/x', 'storage-data');
    symlink('../../storage', $release.'/storage');
    symlink('releases/1', $dir.'/current');
    cdoPut($release, CoreManifest::FILE, json_encode([
        'format' => 1, 'version' => '2026.11.05', 'php_min' => '8.3', 'built_at' => '2026-11-26T00:00:00Z',
        'composer_lock_sha256' => hash_file('sha256', $release.'/composer.lock'), 'origin' => 'deploy',
        'checked' => array_map(static fn (string $b): string => hash('sha256', $b), CDO_PROVEN),
        'replaced' => [],
    ], JSON_UNESCAPED_SLASHES));

    return $release;
}

/**
 * The signed core package 2026.11.55. Returns [sigJson, zipPath, trustedKeys].
 *
 * @param array<string, string> $override Package files to replace (path => body).
 */
function cdoPackage(string $dir, array $override = [], string $lock = '{"packages":[{"name":"laravel/framework"}]}'): array
{
    $checked = array_merge(CDO_PACKAGE, $override);
    $inner = json_encode([
        'format' => 1, 'version' => '2026.11.55', 'php_min' => '8.3', 'built_at' => '2026-11-26T00:00:00Z',
        'composer_lock_sha256' => hash('sha256', $lock),
        'checked' => array_map(static fn (string $b): string => hash('sha256', $b), $checked),
        'replaced' => ['public/build', 'public/iPanel', 'vendor', 'composer.lock'],
    ], JSON_UNESCAPED_SLASHES);
    $files = $checked + [
        'composer.lock' => $lock, 'vendor/laravel/framework/x.php' => 'framework-public',
        'public/build/manifest.json' => 'default-theme-build', 'public/iPanel/index.html' => 'public-admin',
        CoreManifest::FILE => $inner,
    ];
    @mkdir($dir, 0755, true);
    $zipPath = $dir.'/core.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $unpacked = 0;
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $unpacked += strlen($body);
    }
    $zip->close();

    $secret = sodium_crypto_sign_secretkey($pair = sodium_crypto_sign_keypair());
    $public = sodium_crypto_sign_publickey($pair);
    $keyId = KeyId::fromPublicKey($public);
    $manifest = json_encode([
        'format' => 1, 'name' => 'ispa-cms', 'version' => '2026.11.55', 'zip_sha256' => hash_file('sha256', $zipPath),
        'zip_size' => filesize($zipPath), 'unpacked_size' => $unpacked, 'files' => [CoreManifest::FILE => hash('sha256', $inner)],
        'requires' => (object) [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-26T00:00:00Z', 'key_id' => $keyId, 'kind' => 'core',
    ], JSON_UNESCAPED_SLASHES);

    return [(new PackageSigner())->envelope($manifest, $secret), $zipPath, [$keyId => base64_encode($public)]];
}

/**
 * @param list<string> $failing Commands (without the php binary) the fake runner answers with exit 1.
 *
 * @return array{0: CoreUpgrader, 1: object} upgrader and the fake process runner
 */
function cdoUpgrader(string $release, array $pkg, ?CoreHealthCheck $health = null, array $failing = []): array
{
    $runner = new class($failing) extends ProcessRunner {
        /** @var list<string> */
        public array $commands = [];

        /** @param list<string> $failing */
        public function __construct(private array $failing)
        {
        }

        public function run(array $cmd, ?string $cwd, array $env = [], bool $quiet = false): int
        {
            $this->commands[] = $line = implode(' ', array_slice($cmd, 1));

            return in_array($line, $this->failing, true) ? 1 : 0;
        }
    };
    $health ??= new class extends CoreHealthCheck {
        public function check(): array
        {
            return [];
        }
    };
    $gate = new class extends BackupGate {
        public function __construct()
        {
        }

        public function ensure(string $reason, ?int $userId, ?\Cms\Core\Models\BackgroundTask $task = null): Backup
        {
            $backup = new Backup();
            $backup->uuid = 'backup-uuid-1';

            return $backup;
        }
    };
    $source = new class($pkg[0], $pkg[1]) extends CorePackageSource {
        public function __construct(private string $sig, private string $zip)
        {
        }

        public function ticket(string $version): array
        {
            return ['signature_json' => $this->sig, 'download' => fn ($manifest, string $target) => copy($this->zip, $target)];
        }
    };
    $preflight = new CoreUpgradePreflight($release, null, fn (): int => PHP_INT_MAX, fn (): ?array => ['at' => time(), 'user' => 'owner', 'uid' => 1000, 'php_version' => PHP_VERSION], fn (): bool => false, fn (): bool => false);

    return [new CoreUpgrader($release, $preflight, $source, new PackageVerifier($pkg[2], []), $gate, $runner, $health), $runner];
}

beforeEach(function () {
    Event::fake([AuditLogEvent::class]);
    $this->dir = sys_get_temp_dir().'/core-deploy-origin-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0755, true);
    $this->release = cdoRelease($this->dir);
    $this->pkg = cdoPackage($this->dir.'/package');
    config(['core.module_packages.core_package_key_ids' => [array_key_first($this->pkg[2])]]);
});

afterEach(function () {
    cdoRm($this->dir);
});

test('a deploy-origin manifest reads back and an unknown origin is refused', function () {
    $manifest = CoreManifest::installed($this->release);

    expect($manifest->origin)->toBe(CoreManifest::ORIGIN_DEPLOY)
        ->and($manifest->fromDeploy())->toBeTrue()
        ->and($manifest->upgradedFrom)->toBeNull();

    $data = json_decode((string) file_get_contents($this->release.'/'.CoreManifest::FILE), true);
    $data['origin'] = 'elsewhere';
    file_put_contents($this->release.'/'.CoreManifest::FILE, json_encode($data));
    expect(fn () => CoreManifest::installed($this->release))->toThrow(InvalidArgumentException::class, 'origin');
});

test('the plan writes only package paths the manifest proves or the site lacks, and swaps no built folder', function () {
    $installed = CoreManifest::installed($this->release);
    $new = new CoreManifest('2026.11.55', '8.3', array_map(static fn (string $b): string => hash('sha256', $b), CDO_PACKAGE),
        ['public/build', 'public/iPanel', 'vendor', 'composer.lock'], '');
    $staging = $this->dir.'/staging';
    foreach (CDO_PACKAGE as $rel => $body) {
        cdoPut($staging, $rel, $body);
    }
    cdoPut($staging, 'vendor/laravel/framework/x.php', 'framework-public');
    cdoPut($staging, 'public/build/manifest.json', 'default-theme-build');

    $plan = (new CoreFileSwapper($this->release))->plan($installed, $new, $staging, false);

    // VERSION keys the admin bundle and the i18n paths of the deploy's admin build: the deploy owns it, even when listed.
    expect($plan['write'])->toBe(['app/New.php', 'app/X.php', 'cms/Core/app/Added.php', 'cms/Core/module.json'])
        ->and($plan['remove'])->toBe(['app/Gone.php'])
        ->and($plan['replace'])->toBe([])
        ->and($plan['skipped'])->toContain('VERSION', 'lang/vi/text.php', 'cms/Core/lang/vi/text.php', 'composer.json', 'NOTICE', 'admin/src/main.js');
});

test('a deploy-origin release upgrades in place and site files stay byte-identical', function () {
    $siteOnly = [];
    foreach (['cms/Core/app/PrivateOnly.php', 'cms/Acme', 'projects/Acme', 'lang', 'cms/Core/lang', 'composer.json', 'composer.lock', 'vendor', 'public/build', 'runtime_modules.json'] as $path) {
        $siteOnly += cdoSnapshot($this->release, $path);
    }
    $runtime = cdoSnapshot($this->dir, 'runtime');
    [$up, $runner] = cdoUpgrader($this->release, $this->pkg);

    $up->run('2026.11.55', false);

    $after = [];
    foreach (['cms/Core/app/PrivateOnly.php', 'cms/Acme', 'projects/Acme', 'lang', 'cms/Core/lang', 'composer.json', 'composer.lock', 'vendor', 'public/build', 'runtime_modules.json'] as $path) {
        $after += cdoSnapshot($this->release, $path);
    }
    $manifest = CoreManifest::installed($this->release);

    expect($after)->toBe($siteOnly)
        ->and(cdoSnapshot($this->dir, 'runtime'))->toBe($runtime)
        // In place: no new release folder, `current` still points at the same release.
        ->and(glob($this->dir.'/releases/*'))->toBe([$this->release])
        ->and(realpath($this->dir.'/current'))->toBe(realpath($this->release))
        ->and(file_get_contents($this->release.'/app/X.php'))->toBe('x-v2')
        ->and(file_get_contents($this->release.'/app/New.php'))->toBe('new-v2')
        ->and(file_get_contents($this->release.'/cms/Core/app/Added.php'))->toBe('added-v2')
        ->and(file_exists($this->release.'/app/Gone.php'))->toBeFalse()
        ->and(file_exists($this->release.'/NOTICE'))->toBeFalse()
        ->and(file_exists($this->release.'/admin'))->toBeFalse()
        ->and(file_get_contents($this->release.'/VERSION'))->toBe("2026.11.05\n")
        // The placed manifest stays a deploy manifest: the next upgrade follows the same rules.
        ->and($manifest->version)->toBe('2026.11.55')
        ->and($manifest->origin)->toBe(CoreManifest::ORIGIN_DEPLOY)
        ->and($manifest->upgradedFrom)->toBe('2026.11.05')
        ->and($manifest->replaced)->toBe([])
        ->and($manifest->composerLockSha256)->toBe(hash_file('sha256', $this->release.'/composer.lock'))
        ->and(array_keys($manifest->checked))->toBe(['app/New.php', 'app/X.php', 'cms/Content/module.json', 'cms/Core/app/Added.php', 'cms/Core/module.json'])
        ->and((new CoreIntegrity())->modified($manifest, $this->release))->toBe([])
        // vendor/ belongs to the deploy: never composer here, caches rebuilt for the release.
        ->and(implode("\n", $runner->commands))->not->toContain('install')
        ->and(implode("\n", $runner->commands))->toContain('artisan migrate --force', 'artisan config:cache', 'artisan route:cache', 'artisan cms:translation-sync --all')
        // Views compiled by the runner during the health check belong to the code owner; the web user cannot touch()
        // them (the site answered 500), so they are cleared once the site is healthy.
        ->and(end($runner->commands))->toBe('artisan view:clear');
});

test('a deploy site gets the package admin source in its admin workspace, keeping its own admin files', function () {
    // The deploy built the admin from release/admin, copied it to runtime/admin-workspace/admin and removed it from
    // the release; every later rebuild reads that copy, so base-admin changes must land there.
    cdoPut($this->dir, 'runtime/admin-workspace/admin/src/main.js', 'admin-source-v1');
    cdoPut($this->dir, 'runtime/admin-workspace/admin/src/modules/game/game.js', 'private-game-page');
    cdoPut($this->dir, 'runtime/admin-workspace/admin/VERSION', "2026.11.05\n");
    $pkg = cdoPackage($this->dir.'/package-admin', [
        'admin/src/added.js' => 'added-admin-file',
        'admin/VERSION' => "2026.11.55\n",
        'admin/node_modules/left/index.js' => 'never-copied',
    ]);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up] = cdoUpgrader($this->release, $pkg);

    $up->run('2026.11.55', false);

    $workspace = $this->dir.'/runtime/admin-workspace/admin';
    expect(file_get_contents($workspace.'/src/main.js'))->toBe('admin-source')
        ->and(file_get_contents($workspace.'/src/added.js'))->toBe('added-admin-file')
        ->and(file_get_contents($workspace.'/src/modules/game/game.js'))->toBe('private-game-page')
        ->and(file_get_contents($workspace.'/VERSION'))->toBe("2026.11.05\n")
        ->and(file_exists($workspace.'/node_modules'))->toBeFalse()
        ->and(file_exists($this->release.'/admin'))->toBeFalse();
});

test('a failed health check leaves the admin workspace as the deploy left it', function () {
    cdoPut($this->dir, 'runtime/admin-workspace/admin/src/main.js', 'admin-source-v1');
    $health = new class extends CoreHealthCheck {
        public function check(): array
        {
            return ['/ answered 500'];
        }
    };
    [$up] = cdoUpgrader($this->release, $this->pkg, $health);

    expect(fn () => $up->run('2026.11.55', false))->toThrow(CoreUpgradeException::class)
        ->and(file_get_contents($this->dir.'/runtime/admin-workspace/admin/src/main.js'))->toBe('admin-source-v1');
});

test('the health check compiles views in a folder of its own, never in the folder the web server uses', function () {
    // The runner and the web server (www-data) cannot touch each other's compiled views, which Blade
    // does when a view's source is as new as its compiled copy: the in-process health check then answered 500 and
    // the update rolled back on a CI-deployed site. The check now compiles into a private temporary folder.
    $shared = config('view.compiled');
    $health = new class extends CoreHealthCheck {
        public ?string $seen = null;

        public bool $existed = false;

        public function check(): array
        {
            $this->seen = config('view.compiled');
            $this->existed = is_dir((string) $this->seen) && is_writable((string) $this->seen);
            file_put_contents($this->seen.'/compiled.php', 'x');

            return [];
        }
    };
    [$up] = cdoUpgrader($this->release, $this->pkg, $health);

    $up->run('2026.11.55', false);

    expect($health->seen)->not->toBe($shared)
        ->and($health->existed)->toBeTrue()
        ->and(config('view.compiled'))->toBe($shared)
        ->and(is_dir((string) $health->seen))->toBeFalse();
});

test('the health check keeps the view namespaces and directives the providers registered', function () {
    // Forgetting the view singletons to switch the compiled folder dropped every view namespace (the theme's
    // `default::` among them), so GET / answered 500 in the check and every update from the admin rolled back.
    $views = $this->dir.'/health-views';
    @mkdir($views, 0755, true);
    file_put_contents($views.'/hello.blade.php', '@cdoshout(hi) {{ $name }}');
    \Illuminate\Support\Facades\View::addNamespace('cdohealth', $views);
    \Illuminate\Support\Facades\Blade::directive('cdoshout', fn (string $e) => "<?php echo strtoupper('{$e}'); ?>");
    $shared = config('view.compiled');
    $health = new class extends CoreHealthCheck {
        public ?string $html = null;

        public ?string $compiled = null;

        public function check(): array
        {
            // Finder + blade engine, as a page render uses them, without the site's view composers.
            $path = app('view')->getFinder()->find('cdohealth::hello');
            $this->html = trim(app('view')->getEngineResolver()->resolve('blade')->get($path, ['name' => 'site']));
            $this->compiled = app('blade.compiler')->getCompiledPath($path);

            return [];
        }
    };
    [$up] = cdoUpgrader($this->release, $this->pkg, $health);

    $up->run('2026.11.55', false);

    expect($health->html)->toBe('HI site')
        ->and(str_starts_with((string) $health->compiled, (string) $shared))->toBeFalse()
        ->and(str_starts_with(app('blade.compiler')->getCompiledPath($views.'/hello.blade.php'), (string) $shared))->toBeTrue();
});

test('a package with an older Core than the release is refused before anything changes', function () {
    $pkg = cdoPackage($this->dir.'/older', ['cms/Core/module.json' => '{"name":"Core","version":"2026.11.08"}']);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    $before = cdoSnapshot($this->release, 'app');
    [$up] = cdoUpgrader($this->release, $pkg);

    expect(fn () => $up->run('2026.11.55', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_PACKAGE_OLDER'));
    expect(cdoSnapshot($this->release, 'app'))->toBe($before)
        ->and(CoreManifest::installed($this->release)->version)->toBe('2026.11.05');
});

test('a package that needs PHP packages the release lacks asks for a deploy', function () {
    $pkg = cdoPackage($this->dir.'/deps', [], '{"packages":[{"name":"laravel/framework"},{"name":"vendor/new-thing"}]}');
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up] = cdoUpgrader($this->release, $pkg);

    expect(fn () => $up->run('2026.11.55', false))->toThrow(fn (CoreUpgradeException $e) => expect($e->errorCode)->toBe('CORE_NEEDS_DEPLOY'));
    expect(file_get_contents($this->release.'/app/X.php'))->toBe('x-v1');
});

test('the admin preflight allows a deploy-origin release behind current and ignores module composer files', function () {
    cdoPut($this->release, 'cms/Acme/composer.json', '{"require":{"acme/private":"^1"}}');
    $preflight = new CoreUpgradePreflight($this->release, null, fn (): int => PHP_INT_MAX, fn (): ?array => ['at' => time(), 'user' => 'owner', 'uid' => fileowner($this->release), 'php_version' => PHP_VERSION], fn (): bool => false, fn (): bool => false);
    chmod($this->release, 0755);

    $codes = $preflight->check(CoreManifest::installed($this->release), '2026.11.55', true)->blockingCodes();

    expect($codes)->not->toContain('CORE_RELEASE_LAYOUT')
        ->and($codes)->not->toContain('CORE_CUSTOM_DEPS')
        ->and($codes)->toBe([]);
});

test('the runner may work on a deploy-origin release and its cron line goes through current', function () {
    chmod($this->release, 0755);

    expect((new CoreRunnerTick($this->release))->canRun())->toBeTrue()
        ->and(CoreUpgradeRequests::runnerLine($this->release))->toContain(escapeshellarg($this->dir.'/current'))
        ->and(CoreUpgradeRequests::setupFor($this->release, fileowner($this->release))['base'])->toBe($this->dir.'/current');

    // A core-package install behind `current` stays command-line only, as before.
    $data = json_decode((string) file_get_contents($this->release.'/'.CoreManifest::FILE), true);
    unset($data['origin']);
    file_put_contents($this->release.'/'.CoreManifest::FILE, json_encode($data));
    expect((new CoreRunnerTick($this->release))->canRun())->toBeFalse();
});

test('a deploy-origin update rebuilds the language packs after importing the new translations, as the deploy does', function () {
    // A CI-deployed site 2026-11-26: the update imported the translations but never ran cms:translation-generate, so the
    // admin packs (storage/app/public/i18n/<VERSION>/adm, lang/*) kept the old keys and the new texts showed raw keys.
    [$up, $runner] = cdoUpgrader($this->release, $this->pkg);

    $up->run('2026.11.55', false);

    $sync = array_search('artisan cms:translation-sync --all', $runner->commands, true);
    $generate = array_search('artisan cms:translation-generate', $runner->commands, true);
    expect($sync)->toBeInt()
        ->and($generate)->toBeInt()
        ->and($generate)->toBeGreaterThan($sync);
});

test('a failing language pack rebuild only warns', function () {
    [$up] = cdoUpgrader($this->release, $this->pkg, null, ['artisan cms:translation-generate']);

    $up->run('2026.11.55', false);

    expect(CoreManifest::installed($this->release)->version)->toBe('2026.11.55')
        ->and(implode("\n", $up->warnings()))->toContain('cms:translation-generate');
});

test('the admin translation sidecar the deploy proved is written by the update before the translations are imported', function () {
    // The deploy hook proves cms/{Core,Content,Widget}/database/seeders/sql/translate-admin-*.php (2026-11-26): the
    // update writes the package's sidecar like any proven core file, then cms:translation-sync imports it. The export
    // translate.php (rewritten at runtime by cms:translation-generate) and seeders/exported stay the site's.
    $sidecar = 'cms/Core/database/seeders/sql/translate-admin-authored.php';
    cdoPut($this->release, $sidecar, "<?php return ['site-build-sidecar-with-private-keys'];\n");
    cdoPut($this->release, 'cms/Core/database/seeders/sql/translate.php', "<?php return ['site-export'];\n");
    cdoPut($this->release, 'cms/Core/database/seeders/exported/x.php', "<?php return ['runtime-export'];\n");
    $data = json_decode((string) file_get_contents($this->release.'/'.CoreManifest::FILE), true);
    $data['checked'][$sidecar] = hash_file('sha256', $this->release.'/'.$sidecar);
    file_put_contents($this->release.'/'.CoreManifest::FILE, json_encode($data, JSON_UNESCAPED_SLASHES));
    $newSidecar = "<?php return ['public-sidecar-with-new-key'];\n";
    $pkg = cdoPackage($this->dir.'/sidecar', [$sidecar => $newSidecar, 'cms/Core/database/seeders/sql/translate.php' => "<?php return ['public-export'];\n"]);
    config(['core.module_packages.core_package_key_ids' => [array_key_first($pkg[2])]]);
    [$up, $runner] = cdoUpgrader($this->release, $pkg);

    $up->run('2026.11.55', false);
    $manifest = CoreManifest::installed($this->release);

    expect(file_get_contents($this->release.'/'.$sidecar))->toBe($newSidecar)
        ->and(file_get_contents($this->release.'/cms/Core/database/seeders/sql/translate.php'))->toBe("<?php return ['site-export'];\n")
        ->and(file_get_contents($this->release.'/cms/Core/database/seeders/exported/x.php'))->toBe("<?php return ['runtime-export'];\n")
        ->and($manifest->checked[$sidecar] ?? null)->toBe(hash('sha256', $newSidecar))
        ->and((new CoreIntegrity())->modified($manifest, $this->release))->toBe([])
        ->and($runner->commands)->toContain('artisan cms:translation-sync --all');
});
