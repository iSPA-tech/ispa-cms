<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * the first install of a module that iSPA does not publish needs an explicit, recorded confirmation on every
 * path (hub install, offline upload, starter pack CLI), and the installer refuses a partner package without it. iSPA
 * modules install exactly as before. The hub, signing keys and packages are faked in a temp folder.
 */
const TP_VERSION = '2026.1.0';
const TP_INSTALL_URL = '/api/v2/admin/utilities/modules/install';
const TP_UPLOAD_URL = '/api/v2/admin/utilities/modules/upload';
const TP_ACME = ['key' => 'acme', 'type' => 'partner', 'name' => 'Acme Studio'];

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([AuditLogEvent::class]);
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir().'/third-party-'.bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp.'/release';
    $this->runtimeDir = $this->tmp.'/runtime';
    mkdir($this->releaseDir, 0755, true);
    mkdir($this->runtimeDir, 0755, true);
    RuntimeModules::setPath($this->runtimeDir);

    // Two throwaway signing keys: an iSPA release key and a hub countersign key (partner packages).
    $ispa = sodium_crypto_sign_keypair();
    $hub = sodium_crypto_sign_keypair();
    $this->ispaSecret = sodium_crypto_sign_secretkey($ispa);
    $this->ispaKeyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($ispa));
    $this->hubSecret = sodium_crypto_sign_secretkey($hub);
    $this->hubKeyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($hub));
    $trusted = [
        $this->ispaKeyId => base64_encode(sodium_crypto_sign_publickey($ispa)),
        $this->hubKeyId => base64_encode(sodium_crypto_sign_publickey($hub)),
    ];
    $this->app->instance(PackageVerifier::class, new PackageVerifier($trusted, []));

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.trusted_keys' => $trusted,
        'core.module_packages.countersign_key_ids' => [$this->hubKeyId],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);

    // Healthy local environment; the hub answers through tpFakeHub().
    $this->app->instance(InstallPreflight::class, new InstallPreflight(
        new ModuleCatalog(null, $this->releaseDir),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
        fn (string $key): string => '0',
    ));

    $this->hubModules = [];
    $this->listingSheet = null;
    tpFakeHub($this);
});

afterEach(function () {
    RuntimeModules::resetPath();
    tpRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function tpRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            tpRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Fake a correctly signed hub: the catalog lists `$t->hubModules`, a listing answers with `$t->listingSheet`.
 */
function tpFakeHub(object $t): void
{
    Http::fake(function (ClientRequest $sent) use ($t) {
        $payload = str_contains($sent->url(), '/listings/')
            ? ['name' => basename(parse_url($sent->url(), PHP_URL_PATH)), 'listing' => null, 'installs' => 0, 'versions' => [['version' => TP_VERSION, 'revoked' => false, 'sheet' => $t->listingSheet]]]
            : ['modules' => $t->hubModules];
        $body = json_encode($payload);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($t->secret, 200, $nonce, $body)]);
    });
}

/**
 * One catalog entry; a partner module carries the entry-level publisher the hub sends.
 *
 * @return array<string, mixed>
 */
