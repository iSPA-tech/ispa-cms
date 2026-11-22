<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use App\Support\Modules\RuntimePrecedence;
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
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request as ClientRequest;
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
 * release-to-runtime handover. A module the release ships (`<base>/cms/ProbeHandover`) and the project lists
 * in `<base>/runtime_modules.json` may get a newer online copy when the package declares a Core range the site's Core
 * meets; the release copy stays as the fallback. Everything runs in a temp base path; signing keypair generated here;
 * status and artisan collaborators are fakes; no request leaves the process.
 */
const HO_NAME = 'ProbeHandover';
const HO_URL = '/api/v2/admin/utilities/modules/upload';
const HO_CORE = ['core' => '>=2026.11.0,<2027.0.0'];

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-handover-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    // Real base-path layout: RuntimePrecedence reads <base>/cms, <base>/runtime-modules and <base>/runtime_modules.json.
    $this->runtimeDir = $this->tmp.'/runtime-modules';
    $this->releaseDir = $this->tmp.'/cms';
    $this->cacheDir = $this->tmp.'/cache';
    mkdir($this->runtimeDir, 0755);
    mkdir($this->releaseDir, 0755);
    mkdir($this->cacheDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);
    RuntimePrecedence::forget();
    mkdir($this->releaseDir.'/Core');
    file_put_contents($this->releaseDir.'/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.11.0']));
    hoWriteModule($this->releaseDir.'/'.HO_NAME, HO_NAME, '1.0.0', HO_CORE);
    file_put_contents($this->releaseDir.'/'.HO_NAME.'/marker', 'release');
    file_put_contents($this->tmp.'/runtime_modules.json', json_encode([HO_NAME]));
    $this->previousModulesPath = config('modules.paths.modules');

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
    ]);
    DatabaseActivator::flushProcessState();

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);

    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    $this->verifier = new PackageVerifier([$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))], []);
    $this->app->instance(PackageVerifier::class, $this->verifier);

    config(['modules.paths.modules' => $this->releaseDir]);
    $this->preflight = new InstallPreflight(new ModuleCatalog(null, $this->releaseDir), '8.3.0', fn (string $e): bool => true, fn (): array => [], fn (): array => [], fn (): array => [], fn (string $k): string => '0');
    $this->app->instance(InstallPreflight::class, $this->preflight);

    $this->backupUuid = (string) \Illuminate\Support\Str::uuid();
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => $this->backupUuid]));
    $this->app->instance(BackupGate::class, $gate);
    $this->statuses = hoStatuses();
    $this->app->instance(ModuleStatusService::class, $this->statuses);
    $this->artisan = hoArtisan();
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);

    $this->hubSent = [];
    $t = $this;
    Http::fake(function (ClientRequest $sent) use ($t) {
        $t->hubSent[] = $sent->method().' '.$sent->url();
        $handler = $t->hub ?? null;

        return $handler !== null ? $handler($sent) : Http::response('', 500);
    });
    $this->hub = null;
});

afterEach(function () {
    config(['modules.paths.modules' => $this->previousModulesPath]);
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    RuntimePrecedence::forget();
    DatabaseActivator::flushProcessState();
    hoRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function hoRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            hoRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1.
 *
 * @param list<string> $failing
 */
function hoArtisan(array $failing = []): ArtisanSubprocess
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
            $failed = in_array($args[0], $this->failing, true);

            return new FakeProcessResult(command: implode(' ', $args), exitCode: $failed ? 1 : 0, errorOutput: $failed ? 'boom' : '');
        }
    };
}

/**
 * Fake ModuleStatusService: records calls and writes only the test-database status row.
 */
function hoStatuses(): ModuleStatusService
{
    return new class extends ModuleStatusService {
        /** @var list<array{0: string, 1: bool, 2: int|null, 3: string}> */
        public array $calls = [];

        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
            $this->calls[] = [$name, $enabled, $by?->id, $reason];
            (new DatabaseActivator(app()))->setActiveByName($name, $enabled);
        }
    };
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function hoAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Upgrade Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

