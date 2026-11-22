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
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\RuntimePlacement;
use Cms\Core\Services\Modules\Packages\SafeExtractor;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * offline package upload. The upload endpoint verifies the signature with the container's PackageVerifier,
 * runs the upload preflight, stages zip + signature under `<runtime>/.staging/<install uuid>/` and queues the same
 * InstallModuleJob; the installer skips only the hub ticket + download. Everything runs in a temp folder; the
 * signing keypair is generated here; enabling uses fake ModuleStatusService / ArtisanSubprocess (no real commands).
 */
const OU_NAME = 'ProbeUpload';
const OU_VERSION = '2026.1.0';
const OU_URL = '/api/v2/admin/utilities/modules/upload';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    URL::forceScheme('https');

    // No HTTP request may leave the upload path: everything is recorded and fails loudly.
    Http::preventStrayRequests();
    $this->httpSent = [];
    $t = $this;
    Http::fake(function (ClientRequest $sent) use ($t) {
        $t->httpSent[] = $sent->method().' '.$sent->url();

        return Http::response('', 500);
    });

    $base = sys_get_temp_dir().'/install-upload-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->releaseDir = $this->tmp.'/release';
    $this->cacheDir = $this->tmp.'/cache';
    mkdir($this->runtimeDir, 0755);
    mkdir($this->releaseDir, 0755);
    mkdir($this->cacheDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);

    // Cached-file paths point at the temp dir; only a route cache "exists".
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

    // Hub not configured: the upload path must not need it (and the best-effort status report stays local).
    config([
        'ispa-cms.cms_domain' => '',
        'ispa-cms.cms_domain_secret' => '',
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        // A placeholder trusted key in config: only the container's verifier trusts the real test key.
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);

    // Test signing keypair, trusted only by the container-bound verifier.
    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    $this->verifier = new PackageVerifier([$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))], []);
    $this->app->instance(PackageVerifier::class, $this->verifier);

    // Placement spy: its device lookup records each call.
    $this->deviceCalls = [];
    $this->placement = new RuntimePlacement(function (string $path) use ($t): ?int {
        $t->deviceCalls[] = $path;
        $stat = @stat($path);

        return $stat === false ? null : (int) $stat['dev'];
    });
    $this->app->instance(RuntimePlacement::class, $this->placement);

    // Healthy local environment; PHP upload limits read as unlimited.
    ouBindPreflight($this, fn (string $key): string => '0');

    // Enabling collaborators of the installer, bound in the container like a deployed worker would resolve them.
    $this->backupUuid = (string) Str::uuid();
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => $this->backupUuid]));
    $this->app->instance(BackupGate::class, $gate);
    $this->statuses = ouStatuses();
    $this->artisan = ouArtisan();
    $this->app->instance(ModuleStatusService::class, $this->statuses);
    $this->app->instance(ArtisanSubprocess::class, $this->artisan);

    [$this->zipBytes, $this->sigJson] = ouPackage($this, OU_NAME);
});

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    ouRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function ouRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            ouRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Bind the preflight with a faked environment and the given ini reader.
 *
 * @param callable(string): (string|false) $ini
 */
function ouBindPreflight(object $t, callable $ini): void
{
    app()->instance(InstallPreflight::class, new InstallPreflight(
        new ModuleCatalog(null, $t->releaseDir),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
        $ini,
    ));
}

/**
 * Build a package zip holding module $zipName and a sig.json signed with $signKey for module $manifestName.
 *
 * @return array{0: string, 1: string} zip bytes, signature JSON
 */
