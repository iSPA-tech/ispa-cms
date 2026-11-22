<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Console\Commands\ModuleInstallCommandRunCommand;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
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
 * the installer runs a module's declared `install.artisan` commands after `cms:modules-sync` through the same
 * fresh-process runner, refuses commands outside `cms:<module-kebab>:` before anything is placed, handles a failing
 * command like a migrate failure, and re-checks the shared admin packages and theme coupling of the signed manifest at
 * the verifying step (hub installs have no manifest at preflight time). Temp folders, a throwaway signing key and fake
 * status and artisan collaborators; no request leaves the process.
 */
const ICT_NAME = 'ProbeCommands';
const ICT_URL = '/api/v2/admin/utilities/modules/upload';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response('', 500));

    $base = sys_get_temp_dir().'/install-commands-'.bin2hex(random_bytes(6));
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

    $this->shellFile = $this->tmp.'/shared-packages.json';
    file_put_contents($this->shellFile, json_encode(['format' => 1, 'packages' => ['vuedraggable' => ['major' => 4, 'used_by' => ['Widget']]]]));

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
        'core.module_packages.shared_admin_packages_file' => $this->shellFile,
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

    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid()]));
    $this->app->instance(BackupGate::class, $gate);
    $this->app->instance(ModuleStatusService::class, ictStatuses());
    $this->artisan = ictArtisan();
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
    ictRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function ictRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            ictRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Module files of ICT_NAME at $version; `install` is written into module.json when given (any JSON value).
 *
 * @return array<string, string>
 */
function ictFiles(string $version, mixed $install = null): array
{
    $module = ['name' => ICT_NAME, 'version' => $version, 'requires' => [], 'providers' => ['Cms\\'.ICT_NAME.'\\Providers\\'.ICT_NAME.'ServiceProvider']];
    if ($install !== null) {
        $module['install'] = $install;
    }

    return [
        'module.json' => json_encode($module),
        'composer.json' => json_encode(['name' => 'ispa/probe-commands', 'autoload' => ['psr-4' => ['Cms\\'.ICT_NAME.'\\' => 'app/']]]),
        'app/Providers/'.ICT_NAME.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".ICT_NAME."\\Providers;\n",
    ];
}

/**
 * Zip and signature JSON of ICT_NAME at $version; $extra adds optional manifest fields.
 *
 * @param array<string, mixed>  $extra      optional manifest fields
 * @param array<string, string> $extraFiles more module files (relative path => content)
 *
 * @return array{0: string, 1: string}
 */
function ictPackage(object $t, string $version, mixed $install = null, array $extra = [], array $extraFiles = []): array
{
    $files = ictFiles($version, $install) + $extraFiles;
    $zipPath = $t->tmp.'/build-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $listed = [];
    foreach ($files as $path => $content) {
        $zip->addFromString(ICT_NAME.'/'.$path, $content);
        $listed[ICT_NAME.'/'.$path] = hash('sha256', $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $manifest = [
        'format' => 1, 'name' => ICT_NAME, 'version' => $version,
        'zip_sha256' => hash('sha256', $bytes), 'zip_size' => strlen($bytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)), 'files' => $listed,
        'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-13T10:00:00Z', 'key_id' => $t->keyId,
    ] + $extra;

    return [$bytes, (new PackageSigner())->envelope(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $t->signKey)];
}

/**
 * Install ICT_NAME at $version the way a first install leaves it (enabled, version stored).
 */
function ictInstallOld(object $t, string $version, mixed $install = null): void
{
    $dir = $t->runtimeDir.'/.versions/'.ICT_NAME.'-'.$version;
    foreach (ictFiles($version, $install) as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
    symlink('.versions/'.ICT_NAME.'-'.$version, $t->runtimeDir.'/'.ICT_NAME);
    $activator = new DatabaseActivator(app());
    $activator->setVersion(ICT_NAME, $version);
    $activator->setActiveByName(ICT_NAME, true);
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1.
 *
 * @param list<string> $failing
 */
function ictArtisan(array $failing = []): ArtisanSubprocess
{
    return new class($failing) extends ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct(private readonly array $failing)
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            $this->calls[] = $args;
            $failed = in_array(ictEffective($args), $this->failing, true);

            return new FakeProcessResult(command: implode(' ', $args), exitCode: $failed ? 1 : 0, errorOutput: $failed ? 'boom' : '');
        }
    };
}

/**
 * Fake ModuleStatusService: writes only the test-database status row.
 */
function ictStatuses(): ModuleStatusService
{
    return new class extends ModuleStatusService {
        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
            (new DatabaseActivator(app()))->setActiveByName($name, $enabled);
        }
    };
}

/**
 * Sign in as a new Webmaster admin.
 */
