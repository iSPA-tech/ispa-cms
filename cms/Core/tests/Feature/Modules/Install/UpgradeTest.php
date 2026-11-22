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
 * upgrade of an installed runtime module from a signed package. Everything runs in a temp folder; the
 * signing keypair is generated here; status and artisan collaborators are fakes; no request leaves the process.
 */
const UP_NAME = 'ProbeUpgrade';
const UP_URL = '/api/v2/admin/utilities/modules/upload';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-upgrade-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->releaseDir = $this->tmp.'/release';
    $this->cacheDir = $this->tmp.'/cache';
    mkdir($this->runtimeDir, 0755);
    mkdir($this->releaseDir, 0755);
    mkdir($this->cacheDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);

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

    $this->preflight = new InstallPreflight(new ModuleCatalog(null, $this->releaseDir), '8.3.0', fn (string $e): bool => true, fn (): array => [], fn (): array => [], fn (): array => [], fn (string $k): string => '0');
    $this->app->instance(InstallPreflight::class, $this->preflight);

    $this->backupUuid = (string) \Illuminate\Support\Str::uuid();
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => $this->backupUuid]));
    $this->app->instance(BackupGate::class, $gate);
    $this->statuses = upStatuses();
    $this->app->instance(ModuleStatusService::class, $this->statuses);
    $this->artisan = upArtisan();
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
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    upRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function upRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            upRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Files of a module folder at $version.
 *
 * @return array<string, string> relative path => content
 */
