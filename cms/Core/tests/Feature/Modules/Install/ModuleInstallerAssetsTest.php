<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\FrontendBuilder;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/**
 * the installer validates a package's prebuilt front-end assets and publishes them to
 * `<public assets>/<Name>/<version>/`; upgrade publishes before the switch and drops the old version only after
 * success. Everything runs in a temp folder with a throwaway signing key; status and artisan collaborators are fakes;
 * no request leaves the process.
 */
const AST_NAME = 'ProbeAssets';
const AST_URL = '/api/v2/admin/utilities/modules/upload';
const AST_ENTRY = 'resources/assets/js/app.js';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('', 500));

    $base = sys_get_temp_dir().'/install-assets-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->assetsDir = $this->tmp.'/public-assets';
    $this->releaseDir = $this->tmp.'/release';
    $this->cacheDir = $this->tmp.'/cache';
    foreach ([$this->runtimeDir, $this->assetsDir, $this->releaseDir, $this->cacheDir] as $dir) {
        mkdir($dir, 0755);
    }
    RuntimeModules::setPath($this->runtimeDir);
    RuntimeAssets::setPath($this->assetsDir);

    foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes-v7.php', 'APP_EVENTS_CACHE' => 'events.php'] as $var => $file) {
        putenv($var.'='.$this->cacheDir.'/'.$file);
        $_SERVER[$var] = $this->cacheDir.'/'.$file;
    }
    File::put($this->cacheDir.'/routes-v7.php', '<?php');

    $this->statusesFile = $this->tmp.'/statuses.json';
    file_put_contents($this->statusesFile, json_encode(['Core' => true]));
    config([
        'modules.activators.database.statuses-file' => $this->statusesFile,
        'modules.activators.database.cache_store' => 'array',
        'modules.activators.database.connection' => null,
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => 'test-secret',
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);
    DatabaseActivator::flushProcessState();

    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    $this->app->instance(PackageVerifier::class, new PackageVerifier([$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))], []));
    $this->app->instance(InstallPreflight::class, new InstallPreflight(new ModuleCatalog(null, $this->releaseDir), '8.3.0', fn (string $e): bool => true, fn (): array => [], fn (): array => [], fn (): array => [], fn (string $k): string => '0'));

    $this->backupUuid = (string) \Illuminate\Support\Str::uuid();
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => $this->backupUuid]));
    $this->app->instance(BackupGate::class, $gate);
    $this->app->instance(ModuleStatusService::class, astStatuses());
    $this->artisan = astArtisan();
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
});

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    RuntimeAssets::resetPath();
    DatabaseActivator::flushProcessState();
    @chmod($this->assetsDir, 0755);
    astRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function astRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            astRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * The prebuilt front-end files of a package (relative to `frontend-build/`), as the builder writes them.
 *
 * @return array<string, string>
 */
function astBuild(string $version): array
{
    $tag = str_replace('.', '', $version);

    return [
        'manifest.json' => json_encode([AST_ENTRY => [
            'file' => 'assets/app-'.$tag.'.js', 'name' => 'app', 'src' => AST_ENTRY, 'isEntry' => true, 'css' => ['assets/app-'.$tag.'.css'],
        ]], JSON_UNESCAPED_SLASHES),
        'assets/app-'.$tag.'.js' => 'console.log("probe '.$version.'");',
        'assets/app-'.$tag.'.css' => '.probe{color:red}',
    ];
}

/**
 * Module files of $name at $version; with $build, the prebuilt assets under `frontend-build/`.
 *
 * @param array<string, string>|null $build
 *
 * @return array<string, string>
 */