function ouPackage(object $t, string $zipName, ?string $manifestName = null, ?string $signKey = null, ?string $keyId = null, ?string $hashOf = null, bool|array $irreversible = false): array
{
    $manifestName ??= $zipName;
    $files = [
        $zipName.'/module.json' => json_encode(['name' => $zipName, 'version' => OU_VERSION, 'providers' => ['Cms\\'.$zipName.'\\Providers\\'.$zipName.'ServiceProvider']]),
        $zipName.'/composer.json' => json_encode(['name' => 'ispa/probe-upload', 'autoload' => ['psr-4' => ['Cms\\'.$zipName.'\\' => 'app/']]]),
        $zipName.'/app/Providers/'.$zipName.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$zipName."\\Providers;\n",
    ];
    $zipPath = $t->tmp.'/build-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $zipBytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    // The manifest lists the files under the module it names.
    $listed = [];
    foreach ($files as $path => $content) {
        $listed[$manifestName.substr($path, strlen($zipName))] = hash('sha256', $content);
    }
    $hashed = $hashOf ?? $zipBytes;
    $keyId ??= $t->keyId;
    $manifest = json_encode([
        'format' => 1,
        'name' => $manifestName,
        'version' => OU_VERSION,
        'zip_sha256' => hash('sha256', $hashed),
        'zip_size' => strlen($hashed),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => $listed,
        'requires' => [],
        'irreversible' => $irreversible,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => $keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return [$zipBytes, (new PackageSigner())->envelope($manifest, $signKey ?? $t->signKey)];
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing.
 */
function ouArtisan(): ArtisanSubprocess
{
    return new class extends ArtisanSubprocess {
        /** @var list<array<int, string>> */
        public array $calls = [];

        public function __construct()
        {
        }

        public function run(array $args, int $timeout): ProcessResult
        {
            $this->calls[] = $args;

            return new FakeProcessResult(command: implode(' ', $args), exitCode: 0, errorOutput: '');
        }
    };
}

/**
 * Fake ModuleStatusService: records calls and writes only the test-database status row.
 */
function ouStatuses(): ModuleStatusService
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
function ouAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Upload Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId([
            'name' => 'Webmaster '.uniqid(),
            'custom' => json_encode(['level' => 0]),
        ]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

/**
 * Multipart upload body.
 *
 * @return array<string, UploadedFile>
 */
function ouFiles(string $zipBytes, ?string $sigJson): array
{
    $files = ['package' => UploadedFile::fake()->createWithContent('probe-upload.zip', $zipBytes)];
    if ($sigJson !== null) {
        $files['signature'] = UploadedFile::fake()->createWithContent('probe-upload.sig.json', $sigJson);
    }

    return $files;
}

/**
 * Run the queued install job like a worker would (installer resolved from the container).
 */
function ouRunJob(string $uuid): ModuleInstall
{
    app()->call([new InstallModuleJob($uuid), 'handle']);

    return ModuleInstall::query()->where('uuid', $uuid)->firstOrFail();
}

/**
 * Entries left in the staging folder.
 *
 * @return list<string>
 */
function ouStaging(object $t): array
{
    $dir = $t->runtimeDir.'/'.ModuleInstaller::STAGING_FOLDER;

    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

test('an admin who is not a Webmaster gets 403', function () {
    ouAdmin('upload-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(ouAdmin('upload-editor@test.local', false));

    $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])->assertForbidden();

    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);
    Queue::assertNothingPushed();
});

test('a zip without a valid signature is refused', function () {
    Sanctum::actingAs(ouAdmin('upload-wm1@test.local', true));

    // No signature file at all.
    $this->post(OU_URL, ouFiles($this->zipBytes, null), ['Accept' => 'application/json'])->assertStatus(422);

    // A signature file that is not a signed envelope.
    $this->post(OU_URL, ouFiles($this->zipBytes, '{"hello":"world"}'), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', PackageVerificationException::ENVELOPE);

    // A tampered signature (manifest changed after signing).
    $tampered = json_decode($this->sigJson, true);
    $tampered['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
    $this->post(OU_URL, ouFiles($this->zipBytes, json_encode($tampered)), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', PackageVerificationException::SIGNATURE);

    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);
    Queue::assertNothingPushed();
});

test('a signature by an unknown key is refused', function () {
    Sanctum::actingAs(ouAdmin('upload-wm2@test.local', true));
    $other = sodium_crypto_sign_keypair();
    [, $sig] = ouPackage($this, OU_NAME, null, sodium_crypto_sign_secretkey($other), KeyId::fromPublicKey(sodium_crypto_sign_publickey($other)));

    $this->post(OU_URL, ouFiles($this->zipBytes, $sig), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', PackageVerificationException::KEY_UNKNOWN);

    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);
});

test('a signed manifest naming another module than the zip contents is refused', function () {
    Sanctum::actingAs(ouAdmin('upload-wm3@test.local', true));

    // Signed for "OtherModule" over another zip: refused before anything is staged.
    [$otherZip] = ouPackage($this, 'OtherModule');
    [, $sigForOther] = ouPackage($this, 'OtherModule', null, null, null, $otherZip);
    $this->post(OU_URL, ouFiles($this->zipBytes, $sigForOther), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', ModuleInstaller::ERROR_PACKAGE_MISMATCH);
    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);

    // Signed for "OtherModule" over this very zip (hash matches, contents name ProbeUpload): the install fails in
    // the shared extraction step, nothing is placed and staging is cleaned.
    [, $sigSameZip] = ouPackage($this, OU_NAME, 'OtherModule', null, null, $this->zipBytes);
    $response = $this->post(OU_URL, ouFiles($this->zipBytes, $sigSameZip), ['Accept' => 'application/json'])->assertStatus(202);
    $install = ouRunJob((string) $response->json('data.uuid'));

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->source)->toBe(ModuleInstall::SOURCE_UPLOAD)
        ->and($install->error_code)->toStartWith('MODULE_EXTRACT_')
        ->and(is_dir($this->runtimeDir.'/OtherModule') || is_link($this->runtimeDir.'/OtherModule'))->toBeFalse()
        ->and(is_link($this->runtimeDir.'/'.OU_NAME))->toBeFalse()
        ->and(ouStaging($this))->toBe([])
        ->and($this->httpSent)->toBe([]);
});

test('an oversized package is refused', function () {
    config(['core.module_packages.max_zip_bytes' => 64]);
    Sanctum::actingAs(ouAdmin('upload-wm4@test.local', true));

    $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'MODULE_UPLOAD_TOO_LARGE');

    // The signature file has its own 2 MiB cap.
    config(['core.module_packages.max_zip_bytes' => 50 * 1024 * 1024]);
    $this->post(OU_URL, ouFiles($this->zipBytes, str_repeat(' ', 2 * 1024 * 1024 + 1)), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'MODULE_UPLOAD_TOO_LARGE');

    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);
});

test('PHP upload limits below the package limit block the upload with MODULE_UPLOAD_LIMIT_TOO_LOW', function () {
    ouBindPreflight($this, fn (string $key): string => $key === 'upload_max_filesize' ? '2M' : '8M');
    Sanctum::actingAs(ouAdmin('upload-wm5@test.local', true));

    $response = $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', InstallPreflight::UPLOAD_LIMIT_TOO_LOW);

    expect(ModuleInstall::query()->count())->toBe(0)->and(ouStaging($this))->toBe([]);

    // The hub preflight does not apply the upload limit check.
    $preflight = app(InstallPreflight::class);
    $limits = $preflight->uploadLimitBlocking(50 * 1024 * 1024);
    expect($limits)->toHaveCount(1)
        ->and($limits[0]['details']['upload_max_filesize'])->toBe(2 * 1024 * 1024)
        ->and($limits[0]['details']['post_max_size'])->toBe(8 * 1024 * 1024);
    expect(InstallPreflight::iniBytes('2G'))->toBe(2 * 1024 * 1024 * 1024)
        ->and(InstallPreflight::iniBytes('100M'))->toBe(100 * 1024 * 1024)
        ->and(InstallPreflight::iniBytes('512k'))->toBe(512 * 1024)
        ->and(InstallPreflight::iniBytes('0'))->toBeNull()
        ->and(InstallPreflight::iniBytes('-1'))->toBeNull()
        ->and(InstallPreflight::iniBytes(false))->toBeNull();
});

test('a valid upload installs end to end through the shared job path without any HTTP request', function () {
    $user = ouAdmin('upload-wm6@test.local', true);
    Sanctum::actingAs($user);

    $response = $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('data.name', OU_NAME)
        ->assertJsonPath('data.version', OU_VERSION)
        ->assertJsonPath('data.source', ModuleInstall::SOURCE_UPLOAD)
        ->assertJsonPath('data.status', ModuleInstall::STATUS_QUEUED)
        ->assertJsonMissingPath('data.id');

    $uuid = (string) $response->json('data.uuid');
    Queue::assertPushed(InstallModuleJob::class, fn (InstallModuleJob $job) => $job->installUuid === $uuid);

    // Both files wait in the install's staging folder until the worker picks them up.
    $staged = $this->runtimeDir.'/'.ModuleInstaller::STAGING_FOLDER.'/'.$uuid;
    expect(file_get_contents($staged.'/'.ModuleInstaller::UPLOAD_ZIP))->toBe($this->zipBytes)
        ->and(file_get_contents($staged.'/'.ModuleInstaller::UPLOAD_SIGNATURE))->toBe($this->sigJson);

    // A second upload while this one is unfinished: 409.
    $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'MODULE_INSTALL_RUNNING');

    $install = ouRunJob($uuid);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->source)->toBe(ModuleInstall::SOURCE_UPLOAD)
        ->and($install->zip_sha256)->toBe(hash('sha256', $this->zipBytes))
        ->and($install->key_id)->toBe($this->keyId)
        ->and($install->backup_uuid)->toBe($this->backupUuid)
        ->and(is_link($this->runtimeDir.'/'.OU_NAME))->toBeTrue()
        ->and(is_file($this->runtimeDir.'/'.OU_NAME.'/module.json'))->toBeTrue()
        ->and($this->statuses->calls)->toBe([[OU_NAME, true, $user->id, 'install']])
        ->and(array_map(fn (array $a): string => implode(' ', $a), array_slice($this->artisan->calls, 0, 4)))->toBe([
            'module:migrate '.OU_NAME.' --force',
            'cms:modules-sync '.OU_NAME.' --skip-translations',
            'cms:translation-sync '.OU_NAME,
            'cms:translation-generate --public-only',
        ])
        ->and($this->deviceCalls)->not->toBe([])
        ->and(ouStaging($this))->toBe([])
        ->and($this->httpSent)->toBe([]);

    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();
    expect($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED);
    Http::assertNothingSent();
});