function upFiles(string $name, string $version, array $requires = []): array
{
    return [
        'module.json' => json_encode(['name' => $name, 'version' => $version, 'requires' => $requires, 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']]),
        'composer.json' => json_encode(['name' => 'ispa/probe-upgrade', 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        'app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
}

/**
 * Install $name at $version the way a first install leaves it: `.versions/<Name>-<ver>` plus the module link,
 * enabled (or not) in the status table with the stored version.
 */
function upInstallOld(object $t, string $name, string $version, bool $enabled = true, array $requires = []): void
{
    $dir = $t->runtimeDir.'/.versions/'.$name.'-'.$version;
    foreach (upFiles($name, $version, $requires) as $path => $content) {
        @mkdir(dirname($dir.'/'.$path), 0755, true);
        file_put_contents($dir.'/'.$path, $content);
    }
    symlink('.versions/'.$name.'-'.$version, $t->runtimeDir.'/'.$name);
    $activator = new DatabaseActivator(app());
    $activator->setVersion($name, $version);
    $activator->setActiveByName($name, $enabled);
}

/**
 * Zip and signature JSON of $name at $version.
 *
 * @return array{0: string, 1: string}
 */
function upPackage(object $t, string $version, string $name = UP_NAME, bool|array $irreversible = false): array
{
    $files = upFiles($name, $version);
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

    $manifest = json_encode([
        'format' => 1, 'name' => $name, 'version' => $version,
        'zip_sha256' => hash('sha256', $bytes), 'zip_size' => strlen($bytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)), 'files' => $listed,
        'requires' => [], 'irreversible' => $irreversible, 'custom_code' => false, 'php_min' => '8.3',
        'built_at' => '2026-11-10T10:00:00Z', 'key_id' => $t->keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return [$bytes, (new PackageSigner())->envelope($manifest, $t->signKey)];
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1.
 *
 * @param list<string> $failing
 */
function upArtisan(array $failing = []): ArtisanSubprocess
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
function upStatuses(): ModuleStatusService
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
function upAdmin(string $email, bool $webmaster): User
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
function upUploadAndRun(object $t, string $zip, string $sig, array $extra = []): array
{
    $response = $t->post(UP_URL, [
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
 * Sign a hub catalog answer holding the given versions of UP_NAME.
 */
function upHubCatalog(object $t, array $versions, array $extra = []): void
{
    $entries = array_map(fn (string $v): array => ['version' => $v, 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'] + $extra, $versions);
    $t->hub = function (ClientRequest $sent) use ($t, $versions, $entries) {
        $body = json_encode(['modules' => [['name' => UP_NAME, 'latest_version' => end($versions), 'versions' => $entries]]]);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($t->secret, 200, $nonce, $body)]);
    };
}

test('an installed module asked for the same or an older version is not read as a reinstall over kept tables', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    $create = '2026_01_01_000000_create_up_items_table';
    upHubCatalog($this, ['1.0.0', '0.9.0'], ['migrations' => [$create]]);

    // Its own live table exists; the create has run (same version) or, for the older package, a renamed create has not.
    $preflight = fn (array $ran): InstallPreflight => new InstallPreflight(new ModuleCatalog(null, $this->releaseDir), '8.3.0', fn (string $e): bool => true, fn (): array => [], fn (): array => $ran, fn (): array => [], fn (string $k): string => '0', fn (string $t): bool => $t === 'up_items');

    $same = $preflight([$create])->check(UP_NAME, '1.0.0');
    $older = $preflight([])->check(UP_NAME, '0.9.0');

    expect($same->blockingCodes())->toContain(InstallPreflight::SAME_VERSION)
        ->and($same->backup)->toMatchArray(['reason' => 'no_new_migrations', 'earlier_tables' => [], 'conflicting_tables' => []])
        ->and($older->blockingCodes())->toContain(InstallPreflight::DOWNGRADE)
        ->and($older->blockingCodes())->not->toContain(InstallPreflight::TABLE_EXISTS)
        ->and($older->backup['earlier_tables'])->toBe([]);
});

test('preflight accepts a newer version as an upgrade and reports the previous version', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upHubCatalog($this, ['1.0.0', '1.1.0', '0.9.0']);

    $result = $this->preflight->check(UP_NAME, '1.1.0');

    expect($result->blockingCodes())->toBe([])
        ->and($result->kind)->toBe('upgrade')
        ->and($result->previousVersion)->toBe('1.0.0')
        ->and($result->toArray())->toMatchArray(['kind' => 'upgrade', 'previous_version' => '1.0.0'])
        ->and(implode(' ', $result->changes))->toContain('.previous');
});

test('same version and lower version are refused with their own codes', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upHubCatalog($this, ['1.0.0', '1.1.0', '0.9.0']);

    expect($this->preflight->check(UP_NAME, '1.0.0')->blockingCodes())->toContain(InstallPreflight::SAME_VERSION)
        ->and($this->preflight->check(UP_NAME, '0.9.0')->blockingCodes())->toContain(InstallPreflight::DOWNGRADE)
        ->and($this->preflight->check(UP_NAME, '0.9.0')->kind)->toBe('install');
});

test('a dependent module that pins the old version blocks the upgrade', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upInstallOld($this, 'Dependent', '1.0.0', true, [UP_NAME => '=1.0.0']);
    upHubCatalog($this, ['1.0.0', '1.1.0']);

    $result = $this->preflight->check(UP_NAME, '1.1.0');
    $block = collect($result->blocking)->firstWhere('code', InstallPreflight::DEPENDENT_PINS_VERSION);

    expect($result->blockingCodes())->toContain(InstallPreflight::DEPENDENT_PINS_VERSION)
        ->and($block['details']['dependents'])->toBe([['module' => 'Dependent', 'constraint' => '=1.0.0']]);
});

test('a dependent that pins the old version in lower case blocks the upgrade', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upInstallOld($this, 'Dependent', '1.0.0', true, [strtolower(UP_NAME) => '=1.0.0']);
    upHubCatalog($this, ['1.0.0', '1.1.0']);

    $result = $this->preflight->check(UP_NAME, '1.1.0');
    $block = collect($result->blocking)->firstWhere('code', InstallPreflight::DEPENDENT_PINS_VERSION);

    expect($result->blockingCodes())->toContain(InstallPreflight::DEPENDENT_PINS_VERSION)
        ->and($block['details']['dependents'])->toBe([['module' => 'Dependent', 'constraint' => '=1.0.0']]);
});

test('a release module with the same name is still refused', function () {
    mkdir($this->releaseDir.'/'.UP_NAME);
    file_put_contents($this->releaseDir.'/'.UP_NAME.'/module.json', json_encode(['name' => UP_NAME, 'version' => '1.0.0']));
    upHubCatalog($this, ['1.1.0']);

    $result = $this->preflight->check(UP_NAME, '1.1.0');

    expect($result->blockingCodes())->toContain(InstallPreflight::ALREADY_PRESENT)
        ->and($result->blockingCodes())->toContain(InstallPreflight::SHADOWS_RELEASE)
        ->and($result->kind)->toBe('install');
});

test('the catalog marks an installed runtime module with its upgrade', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upHubCatalog($this, ['1.0.0', '1.1.0']);

    $entry = collect((new ModuleCatalog(null, $this->releaseDir))->entries())->firstWhere('name', UP_NAME);

    expect($entry['installed'])->toBe('runtime')
        ->and($entry['installed_version'])->toBe('1.0.0')
        ->and($entry['upgrade_available'])->toBeTrue()
        ->and($entry['upgrade_version'])->toBe('1.1.0');
});

test('offline upload upgrade: old folder moves to .previous, new one is placed, state is kept and audited', function () {
    upInstallOld($this, UP_NAME, '1.0.0', true);
    Sanctum::actingAs(upAdmin('up-wm1@test.local', true));
    [$zip, $sig] = upPackage($this, '1.1.0');

    [$response, $install] = upUploadAndRun($this, $zip, $sig);

    $response->assertStatus(202)->assertJsonPath('data.kind', 'upgrade')->assertJsonPath('data.previous_version', '1.0.0');
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->kind)->toBe('upgrade')
        ->and($install->previous_version)->toBe('1.0.0')
        ->and(readlink($this->runtimeDir.'/'.UP_NAME))->toBe('.versions/'.UP_NAME.'-1.1.0')
        ->and(json_decode((string) file_get_contents($this->runtimeDir.'/'.UP_NAME.'/module.json'), true)['version'])->toBe('1.1.0')
        ->and(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))->toBeTrue()
        ->and(is_link($this->runtimeDir.'/.versions/'.UP_NAME.'-1.0.0'))->toBeTrue()
        ->and(realpath($this->runtimeDir.'/.versions/'.UP_NAME.'-1.0.0'))->toBe(realpath($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))
        ->and($this->statuses->calls)->toBe([]);

    $row = DB::table('module_statuses')->where('name', UP_NAME)->first();
    expect((bool) $row->enabled)->toBeTrue()->and($row->version)->toBe('1.1.0');

    $commands = array_map(fn (array $a): string => implode(' ', $a), $this->artisan->calls);
    expect($commands[0])->toBe('module:migrate '.UP_NAME.' --force');

    $audit = AuditLog::query()->where('action', 'action.module.upgraded')->sole();
    expect($audit->changed)->toMatchArray(['name' => UP_NAME, 'version' => '1.1.0', 'previous_version' => '1.0.0', 'backup_uuid' => $this->backupUuid]);

    $list = $this->getJson('/api/v2/admin/utilities/modules/installs')->assertOk()->json('data');
    expect($list[0])->toMatchArray(['kind' => 'upgrade', 'previous_version' => '1.0.0']);
});

test('a disabled module stays disabled after an upgrade', function () {
    upInstallOld($this, UP_NAME, '1.0.0', false);
    Sanctum::actingAs(upAdmin('up-wm2@test.local', true));
    [$zip, $sig] = upPackage($this, '1.1.0');

    [, $install] = upUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and((bool) DB::table('module_statuses')->where('name', UP_NAME)->value('enabled'))->toBeFalse();
});

test('a failing migration restores the previous folder, keeps the module enabled and audits the failure', function () {
    upInstallOld($this, UP_NAME, '1.0.0', true);
    $this->artisan = upArtisan(['module:migrate']);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);
    Sanctum::actingAs(upAdmin('up-wm3@test.local', true));
    [$zip, $sig] = upPackage($this, '1.1.0');

    [, $install] = upUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_MIGRATE)
        ->and(readlink($this->runtimeDir.'/'.UP_NAME))->toBe('.versions/'.UP_NAME.'-1.0.0')
        ->and(json_decode((string) file_get_contents($this->runtimeDir.'/'.UP_NAME.'/module.json'), true)['version'])->toBe('1.0.0')
        ->and(is_dir($this->runtimeDir.'/.versions/'.UP_NAME.'-1.0.0'))->toBeTrue()
        ->and(is_dir($this->runtimeDir.'/.versions/'.UP_NAME.'-1.1.0'))->toBeFalse()
        ->and(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))->toBeFalse()
        ->and($this->statuses->calls)->toBe([]);

    $row = DB::table('module_statuses')->where('name', UP_NAME)->first();
    expect((bool) $row->enabled)->toBeTrue()->and($row->version)->toBe('1.0.0')
        ->and(AuditLog::query()->where('action', 'action.module.upgrade_failed')->sole()->changed)->toMatchArray(['error_code' => ModuleInstaller::ERROR_MIGRATE, 'previous_version' => '1.0.0'])
        ->and(AuditLog::query()->where('action', 'action.module.upgraded')->count())->toBe(0);
});