function ictWebmaster(string $email): User
{
    $user = User::query()->create(['name' => 'Commands Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    Sanctum::actingAs($user->fresh());

    return $user->fresh();
}

/**
 * Upload a package through the endpoint (which runs the preflight) and return the queued row's uuid.
 */
function ictUpload(object $t, string $zip, string $sig): string
{
    $response = $t->post(ICT_URL, [
        'package' => UploadedFile::fake()->createWithContent('p.zip', $zip),
        'signature' => UploadedFile::fake()->createWithContent('p.sig.json', $sig),
    ], ['Accept' => 'application/json']);
    $response->assertStatus(202);

    return (string) $response->json('data.uuid');
}

/**
 * Run the queued install job of $uuid and return the row.
 */
function ictRun(string $uuid): ModuleInstall
{
    app()->call([new InstallModuleJob($uuid), 'handle']);

    return ModuleInstall::query()->where('uuid', $uuid)->firstOrFail();
}

/**
 * First argument of every recorded artisan call, in order.
 *
 * @return list<string>
 */
function ictCommands(object $t): array
{
    return array_map(ictEffective(...), $t->artisan->calls);
}

/**
 * The command an artisan call runs: the declared command for a call through the exact-name runner (F2),
 * else the first argument.
 *
 * @param array<int, string> $args
 */
function ictEffective(array $args): string
{
    return $args[0] === ModuleInstallCommandRunCommand::NAME ? (string) ($args[2] ?? '') : $args[0];
}

test('declared install commands run in order after cms:modules-sync through the subprocess runner', function () {
    ictWebmaster('ict-1@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', ['artisan' => ['cms:probe-commands:sync-permission', 'cms:probe-commands:seed-enums']]);

    $install = ictRun(ictUpload($this, $zip, $sig));

    $commands = ictCommands($this);
    $sync = array_search('cms:modules-sync', $commands, true);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(array_slice($commands, 0, 6))->toBe([
            'module:migrate', 'cms:modules-sync', 'cms:probe-commands:sync-permission', 'cms:probe-commands:seed-enums',
            'cms:translation-sync', 'cms:translation-generate',
        ])
        ->and($sync)->toBe(1)
        // F2: each declared command goes through the exact-name runner, never straight to artisan, which would
        // resolve an abbreviation to another command.
        ->and($this->artisan->calls[2])->toBe([ModuleInstallCommandRunCommand::NAME, ICT_NAME, 'cms:probe-commands:sync-permission'])
        ->and($this->artisan->calls[3])->toBe([ModuleInstallCommandRunCommand::NAME, ICT_NAME, 'cms:probe-commands:seed-enums'])
        ->and(collect($this->artisan->calls)->contains(fn (array $a): bool => $a[0] === 'cms:probe-commands:sync-permission'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'action.module.installed')->exists())->toBeTrue();
});

test('a module without install commands runs exactly the steps of today', function () {
    ictWebmaster('ict-2@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0');

    $install = ictRun(ictUpload($this, $zip, $sig));

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(array_slice(ictCommands($this), 0, 4))->toBe(['module:migrate', 'cms:modules-sync', 'cms:translation-sync', 'cms:translation-generate']);
});

test('a command outside the module namespace or with arguments is refused before anything is placed', function (mixed $install) {
    ictWebmaster('ict-3-'.md5(json_encode($install)).'@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', $install);

    $row = ictRun(ictUpload($this, $zip, $sig));

    expect($row->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($row->error_code)->toBe(ModuleInstaller::ERROR_RUNTIME_INVALID)
        ->and($row->step_message)->toContain('verifying')
        ->and(@lstat($this->runtimeDir.'/'.ICT_NAME))->toBeFalse()
        ->and(DB::table('module_statuses')->where('name', ICT_NAME)->exists())->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
})->with([
    'other module namespace' => [['artisan' => ['cms:other-module:sync']]],
    'core command' => [['artisan' => ['migrate:fresh']]],
    'arguments' => [['artisan' => ['cms:probe-commands:sync --force']]],
    'option glued' => [['artisan' => ['cms:probe-commands:sync=1']]],
    'bare prefix' => [['artisan' => ['cms:probe-commands:']]],
    'not a string' => [['artisan' => [['cms:probe-commands:sync']]]],
    'not a list' => [['artisan' => 'cms:probe-commands:sync']],
    'install is a list' => [['cms:probe-commands:sync']],
    'unknown install key' => [['artisan' => [], 'shell' => ['ls']]],
]);

test('a failing install command disables the module and writes module.install_failed, as a migrate failure does', function () {
    $this->artisan = ictArtisan(['cms:probe-commands:sync-permission']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    ictWebmaster('ict-4@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', ['artisan' => ['cms:probe-commands:sync-permission', 'cms:probe-commands:seed-enums']]);

    $install = ictRun(ictUpload($this, $zip, $sig));

    $audit = AuditLog::query()->where('action', 'action.module.install_failed')->sole();
    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_INSTALL_COMMAND)
        ->and($install->error_code)->toBe('MODULE_INSTALL_COMMAND_FAILED')
        ->and($install->step_message)->toContain('disabled again')
        ->and((bool) DB::table('module_statuses')->where('name', ICT_NAME)->value('enabled'))->toBeFalse()
        ->and(ictCommands($this))->not->toContain('cms:probe-commands:seed-enums')
        ->and(ictCommands($this))->not->toContain('cms:translation-sync')
        ->and(readlink($this->runtimeDir.'/'.ICT_NAME))->toBe('.versions/'.ICT_NAME.'-1.0.0')
        ->and($audit->changed['error_code'])->toBe('MODULE_INSTALL_COMMAND_FAILED');
});

test('an upgrade runs the new version install commands after cms:modules-sync', function () {
    ictInstallOld($this, '1.0.0');
    ictWebmaster('ict-5@test.local');
    [$zip, $sig] = ictPackage($this, '1.1.0', ['artisan' => ['cms:probe-commands:sync-permission']]);

    $install = ictRun(ictUpload($this, $zip, $sig));

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->kind)->toBe('upgrade')
        ->and(array_slice(ictCommands($this), 0, 3))->toBe(['module:migrate', 'cms:modules-sync', 'cms:probe-commands:sync-permission']);
});

test('a failing install command on upgrade restores the old version and writes module.upgrade_failed', function () {
    ictInstallOld($this, '1.0.0');
    $this->artisan = ictArtisan(['cms:probe-commands:sync-permission']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    ictWebmaster('ict-6@test.local');
    [$zip, $sig] = ictPackage($this, '1.1.0', ['artisan' => ['cms:probe-commands:sync-permission']]);

    $install = ictRun(ictUpload($this, $zip, $sig));

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_INSTALL_COMMAND)
        ->and(readlink($this->runtimeDir.'/'.ICT_NAME))->toBe('.versions/'.ICT_NAME.'-1.0.0')
        ->and(AuditLog::query()->where('action', 'action.module.upgrade_failed')->sole()->changed['error_code'])->toBe('MODULE_INSTALL_COMMAND_FAILED');
});

test('the upload preflight refuses a package needing a shared admin package the shell lacks', function () {
    ictWebmaster('ict-7@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', null, ['admin_packages' => ['@vueuse/core' => 14]]);

    $response = $this->post(ICT_URL, [
        'package' => UploadedFile::fake()->createWithContent('p.zip', $zip),
        'signature' => UploadedFile::fake()->createWithContent('p.sig.json', $sig),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonPath('error_code', InstallPreflight::ADMIN_PACKAGE_MISSING);
    expect(ModuleInstall::query()->count())->toBe(0)
        ->and((string) $response->getContent())->not->toContain($this->tmp);
});

test('the installer re-checks the signed manifest at verifying: a shell without the package refuses before placement', function () {
    ictWebmaster('ict-8@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', ['artisan' => ['cms:probe-commands:sync']], ['admin_packages' => ['vuedraggable' => 4]]);
    $uuid = ictUpload($this, $zip, $sig);

    // The shell changes between the preflight and the job (a hub install has no manifest at preflight time).
    file_put_contents($this->shellFile, json_encode(['format' => 1, 'packages' => ['vuedraggable' => ['major' => 2]]]));
    $install = ictRun($uuid);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(InstallPreflight::ADMIN_PACKAGE_MISSING)
        ->and($install->step_message)->toContain('verifying')
        ->and($install->step_message)->not->toContain($this->tmp)
        ->and(@lstat($this->runtimeDir.'/'.ICT_NAME))->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
});

test('the installer refuses a project-theme package at verifying', function () {
    $user = ictWebmaster('ict-9@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', null, [
        'frontend' => ['entries' => ['resources/assets/js/app.js'], 'styles' => 'project-theme', 'build' => 'frontend-build'],
    ], ['frontend-build/manifest.json' => '{}']);

    // Stage directly: the upload preflight would refuse it first.
    $uuid = (string) \Illuminate\Support\Str::uuid();
    file_put_contents($this->tmp.'/p.zip', $zip);
    file_put_contents($this->tmp.'/p.sig.json', $sig);
    ModuleInstaller::stageUpload($uuid, $this->tmp.'/p.zip', $this->tmp.'/p.sig.json');
    ModuleInstall::query()->create([
        'uuid' => $uuid, 'name' => ICT_NAME, 'version' => '1.0.0', 'source' => ModuleInstall::SOURCE_UPLOAD,
        'kind' => 'install', 'status' => ModuleInstall::STATUS_QUEUED, 'requested_by' => (int) $user->id,
    ]);

    $install = ictRun($uuid);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(InstallPreflight::THEME_COUPLED)
        ->and(@lstat($this->runtimeDir.'/'.ICT_NAME))->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
});

test('no audit row of an install with commands carries a file path of the site', function () {
    $this->artisan = ictArtisan(['cms:probe-commands:sync']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    ictWebmaster('ict-10@test.local');
    [$zip, $sig] = ictPackage($this, '1.0.0', ['artisan' => ['cms:probe-commands:sync']]);

    $install = ictRun(ictUpload($this, $zip, $sig));

    $json = json_encode([$install->toApiArray(), AuditLog::query()->get()->map->changed->all()], JSON_UNESCAPED_SLASHES);
    expect($install->error_code)->toBe(ModuleInstaller::ERROR_INSTALL_COMMAND)
        ->and($json)->not->toContain($this->tmp)
        ->and($json)->not->toContain(base_path());
});