test('upload and hub installs use the same container verifier, extractor and placement', function () {
    // An extractor bound in the container with a 1-file limit (config allows 5000) must be the one that runs.
    $extractor = new SafeExtractor(['max_files' => 1]);
    $this->app->instance(SafeExtractor::class, $extractor);

    $installer = app(ModuleInstaller::class);
    $read = fn (string $prop): object => (new ReflectionProperty(ModuleInstaller::class, $prop))->getValue($installer);
    expect($read('verifier'))->toBe($this->verifier)
        ->and($read('extractor'))->toBe($extractor)
        ->and($read('placement'))->toBe($this->placement);

    Sanctum::actingAs(ouAdmin('upload-wm7@test.local', true));
    $response = $this->post(OU_URL, ouFiles($this->zipBytes, $this->sigJson), ['Accept' => 'application/json'])->assertStatus(202);
    $install = ouRunJob((string) $response->json('data.uuid'));

    // Verified by the container verifier (config trusts only a placeholder key), stopped by the container extractor.
    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(SafeExtractor::TOO_MANY_FILES)
        ->and($install->key_id)->toBe($this->keyId)
        ->and(ouStaging($this))->toBe([])
        ->and($this->httpSent)->toBe([]);
});

test('a manifest listing irreversible migrations carries the names into the upload preflight warning', function () {
    $names = ['2026_01_01_000000_drop_x.php', '2026_01_02_000000_drop_y'];
    [, $sig] = ouPackage($this, OU_NAME, null, null, null, null, $names);
    $manifest = $this->verifier->verify($sig);

    expect($manifest->irreversible)->toBeTrue()
        ->and($manifest->irreversibleMigrations)->toBe($names)
        ->and($manifest->toArray()['irreversible'])->toBe($names);

    $warning = collect(app(InstallPreflight::class)->checkUpload($manifest)->toArray()['warnings'])
        ->firstWhere('code', InstallPreflight::WARN_IRREVERSIBLE);
    expect($warning)->not->toBeNull()
        ->and($warning['details']['migrations'])->toBe($names)
        ->and($warning['details']['listed'])->toBeTrue();
});