test('the next successful upgrade removes older previous folders', function () {
    upInstallOld($this, UP_NAME, '1.0.0', true);
    Sanctum::actingAs(upAdmin('up-wm4@test.local', true));

    [$zip, $sig] = upPackage($this, '1.1.0');
    upUploadAndRun($this, $zip, $sig);
    expect(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))->toBeTrue();

    [$zip, $sig] = upPackage($this, '1.2.0');
    [, $install] = upUploadAndRun($this, $zip, $sig);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.1.0'))->toBeTrue()
        ->and(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))->toBeFalse()
        ->and(is_link($this->runtimeDir.'/.versions/'.UP_NAME.'-1.0.0'))->toBeFalse();
});

test('an uploaded package with the same or a lower version is refused at the endpoint', function () {
    upInstallOld($this, UP_NAME, '1.1.0');
    Sanctum::actingAs(upAdmin('up-wm5@test.local', true));

    [$zip, $sig] = upPackage($this, '1.1.0');
    [$same] = upUploadAndRun($this, $zip, $sig);
    [$zip, $sig] = upPackage($this, '1.0.0');
    [$lower] = upUploadAndRun($this, $zip, $sig);

    $same->assertStatus(422)->assertJsonPath('error_code', InstallPreflight::SAME_VERSION);
    $lower->assertStatus(422)->assertJsonPath('error_code', InstallPreflight::DOWNGRADE);
    expect(ModuleInstall::query()->count())->toBe(0);
});