/**
 * Upload a package through the endpoint and run the queued job.
 */
function hoUploadAndRun(object $t, string $zip, string $sig, array $extra = []): array
{
    $response = $t->post(HO_URL, [
        'package' => UploadedFile::fake()->createWithContent('p.zip', $zip),
        'signature' => UploadedFile::fake()->createWithContent('p.sig.json', $sig),
    ] + $extra, ['Accept' => 'application/json']);
    if ($response->status() !== 202) {
        return [$response, null];
    }
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);

    return [$response, ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail()];
}

/**
 * Write module.json, composer.json and the provider file of $name at $version into $dir.
 *
 * @param array<string, string> $requires
 */
function hoWriteModule(string $dir, string $name, string $version, array $requires): void
{
    foreach (hoFiles($name, $version, $requires) as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
}

/**
 * Files of a module folder at $version.
 *
 * @param array<string, string>|list<string> $requires
 *
 * @return array<string, string> relative path => content
 */
function hoFiles(string $name, string $version, array $requires): array
{
    return [
        'module.json' => json_encode(['name' => $name, 'version' => $version, 'requires' => $requires, 'files' => [], 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']]),
        'composer.json' => json_encode(['name' => 'ispa/probe-handover', 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        'app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
}

/**
 * Zip and signature JSON of HO_NAME at $version; $requires goes into module.json and the signed manifest.
 *
 * @param array<string, string>|list<string> $requires
 *
 * @return array{0: string, 1: string}
 */
function hoPackage(object $t, string $version, array $requires = HO_CORE): array
{
    $files = hoFiles(HO_NAME, $version, $requires);
    $zipPath = $t->tmp.'/build-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $listed = [];
    foreach ($files as $path => $content) {
        $zip->addFromString(HO_NAME.'/'.$path, $content);
        $listed[HO_NAME.'/'.$path] = hash('sha256', $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $manifest = json_encode([
        'format' => 1, 'name' => HO_NAME, 'version' => $version,
        'zip_sha256' => hash('sha256', $bytes), 'zip_size' => strlen($bytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)), 'files' => $listed,
        // The signed manifest names modules studly-cased, as the package builder writes them.
        'requires' => array_combine(array_map('ucfirst', array_keys($requires)), $requires), 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-13T10:00:00Z', 'key_id' => $t->keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return [$bytes, (new PackageSigner())->envelope($manifest, $t->signKey)];
}

/**
 * Sign a hub catalog answer holding the given versions of HO_NAME, each with $requires.
 *
 * @param list<string> $versions
 * @param array<string, string>|list<string> $requires
 */
function hoHubCatalog(object $t, array $versions, array $requires = HO_CORE): void
{
    $entries = array_map(fn (string $v): array => ['version' => $v, 'revoked' => false, 'requires' => $requires, 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'], $versions);
    $t->hub = function (ClientRequest $sent) use ($t, $versions, $entries) {
        $body = json_encode(['modules' => [['name' => HO_NAME, 'latest_version' => end($versions), 'versions' => $entries]]]);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($t->secret, 200, $nonce, $body)]);
    };
}

test('an unlisted release module is still refused as today', function () {
    unlink($this->tmp.'/runtime_modules.json');
    hoHubCatalog($this, ['1.1.0']);

    $result = $this->preflight->check(HO_NAME, '1.1.0');

    expect($result->blockingCodes())->toContain(InstallPreflight::ALREADY_PRESENT)
        ->and($result->blockingCodes())->toContain(InstallPreflight::SHADOWS_RELEASE)
        ->and($result->blockingCodes())->toContain(InstallPreflight::NAME_CONFLICT)
        ->and($result->kind)->toBe('install');
});

test('a listed release module accepts a newer package with a Core range as a handover upgrade', function () {
    hoHubCatalog($this, ['1.0.0', '1.1.0']);

    $result = $this->preflight->check(HO_NAME, '1.1.0');

    expect($result->blockingCodes())->toBe([])
        ->and($result->kind)->toBe('upgrade')
        ->and($result->previousVersion)->toBe('1.0.0')
        ->and(implode(' ', $result->changes))->toContain('release copy');
});

test('a listed release module refuses the same or an older version', function () {
    hoHubCatalog($this, ['0.9.0', '1.0.0']);

    expect($this->preflight->check(HO_NAME, '1.0.0')->blockingCodes())->toContain(InstallPreflight::SAME_VERSION)
        ->and($this->preflight->check(HO_NAME, '0.9.0')->blockingCodes())->toContain(InstallPreflight::DOWNGRADE);
});

test('a handover package without a Core range is refused', function (array $requires) {
    hoHubCatalog($this, ['1.1.0'], $requires);

    expect($this->preflight->check(HO_NAME, '1.1.0')->blockingCodes())->toContain(InstallPreflight::HANDOVER_NEEDS_CORE_RANGE);
})->with([
    'no requires' => [[]],
    'list form' => [['core']],
    'any version' => [['core' => '*']],
]);

test('a handover package whose Core range excludes the site Core is refused', function () {
    hoHubCatalog($this, ['1.1.0'], ['core' => '>=2026.12.0,<2027.0.0']);

    expect($this->preflight->check(HO_NAME, '1.1.0')->blockingCodes())->toContain(InstallPreflight::REQUIRES_UNMET);
});

test('the catalog offers a handover upgrade for a listed release module', function () {
    hoHubCatalog($this, ['1.0.0', '1.1.0']);

    $entry = collect((new ModuleCatalog(null, $this->releaseDir))->entries())->firstWhere('name', HO_NAME);

    expect($entry['installed'])->toBe('release')
        ->and($entry['handover'])->toBeTrue()
        ->and($entry['source'])->toBe('release')
        ->and($entry['installed_version'])->toBe('1.0.0')
        ->and($entry['upgrade_available'])->toBeTrue()
        ->and($entry['upgrade_version'])->toBe('1.1.0');
});

test('handover upload: the new copy is linked beside the release copy, wins, and the module is not touched', function () {
    (new DatabaseActivator(app()))->setVersion(HO_NAME, '1.0.0');
    (new DatabaseActivator(app()))->setActiveByName(HO_NAME, true);
    Sanctum::actingAs(hoAdmin('ho-wm1@test.local', true));
    [$zip, $sig] = hoPackage($this, '1.1.0');

    [$response, $install] = hoUploadAndRun($this, $zip, $sig);

    $response->assertStatus(202)->assertJsonPath('data.kind', 'upgrade')->assertJsonPath('data.previous_version', '1.0.0');
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->previous_version)->toBe('1.0.0')
        ->and(readlink($this->runtimeDir.'/'.HO_NAME))->toBe('.versions/'.HO_NAME.'-1.1.0')
        ->and(file_get_contents($this->releaseDir.'/'.HO_NAME.'/marker'))->toBe('release')
        ->and(is_dir($this->runtimeDir.'/.previous'))->toBeFalse()
        ->and($this->statuses->calls)->toBe([]);

    RuntimePrecedence::forget();
    expect(RuntimePrecedence::preferRuntime($this->tmp, HO_NAME))->toBeTrue();
    $state = (new ModuleCatalog(null, $this->releaseDir))->installedState(HO_NAME);
    expect($state)->toMatchArray(['installed' => 'release', 'source' => 'runtime', 'installed_version' => '1.1.0', 'handover' => true]);

    $row = DB::table('module_statuses')->where('name', HO_NAME)->first();
    expect((bool) $row->enabled)->toBeTrue()->and($row->version)->toBe('1.1.0');
    $commands = array_map(fn (array $a): string => implode(' ', $a), $this->artisan->calls);
    expect($commands[0])->toBe('module:migrate '.HO_NAME.' --force');
    expect(AuditLog::query()->where('action', 'action.module.upgraded')->sole()->changed)->toMatchArray(['version' => '1.1.0', 'previous_version' => '1.0.0']);
});

test('a second handover upgrade replaces the runtime copy as a normal upgrade', function () {
    Sanctum::actingAs(hoAdmin('ho-wm2@test.local', true));
    [$zip, $sig] = hoPackage($this, '1.1.0');
    hoUploadAndRun($this, $zip, $sig);
    [$zip2, $sig2] = hoPackage($this, '1.2.0');

    [$response, $install] = hoUploadAndRun($this, $zip2, $sig2);

    $response->assertStatus(202)->assertJsonPath('data.previous_version', '1.1.0');
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(readlink($this->runtimeDir.'/'.HO_NAME))->toBe('.versions/'.HO_NAME.'-1.2.0')
        ->and(is_dir($this->runtimeDir.'/.previous/'.HO_NAME.'-1.1.0'))->toBeTrue()
        ->and(file_get_contents($this->releaseDir.'/'.HO_NAME.'/marker'))->toBe('release');
});

test('a failing migration removes the new copy, keeps the module enabled on the release copy', function () {
    (new DatabaseActivator(app()))->setVersion(HO_NAME, '1.0.0');
    (new DatabaseActivator(app()))->setActiveByName(HO_NAME, true);
    $this->artisan = hoArtisan(['module:migrate']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    Sanctum::actingAs(hoAdmin('ho-wm3@test.local', true));
    [$zip, $sig] = hoPackage($this, '1.1.0');

    [, $install] = hoUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_MIGRATE)
        ->and(@lstat($this->runtimeDir.'/'.HO_NAME))->toBeFalse()
        ->and(is_dir($this->runtimeDir.'/.versions/'.HO_NAME.'-1.1.0'))->toBeFalse()
        ->and(file_get_contents($this->releaseDir.'/'.HO_NAME.'/marker'))->toBe('release')
        ->and($this->statuses->calls)->toBe([]);

    RuntimePrecedence::forget();
    expect(RuntimePrecedence::preferRuntime($this->tmp, HO_NAME))->toBeFalse();
    $row = DB::table('module_statuses')->where('name', HO_NAME)->first();
    expect((bool) $row->enabled)->toBeTrue()->and($row->version)->toBe('1.0.0');
});

test('a placed copy that would not win is undone before any artisan step', function () {
    // The release Core moves past the package range between preflight and placement.
    Sanctum::actingAs(hoAdmin('ho-wm4@test.local', true));
    [$zip, $sig] = hoPackage($this, '1.1.0', ['core' => '>=2026.11.0,<2026.11.5']);
    $this->preflight = new InstallPreflight(new ModuleCatalog(null, $this->releaseDir), '8.3.0', fn (string $e): bool => true, fn (): array => [], fn (): array => [], fn (): array => [], fn (string $k): string => '0');
    $this->app->instance(InstallPreflight::class, $this->preflight);
    $response = $this->post(HO_URL, [
        'package' => UploadedFile::fake()->createWithContent('p.zip', $zip),
        'signature' => UploadedFile::fake()->createWithContent('p.sig.json', $sig),
    ], ['Accept' => 'application/json']);
    $response->assertStatus(202);
    file_put_contents($this->releaseDir.'/Core/module.json', json_encode(['name' => 'Core', 'version' => '2026.11.9']));
    app()->call([new InstallModuleJob((string) $response->json('data.uuid')), 'handle']);
    $install = ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_HANDOVER_NOT_PREFERRED)
        ->and(@lstat($this->runtimeDir.'/'.HO_NAME))->toBeFalse()
        ->and(is_dir($this->runtimeDir.'/.versions/'.HO_NAME.'-1.1.0'))->toBeFalse()
        ->and($this->artisan->calls)->toBe([]);
});