test('a boolean irreversible flag still warns, with an empty migration list', function () {
    [, $sig] = ouPackage($this, OU_NAME, null, null, null, null, true);
    $manifest = $this->verifier->verify($sig);

    expect($manifest->irreversible)->toBeTrue()
        ->and($manifest->irreversibleMigrations)->toBe([])
        ->and($manifest->toArray()['irreversible'])->toBeTrue();

    $warning = collect(app(InstallPreflight::class)->checkUpload($manifest)->toArray()['warnings'])
        ->firstWhere('code', InstallPreflight::WARN_IRREVERSIBLE);
    expect($warning)->not->toBeNull()
        ->and($warning['details']['migrations'])->toBe([])
        ->and($warning['details']['listed'])->toBeFalse();
});

test('an irreversible list with a non-string, a path or a duplicate is refused as a schema error', function (array $bad) {
    Sanctum::actingAs(ouAdmin('upload-wm-irr@test.local', true));
    [$zip, $sig] = ouPackage($this, OU_NAME, null, null, null, null, $bad);

    $this->post(OU_URL, ouFiles($zip, $sig), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', PackageVerificationException::SCHEMA);
    expect(ModuleInstall::query()->count())->toBe(0);
})->with([
    'non-string' => [[5]],
    'path' => [['../x']],
    'duplicate' => [['a_b', 'a_b']],
]);

test('an empty irreversible list reads as not irreversible', function () {
    [, $sig] = ouPackage($this, OU_NAME, null, null, null, null, []);
    $manifest = $this->verifier->verify($sig);

    expect($manifest->irreversible)->toBeFalse()->and($manifest->irreversibleMigrations)->toBe([]);
});

/**
 * Build a ProbeUpload package with the real PackageBuilder (module declares the given irreversible migrations) and
 * sign the manifest bytes the builder wrote.
 *
 * @param list<string> $irreversible migration names listed under migrations.irreversible in module.json
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>} zip bytes, signature JSON, builder manifest_data
 */
function ouBuilt(object $t, array $irreversible): array
{
    $root = $t->tmp.'/builder-'.bin2hex(random_bytes(4));
    $module = $root.'/mods/'.OU_NAME;
    mkdir($module.'/app', 0775, true);
    mkdir($module.'/database/migrations', 0775, true);
    file_put_contents($module.'/module.json', json_encode([
        'name' => OU_NAME, 'alias' => 'probe-upload', 'version' => OU_VERSION, 'providers' => ['Cms\\'.OU_NAME.'\\Providers\\'.OU_NAME.'ServiceProvider'], 'files' => [],
        'migrations' => ['irreversible' => $irreversible],
    ]));
    mkdir($module.'/app/Providers', 0775, true);
    file_put_contents($module.'/composer.json', json_encode(['name' => 'ispa/probe-upload', 'autoload' => ['psr-4' => ['Cms\\'.OU_NAME.'\\' => 'app/']]]));
    file_put_contents($module.'/app/Providers/'.OU_NAME."ServiceProvider.php", "<?php\n\nnamespace Cms\\".OU_NAME."\\Providers;\n");
    foreach ($irreversible as $name) {
        file_put_contents($module.'/database/migrations/'.$name.'.php', '<?php');
    }
    file_put_contents($root.'/composer.lock', json_encode(['packages' => [], 'packages-dev' => []]));

    $built = (new PackageBuilder())->build(OU_NAME, $root.'/mods', $root.'/out', $t->keyId, $root.'/composer.lock');

    return [
        (string) file_get_contents($built['zip']),
        (new PackageSigner())->envelope((string) file_get_contents($built['manifest']), $t->signKey),
        $built['manifest_data'],
    ];
}

test('with publish_irreversible_list on the builder publishes the migration names and the upload path accepts them', function () {
    config(['core.module_packages.publish_irreversible_list' => true]);
    $names = ['2026_01_01_000000_drop_x', '2026_01_02_000000_drop_y'];
    [$zip, $sig, $data] = ouBuilt($this, [...$names, '2026_01_01_000000_drop_x']);

    expect($data['irreversible'])->toBe($names);
    $manifest = $this->verifier->verify($sig);
    expect($manifest->irreversible)->toBeTrue()->and($manifest->irreversibleMigrations)->toBe($names);

    Sanctum::actingAs(ouAdmin('upload-wm-list@test.local', true));
    $response = $this->post(OU_URL, ouFiles($zip, $sig) + ['confirm_irreversible' => '1', 'confirm_name' => OU_NAME], ['Accept' => 'application/json'])->assertStatus(202);
    $install = ouRunJob((string) $response->json('data.uuid'));
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED);
});