test('an irreversible upgrade needs the typed confirmation', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    Sanctum::actingAs(upAdmin('up-wm6@test.local', true));
    [$zip, $sig] = upPackage($this, '1.1.0', UP_NAME, ['2026_01_01_000001_drop_x']);

    [$refused] = upUploadAndRun($this, $zip, $sig);
    [$accepted, $install] = upUploadAndRun($this, $zip, $sig, ['confirm_irreversible' => '1', 'confirm_name' => UP_NAME]);

    $refused->assertStatus(422)->assertJsonPath('error_code', 'MODULE_CONFIRMATION_REQUIRED');
    expect($accepted->status())->toBe(202)->and($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)->and($install->confirmed_irreversible)->toBeTrue();
});

test('hub path: the upgrade is downloaded, verified and placed like an install', function () {
    upInstallOld($this, UP_NAME, '1.0.0', true);
    [$zip, $sig] = upPackage($this, '1.1.0');
    $url = 'https://hub.test/api/v2/public/platform/downloads/'.bin2hex(random_bytes(8));
    $this->hub = function (ClientRequest $sent) use ($url, $zip, $sig) {
        if ($sent->method() === 'POST' && str_ends_with($sent->url(), ModuleInstaller::TICKET_PATH)) {
            $body = json_encode(['download_url' => $url, 'expires_at' => '2026-11-10T10:05:00+00:00', 'signature_json' => $sig]);
            $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

            return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($this->secret, 200, $nonce, $body)]);
        }

        return $sent->url() === $url ? Http::response($zip, 200) : Http::response('', 404);
    };
    $user = upAdmin('up-wm7@test.local', true);
    $install = ModuleInstall::query()->create([
        'name' => UP_NAME, 'version' => '1.1.0', 'source' => ModuleInstall::SOURCE_HUB, 'kind' => ModuleInstall::KIND_UPGRADE,
        'previous_version' => '1.0.0', 'status' => ModuleInstall::STATUS_QUEUED, 'requested_by' => (int) $user->id,
    ]);

    app()->call([new InstallModuleJob($install->uuid), 'handle']);

    $install->refresh();
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->source)->toBe('hub')
        ->and(readlink($this->runtimeDir.'/'.UP_NAME))->toBe('.versions/'.UP_NAME.'-1.1.0')
        ->and(is_dir($this->runtimeDir.'/.previous/'.UP_NAME.'-1.0.0'))->toBeTrue();
});