function tpEntry(string $name, ?array $publisher = null): array
{
    $entry = ['name' => $name, 'latest_version' => TP_VERSION, 'versions' => [[
        'version' => TP_VERSION, 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0',
    ]]];

    return $publisher === null ? $entry : $entry + ['publisher' => $publisher];
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function tpWebmaster(): User
{
    $user = User::query()->create(['name' => 'Install Admin', 'email' => 'wm-'.Str::random(6).'@example.test', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

    return $user->fresh();
}

/**
 * Build a package zip for $name and its sig.json signed with the given secret key.
 *
 * @return array{0: string, 1: string} zip bytes, signature JSON
 */
function tpPackage(string $name, string $secret, string $keyId): array
{
    $files = [
        $name.'/module.json' => json_encode(['name' => $name, 'version' => TP_VERSION, 'providers' => ['Cms\\'.$name.'\\Providers\\'.$name.'ServiceProvider']]),
        $name.'/composer.json' => json_encode(['name' => 'ispa/'.strtolower($name), 'autoload' => ['psr-4' => ['Cms\\'.$name.'\\' => 'app/']]]),
        $name.'/app/Providers/'.$name.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".$name."\\Providers;\n",
    ];
    $zipPath = sys_get_temp_dir().'/tp-build-'.bin2hex(random_bytes(4)).'.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $zipBytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $manifest = json_encode([
        'format' => 1,
        'name' => $name,
        'version' => TP_VERSION,
        'zip_sha256' => hash('sha256', $zipBytes),
        'zip_size' => strlen($zipBytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => array_map(static fn (string $c): string => hash('sha256', $c), $files),
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-16T10:00:00Z',
        'key_id' => $keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return [$zipBytes, (new PackageSigner())->envelope($manifest, $secret)];
}

/**
 * POST an offline upload of the given package.
 *
 * @param array<string, string> $fields extra multipart fields
 */
function tpUpload(object $t, array $package, array $fields = []): \Illuminate\Testing\TestResponse
{
    $zip = $t->tmp.'/upload-'.bin2hex(random_bytes(4)).'.zip';
    $sig = $t->tmp.'/upload-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($zip, $package[0]);
    file_put_contents($sig, $package[1]);

    return $t->post(TP_UPLOAD_URL, [
        'package' => new UploadedFile($zip, 'package.zip', 'application/zip', null, true),
        'signature' => new UploadedFile($sig, 'sig.json', 'application/json', null, true),
    ] + $fields, ['Accept' => 'application/json']);
}

/**
 * A succeeded, confirmed install of a module of publisher $key.
 */
function tpConfirmedBefore(string $key): void
{
    ModuleInstall::query()->create([
        'name' => 'EarlierPartner', 'version' => '2026.0.1', 'source' => ModuleInstall::SOURCE_HUB, 'kind' => ModuleInstall::KIND_INSTALL,
        'status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => Carbon::now(), 'warnings' => [],
        'third_party_publisher' => $key, 'third_party_confirmed_by' => 1, 'third_party_confirmed_at' => Carbon::now(),
    ]);
}

// ---- Hub install --------------------------------------------------------------------------------------------------

test('an iSPA module installs exactly as before: no partner warning, no confirmation, nothing recorded', function () {
    $this->hubModules = [tpEntry('Probe')];
    Sanctum::actingAs(tpWebmaster());

    $result = app(InstallPreflight::class)->check('Probe', TP_VERSION);
    expect($result->warningCodes())->not->toContain(InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL);

    $this->postJson(TP_INSTALL_URL, ['name' => 'Probe', 'version' => TP_VERSION])->assertStatus(202)
        ->assertJsonPath('data.third_party', false)
        ->assertJsonPath('data.third_party_confirmed', false);

    $row = ModuleInstall::query()->sole();
    expect($row->third_party_publisher)->toBeNull()
        ->and($row->third_party_confirmed_by)->toBeNull()
        ->and($row->third_party_confirmed_at)->toBeNull();
    Event::assertNotDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'module.third_party_confirmed');
    Queue::assertPushed(InstallModuleJob::class);
});

test('a partner module warns with its publisher and the impact from the technical sheet', function () {
    $this->hubModules = [tpEntry('Gallery', TP_ACME)];
    $this->listingSheet = [
        'database' => ['migrations' => 2, 'tables_created' => ['gallery_items'], 'tables_altered' => ['users'], 'irreversible' => false],
        'admin' => ['permissions' => ['gallery.view', 'gallery.edit'], 'menus' => 1],
        'routes' => ['public' => 2, 'admin' => 3, 'api' => 4],
    ];

    $result = app(InstallPreflight::class)->check('Gallery', TP_VERSION);
    $warning = collect($result->warnings)->firstWhere('code', InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL);

    expect($result->canInstall())->toBeTrue()
        ->and($warning)->not->toBeNull()
        ->and($warning['details']['publisher'])->toBe(TP_ACME)
        ->and($warning['details']['impact']['permissions'])->toBe(['gallery.view', 'gallery.edit'])
        ->and($warning['details']['impact']['routes'])->toBe(['public' => 2, 'admin' => 3, 'api' => 4])
        ->and($warning['details']['impact']['tables_created'])->toBe(['gallery_items'])
        ->and($warning['details']['impact']['tables_altered'])->toBe(['users']);
});

test('a partner module without the confirmation is refused and nothing is queued', function () {
    $this->hubModules = [tpEntry('Gallery', TP_ACME)];
    Sanctum::actingAs(tpWebmaster());

    $this->postJson(TP_INSTALL_URL, ['name' => 'Gallery', 'version' => TP_VERSION])
        ->assertStatus(422)
        ->assertJsonPath('error_code', InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED)
        ->assertJsonPath('data.publisher.key', 'acme');
    // A truthy string is not a confirmation on the JSON path.
    $this->postJson(TP_INSTALL_URL, ['name' => 'Gallery', 'version' => TP_VERSION, 'confirm_third_party' => 'yes'])
        ->assertStatus(422);

    expect(ModuleInstall::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a confirmed partner install records who confirmed it, and audits it', function () {
    $this->hubModules = [tpEntry('Gallery', TP_ACME)];
    $user = tpWebmaster();
    Sanctum::actingAs($user);

    $this->postJson(TP_INSTALL_URL, ['name' => 'Gallery', 'version' => TP_VERSION, 'confirm_third_party' => true])
        ->assertStatus(202)
        ->assertJsonPath('data.third_party', true)
        ->assertJsonPath('data.third_party_confirmed', true)
        ->assertJsonMissingPath('data.third_party_confirmed_by');

    $row = ModuleInstall::query()->sole();
    expect($row->third_party_publisher)->toBe('acme')
        ->and($row->third_party_confirmed_by)->toBe((int) $user->id)
        ->and($row->third_party_confirmed_at)->not->toBeNull();
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'module.third_party_confirmed'
        && $e->changed['third_party_publisher'] === 'acme' && $e->id === (int) $row->id && $e->user_id === (int) $user->id);
});

test('a later module of a publisher confirmed before installs without asking again, and keeps the publisher', function () {
    $this->hubModules = [tpEntry('Gallery', TP_ACME)];
    tpConfirmedBefore('acme');
    Sanctum::actingAs(tpWebmaster());

    expect(app(InstallPreflight::class)->check('Gallery', TP_VERSION)->warningCodes())
        ->not->toContain(InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL);

    $this->postJson(TP_INSTALL_URL, ['name' => 'Gallery', 'version' => TP_VERSION])->assertStatus(202);
    $row = ModuleInstall::query()->where('name', 'Gallery')->sole();
    expect($row->third_party_publisher)->toBe('acme')->and($row->third_party_confirmed_at)->toBeNull();
});

test('another publisher, or an earlier confirmed install that failed, asks again', function () {
    $this->hubModules = [tpEntry('Gallery', TP_ACME)];
    tpConfirmedBefore('other-studio');
    ModuleInstall::query()->create([
        'name' => 'Broken', 'version' => '2026.0.1', 'source' => ModuleInstall::SOURCE_HUB, 'kind' => ModuleInstall::KIND_INSTALL,
        'status' => ModuleInstall::STATUS_FAILED, 'finished_at' => Carbon::now(), 'warnings' => [],
        'third_party_publisher' => 'acme', 'third_party_confirmed_by' => 1, 'third_party_confirmed_at' => Carbon::now(),
    ]);

    expect(app(InstallPreflight::class)->check('Gallery', TP_VERSION)->warningCodes())
        ->toContain(InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL);
});

// ---- Offline upload -----------------------------------------------------------------------------------------------

test('an offline upload signed with an iSPA key installs as before', function () {
    $this->hubModules = [];
    Sanctum::actingAs(tpWebmaster());

    tpUpload($this, tpPackage('Uploaded', $this->ispaSecret, $this->ispaKeyId))->assertStatus(202);
    expect(ModuleInstall::query()->sole()->third_party_publisher)->toBeNull();
});

test('an offline upload of a hub-countersigned package asks, even with no hub at all', function () {
    // Site not connected to the hub: the publisher is unknown, the countersign key decides.
    config(['ispa-cms.cms_domain' => '', 'ispa-cms.cms_domain_secret' => '']);
    $user = tpWebmaster();
    Sanctum::actingAs($user);
    $package = tpPackage('Uploaded', $this->hubSecret, $this->hubKeyId);

    tpUpload($this, $package)
        ->assertStatus(422)
        ->assertJsonPath('error_code', InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED)
        ->assertJsonPath('data.publisher', null);
    expect(ModuleInstall::query()->count())->toBe(0);

    tpUpload($this, $package, ['confirm_third_party' => '1'])->assertStatus(202);
    $row = ModuleInstall::query()->sole();
    expect($row->third_party_publisher)->toBe(ModuleInstall::PUBLISHER_UNKNOWN)
        ->and($row->third_party_confirmed_by)->toBe((int) $user->id);
    Http::assertNothingSent();
});

test('an offline upload of a module the hub names as a partner module asks too', function () {
    $this->hubModules = [tpEntry('Uploaded', TP_ACME)];
    Sanctum::actingAs(tpWebmaster());

    tpUpload($this, tpPackage('Uploaded', $this->ispaSecret, $this->ispaKeyId))
        ->assertStatus(422)
        ->assertJsonPath('data.publisher.key', 'acme');
});

// ---- Installer gate -----------------------------------------------------------------------------------------------

test('the installer refuses a countersigned package whose row carries no confirmation, before anything is placed', function () {
    $package = tpPackage('Uploaded', $this->hubSecret, $this->hubKeyId);
    $run = function (array $extra) use ($package): ModuleInstall {
        $uuid = (string) Str::uuid();
        $zip = $this->tmp.'/'.$uuid.'.zip';
        $sig = $this->tmp.'/'.$uuid.'.json';
        file_put_contents($zip, $package[0]);
        file_put_contents($sig, $package[1]);
        ModuleInstaller::stageUpload($uuid, $zip, $sig);
        $row = ModuleInstall::query()->create($extra + [
            'uuid' => $uuid, 'name' => 'Uploaded', 'version' => TP_VERSION, 'source' => ModuleInstall::SOURCE_UPLOAD,
            'kind' => ModuleInstall::KIND_INSTALL, 'status' => ModuleInstall::STATUS_QUEUED, 'backup_skipped' => true, 'warnings' => [],
        ]);
        app(ModuleInstaller::class)->run($row);

        return $row->refresh();
    };
    $gate = Mockery::mock(BackupGate::class);
    $gate->shouldReceive('ensure')->andReturn((new Backup())->forceFill(['uuid' => (string) Str::uuid()]));
    $this->app->instance(BackupGate::class, $gate);

    // Recorded as an iSPA module (no publisher data): the signing key still marks it as a partner package.
    $refused = $run([]);
    expect($refused->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($refused->error_code)->toBe(InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED)
        ->and(file_exists($this->runtimeDir.'/Uploaded'))->toBeFalse();

    // Marked as a partner module by the preflight, never confirmed: refused as well.
    expect($run(['third_party_publisher' => 'acme'])->error_code)->toBe(InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED);

    // Confirmed: the gate lets it through (whatever the later steps of this bare test environment do).
    $confirmed = $run(['third_party_publisher' => ModuleInstall::PUBLISHER_UNKNOWN, 'third_party_confirmed_by' => 1, 'third_party_confirmed_at' => Carbon::now()]);
    expect($confirmed->error_code)->not->toBe(InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED);
});

// ---- Starter pack CLI ---------------------------------------------------------------------------------------------

test('the starter pack install needs --confirm-third-party for a partner module', function () {
    expect(Artisan::all()['cms:starter-pack-apply']->getDefinition()->hasOption('confirm-third-party'))->toBeTrue();

    $warning = ['code' => InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL, 'message' => 'x', 'details' => ['publisher' => TP_ACME, 'impact' => null]];
    $installer = Mockery::mock(ModuleInstaller::class);
    $installer->shouldReceive('run')->andReturnUsing(function (ModuleInstall $row): void {
        $row->forceFill(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => Carbon::now()])->save();
    });
    $applier = app()->make(StarterPackApplier::class, [
        'installer' => $installer,
        'preflight' => fn (string $name, string $version): PreflightResult => new PreflightResult($name, $version, [], [$warning], [], 'install', null, []),
    ]);
    $install = (new ReflectionClass($applier))->getMethod('install');

    $refused = $install->invoke($applier, 'Gallery', TP_VERSION, null, false, false);
    expect($refused)->toContain('--confirm-third-party')
        ->and(ModuleInstall::query()->count())->toBe(0);

    $user = tpWebmaster();
    expect($install->invoke($applier, 'Gallery', TP_VERSION, (int) $user->id, false, true))->toBeNull();
    $row = ModuleInstall::query()->sole();
    expect($row->third_party_publisher)->toBe('acme')
        ->and($row->third_party_confirmed_by)->toBe((int) $user->id);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'module.third_party_confirmed');
});