function astFiles(string $name, string $version, ?array $build): array
{
    $files = [
        'module.json' => json_encode(['name' => $name, 'version' => $version, 'requires' => [], 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']]),
        'composer.json' => json_encode(['name' => 'ispa/probe-assets', 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        'app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
    foreach ($build ?? [] as $path => $content) {
        $files['frontend-build/'.$path] = $content;
    }

    return $files;
}

/**
 * Zip and signature JSON of $name at $version; the manifest declares `frontend` when $build is given.
 *
 * @param array<string, string>|null $build
 *
 * @return array{0: string, 1: string}
 */
function astPackage(object $t, string $version, ?array $build, string $name = AST_NAME): array
{
    $files = astFiles($name, $version, $build);
    $zipPath = $t->tmp.'/build-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $listed = [];
    foreach ($files as $path => $content) {
        $zip->addFromString($name.'/'.$path, $content);
        $listed[$name.'/'.$path] = hash('sha256', $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $manifest = [
        'format' => 1, 'name' => $name, 'version' => $version,
        'zip_sha256' => hash('sha256', $bytes), 'zip_size' => strlen($bytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)), 'files' => $listed,
        'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-13T10:00:00Z', 'key_id' => $t->keyId,
    ];
    if ($build !== null) {
        $manifest['frontend'] = ['entries' => [AST_ENTRY], 'styles' => 'module', 'build' => 'frontend-build'];
    }

    return [$bytes, (new PackageSigner())->envelope(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $t->signKey)];
}

/**
 * Install $name at $version the way a first install leaves it, with its published asset folder when $build is given.
 *
 * @param array<string, string>|null $build
 */
function astInstallOld(object $t, string $name, string $version, ?array $build): void
{
    $dir = $t->runtimeDir.'/.versions/'.$name.'-'.$version;
    foreach (astFiles($name, $version, $build) as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
    symlink('.versions/'.$name.'-'.$version, $t->runtimeDir.'/'.$name);
    if ($build !== null) {
        RuntimeAssets::publish($name, $version, $dir.'/frontend-build');
    }
    $activator = new DatabaseActivator(app());
    $activator->setVersion($name, $version);
    $activator->setActiveByName($name, true);
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1; $hook runs on every call.
 *
 * @param list<string> $failing
 */
function astArtisan(array $failing = [], ?Closure $hook = null): ArtisanSubprocess
{
    return new class($failing, $hook) extends ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct(private readonly array $failing, private readonly ?Closure $hook)
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            $this->calls[] = $args;
            if ($this->hook !== null) {
                ($this->hook)($args);
            }
            $failed = in_array($args[0], $this->failing, true);

            return new FakeProcessResult(command: implode(' ', $args), exitCode: $failed ? 1 : 0, errorOutput: $failed ? 'boom' : '');
        }
    };
}

/**
 * Fake ModuleStatusService: writes only the test-database status row.
 */
function astStatuses(): ModuleStatusService
{
    return new class extends ModuleStatusService {
        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
            (new DatabaseActivator(app()))->setActiveByName($name, $enabled);
        }
    };
}

/**
 * Create a Webmaster admin.
 */
function astWebmaster(string $email): User
{
    $user = User::query()->create(['name' => 'Assets Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

    return $user->fresh();
}

/**
 * Upload a package through the endpoint and run the queued job.
 */
function astUploadAndRun(object $t, string $zip, string $sig): ModuleInstall
{
    $response = $t->post(AST_URL, [
        'package' => UploadedFile::fake()->createWithContent('p.zip', $zip),
        'signature' => UploadedFile::fake()->createWithContent('p.sig.json', $sig),
    ], ['Accept' => 'application/json']);
    $response->assertStatus(202);
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);

    return ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
}

/**
 * Relative paths of every file under $dir, sorted.
 *
 * @return list<string>
 */
function astTree(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $out[] = substr($file->getPathname(), strlen($dir) + 1);
    }
    sort($out);

    return $out;
}

/**
 * Entries (dot entries included) of a folder; empty when it does not exist.
 *
 * @return list<string>
 */
function astEntries(string $dir): array
{
    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

test('install publishes the prebuilt assets to the versioned folder with web-readable modes and audits the count', function () {
    Sanctum::actingAs(astWebmaster('ast-1@test.local'));
    [$zip, $sig] = astPackage($this, '1.0.0', astBuild('1.0.0'));

    $install = astUploadAndRun($this, $zip, $sig);

    $published = $this->assetsDir.'/'.AST_NAME.'/1.0.0';
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(astTree($published))->toBe(['assets/app-100.css', 'assets/app-100.js', 'manifest.json'])
        ->and(astEntries($this->assetsDir.'/'.AST_NAME))->toBe(['1.0.0'])
        ->and(file_get_contents($published.'/assets/app-100.js'))->toBe('console.log("probe 1.0.0");')
        ->and(fileperms($published) & 0777)->toBe(0755)
        ->and(fileperms($published.'/assets') & 0777)->toBe(0755)
        ->and(fileperms($this->assetsDir.'/'.AST_NAME) & 0777)->toBe(0755)
        ->and(fileperms($published.'/assets/app-100.js') & 0777)->toBe(0644)
        ->and(fileperms($published.'/manifest.json') & 0777)->toBe(0644)
        ->and(readlink($this->runtimeDir.'/'.AST_NAME))->toBe('.versions/'.AST_NAME.'-1.0.0');

    $audit = AuditLog::query()->where('action', 'action.module.installed')->sole();
    expect($audit->changed['asset_files'])->toBe(3)
        ->and(json_encode($audit->changed))->not->toContain('app-100')
        ->and(json_encode($audit->changed))->not->toContain('manifest.json');
});

test('a package whose frontend-build holds a disallowed file fails at verifying, before placement', function (string $file) {
    Sanctum::actingAs(astWebmaster('ast-2-'.md5($file).'@test.local'));
    [$zip, $sig] = astPackage($this, '1.0.0', astBuild('1.0.0') + [$file => '<?php echo 1;']);

    $install = astUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_RUNTIME_INVALID)
        ->and($install->step_message)->toContain('verifying')
        ->and(@lstat($this->runtimeDir.'/'.AST_NAME))->toBeFalse()
        ->and(astEntries($this->runtimeDir.'/.versions'))->toBe([])
        ->and(astEntries($this->assetsDir))->toBe([])
        ->and($this->artisan->calls)->toBe([]);
})->with(['assets/evil.php', 'index.html', 'assets/app.js.map']);

test('a publish failure (read-only target) fails the install and leaves no module folder and no asset folder', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores folder permissions');
    }
    Sanctum::actingAs(astWebmaster('ast-3@test.local'));
    [$zip, $sig] = astPackage($this, '1.0.0', astBuild('1.0.0'));
    chmod($this->assetsDir, 0555);

    $install = astUploadAndRun($this, $zip, $sig);
    chmod($this->assetsDir, 0755);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_ASSETS_PUBLISH)
        ->and($install->error_code)->toBe('MODULE_ASSETS_PUBLISH_FAILED')
        ->and(@lstat($this->runtimeDir.'/'.AST_NAME))->toBeFalse()
        ->and(astEntries($this->runtimeDir.'/.versions'))->toBe([])
        ->and(astEntries($this->assetsDir))->toBe([])
        ->and(DB::table('module_statuses')->where('name', AST_NAME)->exists())->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
});

test('upgrade keeps the old asset folder while the new one is published and removes it after success', function () {
    astInstallOld($this, AST_NAME, '1.0.0', astBuild('1.0.0'));
    $seen = [];
    $assets = $this->assetsDir.'/'.AST_NAME;
    $this->artisan = astArtisan([], function (array $args) use (&$seen, $assets): void {
        if ($args[0] === 'module:migrate') {
            $seen = astEntries($assets);
        }
    });
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    Sanctum::actingAs(astWebmaster('ast-4@test.local'));
    [$zip, $sig] = astPackage($this, '1.1.0', astBuild('1.1.0'));

    $install = astUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->kind)->toBe('upgrade')
        ->and($seen)->toBe(['1.0.0', '1.1.0'])
        ->and(astEntries($assets))->toBe(['1.1.0'])
        ->and(astTree($assets.'/1.1.0'))->toBe(['assets/app-110.css', 'assets/app-110.js', 'manifest.json'])
        ->and(AuditLog::query()->where('action', 'action.module.upgraded')->sole()->changed['asset_files'])->toBe(3);
});

test('a failed upgrade keeps the old asset folder and removes the new one', function () {
    astInstallOld($this, AST_NAME, '1.0.0', astBuild('1.0.0'));
    $this->artisan = astArtisan(['module:migrate']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    Sanctum::actingAs(astWebmaster('ast-5@test.local'));
    [$zip, $sig] = astPackage($this, '1.1.0', astBuild('1.1.0'));

    $install = astUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_MIGRATE)
        ->and(readlink($this->runtimeDir.'/'.AST_NAME))->toBe('.versions/'.AST_NAME.'-1.0.0')
        ->and(astEntries($this->assetsDir.'/'.AST_NAME))->toBe(['1.0.0'])
        ->and(astTree($this->assetsDir.'/'.AST_NAME.'/1.0.0'))->toBe(['assets/app-100.css', 'assets/app-100.js', 'manifest.json']);
});

test('an upgrade whose publish fails changes nothing: old code, old assets, same version', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores folder permissions');
    }
    astInstallOld($this, AST_NAME, '1.0.0', astBuild('1.0.0'));
    Sanctum::actingAs(astWebmaster('ast-6@test.local'));
    [$zip, $sig] = astPackage($this, '1.1.0', astBuild('1.1.0'));
    chmod($this->assetsDir.'/'.AST_NAME, 0555);

    $install = astUploadAndRun($this, $zip, $sig);
    chmod($this->assetsDir.'/'.AST_NAME, 0755);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_ASSETS_PUBLISH)
        ->and(readlink($this->runtimeDir.'/'.AST_NAME))->toBe('.versions/'.AST_NAME.'-1.0.0')
        ->and(astEntries($this->runtimeDir.'/.versions'))->toBe([AST_NAME.'-1.0.0'])
        ->and(astEntries($this->assetsDir.'/'.AST_NAME))->toBe(['1.0.0'])
        ->and(DB::table('module_statuses')->where('name', AST_NAME)->value('version'))->toBe('1.0.0')
        ->and($this->artisan->calls)->toBe([]);
});