test('an admin who is not a Webmaster gets 403 on the upgrade-capable upload', function () {
    upInstallOld($this, UP_NAME, '1.0.0');
    upAdmin('up-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(upAdmin('up-editor@test.local', false));
    [$zip, $sig] = upPackage($this, '1.1.0');

    [$response] = upUploadAndRun($this, $zip, $sig);

    $response->assertForbidden();
    expect(ModuleInstall::query()->count())->toBe(0);
});

/**
 * Point the module repository at the temp runtime folder so the list API sees the placed runtime modules.
 */
function upListRuntimeModules(object $t): void
{
    config(['modules.scan.enabled' => true, 'modules.scan.paths' => [$t->runtimeDir]]);
    app()->instance('modules', new \App\Support\Modules\ReleaseFirstModuleRepository(app(), (string) config('modules.paths.modules')));
    \Nwidart\Modules\Facades\Module::clearResolvedInstance('modules');
}

test('after an upgrade the modules list reports the new version of the runtime module', function () {
    upInstallOld($this, UP_NAME, '1.0.0', true);
    Sanctum::actingAs(upAdmin('up-wm-list@test.local', true));
    upListRuntimeModules($this);
    $before = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->firstWhere('name', UP_NAME);
    expect($before['version'])->toBe('1.0.0');

    [$zip, $sig] = upPackage($this, '1.1.0');
    [, $install] = upUploadAndRun($this, $zip, $sig);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED);
    upListRuntimeModules($this);

    $row = collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->firstWhere('name', UP_NAME);

    // The fixture has no version in composer.json: the number can only come from the stored version.
    expect($row['version'])->toBe('1.1.0');
});

test('the modules list says whether a hub is configured', function () {
    Sanctum::actingAs(upAdmin('up-wm-hub@test.local', true));

    $flags = function (array $config): array {
        config($config);

        return collect($this->getJson('/api/v2/admin/utilities/modules')->assertOk()->json('data'))->pluck('hub_configured')->unique()->values()->all();
    };
    $ready = ['ispa-cms.cms_domain' => 'site.example.test', 'ispa-cms.cms_domain_secret' => 'a-secret', 'ispa-cms.platform_hub_url' => 'https://hub.test'];

    expect($flags($ready))->toBe([true])
        ->and($flags(['ispa-cms.cms_domain' => '']))->toBe([false])
        ->and($flags($ready))->toBe([true])
        ->and($flags(['ispa-cms.cms_domain_secret' => '']))->toBe([false])
        ->and($flags($ready))->toBe([true])
        ->and($flags(['ispa-cms.platform_hub_url' => ' ']))->toBe([false]);
});