test('with publish_irreversible_list off the builder keeps the boolean and the package still installs', function () {
    config(['core.module_packages.publish_irreversible_list' => false]);
    [$zip, $sig, $data] = ouBuilt($this, ['2026_01_01_000000_drop_x']);
    expect($data['irreversible'])->toBeTrue();
    expect($this->verifier->verify($sig)->irreversibleMigrations)->toBe([]);

    Sanctum::actingAs(ouAdmin('upload-wm-bool@test.local', true));
    $response = $this->post(OU_URL, ouFiles($zip, $sig) + ['confirm_irreversible' => '1', 'confirm_name' => OU_NAME], ['Accept' => 'application/json'])->assertStatus(202);
    expect(ouRunJob((string) $response->json('data.uuid'))->status)->toBe(ModuleInstall::STATUS_SUCCEEDED);

    config(['core.module_packages.publish_irreversible_list' => true]);
    [, , $none] = ouBuilt($this, []);
    expect($none['irreversible'])->toBeFalse();
});

test('a module listing more irreversible migrations than the manifest allows fails the build when the list is published', function () {
    config(['core.module_packages.publish_irreversible_list' => true]);
    $names = array_map(fn (int $i): string => 'm_'.$i, range(1, 501));
    expect(fn () => ouBuilt($this, $names))->toThrow(PackageBuildException::class, 'irreversible');

    config(['core.module_packages.publish_irreversible_list' => false]);
    expect(ouBuilt($this, $names)[2]['irreversible'])->toBeTrue();
});