test('an upgrade to a version without front-end assets drops the old asset folders after success', function () {
    astInstallOld($this, AST_NAME, '1.0.0', astBuild('1.0.0'));
    Sanctum::actingAs(astWebmaster('ast-7@test.local'));
    [$zip, $sig] = astPackage($this, '1.1.0', null);

    $install = astUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(astEntries($this->assetsDir))->toBe([])
        ->and(AuditLog::query()->where('action', 'action.module.upgraded')->sole()->changed)->not->toHaveKey('asset_files');
});

test('a package without frontend installs exactly as today and creates no asset folder', function () {
    Sanctum::actingAs(astWebmaster('ast-8@test.local'));
    [$zip, $sig] = astPackage($this, '1.0.0', null);

    $install = astUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->step_message)->toBe('Module installed and enabled.')
        ->and(astEntries($this->assetsDir))->toBe([])
        ->and(AuditLog::query()->where('action', 'action.module.installed')->sole()->changed)->not->toHaveKey('asset_files');
});

test('validate checks the frontend-build folder against the signed declaration', function () {
    $dir = $this->tmp.'/check/'.AST_NAME;
    $write = function (array $build) use ($dir): void {
        astRemoveTree($this->tmp.'/check');
        foreach (astFiles(AST_NAME, '1.0.0', $build === [] ? null : $build) as $path => $content) {
            @mkdir(dirname($dir.'/'.$path), 0755, true);
            file_put_contents($dir.'/'.$path, $content);
        }
    };
    $validate = fn (?array $entries): array => RuntimeModules::validate($dir, AST_NAME, $this->releaseDir, $entries);

    $write(astBuild('1.0.0'));
    expect($validate([AST_ENTRY]))->toBe([])
        ->and($validate(null))->toBe([])
        ->and($validate([]))->not->toBe([])
        ->and($validate(['resources/assets/js/other.js']))->not->toBe([]);

    $missing = astBuild('1.0.0');
    unset($missing['assets/app-100.css']);
    $write($missing);
    expect($validate([AST_ENTRY]))->not->toBe([]);

    $write(['manifest.json' => '[]'] + astBuild('1.0.0'));
    expect($validate([AST_ENTRY]))->not->toBe([]);

    $write(['manifest.json' => json_encode(['../escape.js' => ['file' => 'assets/app-100.js', 'isEntry' => true]])] + astBuild('1.0.0'));
    expect($validate([AST_ENTRY]))->not->toBe([]);

    $unlisted = astBuild('1.0.0');
    unset($unlisted['manifest.json']);
    $write($unlisted);
    expect($validate([AST_ENTRY]))->not->toBe([]);

    $write([]);
    expect($validate([]))->toBe([])
        ->and($validate([AST_ENTRY]))->not->toBe([]);

    $write(astBuild('1.0.0'));
    symlink('/etc/hosts', $dir.'/frontend-build/assets/link.js');
    expect($validate([AST_ENTRY]))->not->toBe([]);
});

test('the site allowlist equals the builder allowlist', function () {
    expect(RuntimeModules::ASSET_EXTENSIONS)->toBe(FrontendBuilder::ALLOWED_EXTENSIONS);
});

test('RuntimeAssets resolves a deployed link to the shared folder, a plain folder to itself, and refuses bad names', function () {
    $public = $this->tmp.'/release-public';
    mkdir($public, 0755);
    RuntimeAssets::resetPath();
    expect(RuntimeAssets::path($public))->toBeNull();

    mkdir($public.'/runtime-assets', 0755);
    expect(RuntimeAssets::path($public))->toBe(realpath($public.'/runtime-assets'));
    rmdir($public.'/runtime-assets');

    $shared = $this->tmp.'/runtime-shared/public-assets';
    mkdir($shared, 0755, true);
    symlink($shared, $public.'/runtime-assets');
    expect(RuntimeAssets::path($public))->toBe(realpath($shared))
        ->and(RuntimeAssets::buildDirectory('Journal', '1.2.0'))->toBe('runtime-assets/Journal/1.2.0')
        ->and(RuntimeAssets::url('Journal', '1.2.0'))->toEndWith('/runtime-assets/Journal/1.2.0');

    RuntimeAssets::setPath($this->assetsDir);
    $source = $this->tmp.'/src-build';
    mkdir($source, 0755);
    file_put_contents($source.'/manifest.json', '{}');
    expect(fn () => RuntimeAssets::publish('../Evil', '1.0.0', $source))->toThrow(PackageVerificationException::class)
        ->and(fn () => RuntimeAssets::publish(AST_NAME, '1.0', $source))->toThrow(PackageVerificationException::class)
        ->and(RuntimeAssets::remove('../Evil'))->toBeFalse()
        ->and(astEntries($this->assetsDir))->toBe([]);
});

test('a signed Game* package is refused at verifying even when the release ships no module of that name ( F1)', function () {
    Sanctum::actingAs(astWebmaster('ast-f1@test.local'));
    [$zip, $sig] = astPackage($this, '1.0.0', astBuild('1.0.0'), 'GameProbe');

    $install = astUploadAndRun($this, $zip, $sig);

    expect(is_dir(base_path('cms/GameProbe')))->toBeFalse()
        ->and($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_RUNTIME_INVALID)
        ->and($install->step_message)->toContain('verifying')
        ->and(@lstat($this->runtimeDir.'/GameProbe'))->toBeFalse()
        ->and(astEntries($this->runtimeDir.'/.versions'))->toBe([])
        ->and(astEntries($this->assetsDir))->toBe([])
        ->and(DB::table('module_statuses')->where('name', 'GameProbe')->exists())->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
});
