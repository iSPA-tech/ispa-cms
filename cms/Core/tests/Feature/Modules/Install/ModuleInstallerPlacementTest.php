<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const MIP_NAME = 'ProbeInstall';
const MIP_VERSION = '2026.1.0';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-place-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    $this->tmp = realpath($base);
    $this->runtimeDir = $this->tmp.'/runtime';
    $this->releaseDir = $this->tmp.'/release';
    mkdir($this->runtimeDir, 0755);
    mkdir($this->releaseDir, 0755);
    RuntimeModules::setPath($this->runtimeDir);

    // Module statuses go to the test database; the statuses file read on the first write is a temp copy.
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
    ]);

    // Test signing keypair, trusted only inside this test.
    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    $this->trusted = [$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))];

    // Package zip and its signed manifest.
    $files = [
        MIP_NAME.'/module.json' => json_encode(['name' => MIP_NAME, 'version' => MIP_VERSION, 'providers' => ['Cms\\'.MIP_NAME.'\\Providers\\'.MIP_NAME.'ServiceProvider']]),
        MIP_NAME.'/composer.json' => json_encode(['name' => 'ispa/probe-install', 'autoload' => ['psr-4' => ['Cms\\'.MIP_NAME.'\\' => 'app/']]]),
        MIP_NAME.'/app/Providers/'.MIP_NAME.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".MIP_NAME."\\Providers;\n",
    ];
    $zipPath = $this->tmp.'/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $this->zipBytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $this->manifestBytes = json_encode([
        'format' => 1,
        'name' => MIP_NAME,
        'version' => MIP_VERSION,
        'zip_sha256' => hash('sha256', $this->zipBytes),
        'zip_size' => strlen($this->zipBytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => array_map(fn (string $c): string => hash('sha256', $c), $files),
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => $this->keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $this->sigJson = (new PackageSigner())->envelope($this->manifestBytes, $this->signKey);

    // Hub behaviour, changed per test.
    $this->hub = (object) [
        'download_url' => 'https://hub.test/api/v2/public/platform/downloads/'.bin2hex(random_bytes(8)),
        'signature_json' => $this->sigJson,
        'zip' => $this->zipBytes,
    ];
    $hub = $this->hub;
    $secret = $this->secret;
    Http::fake(function (ClientRequest $sent) use ($hub, $secret) {
        if ($sent->method() === 'POST' && str_ends_with($sent->url(), ModuleInstaller::TICKET_PATH)) {
            $body = json_encode(['download_url' => $hub->download_url, 'expires_at' => '2026-11-06T10:05:00+00:00', 'signature_json' => $hub->signature_json]);
            $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

            return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body)]);
        }
        if ($sent->method() === 'GET' && $sent->url() === $hub->download_url) {
            return Http::response($hub->zip, 200);
        }

        return Http::response('', 404);
    });

    $this->backupUuid = (string) Str::uuid();
    $this->gate = Mockery::mock(BackupGate::class);
    $this->enableCalls = [];
});

afterEach(function () {
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    mipRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function mipRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            mipRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Build the installer with the test collaborators; the enable step records its calls.
 */
function mipInstaller(object $t): ModuleInstaller
{
    return new ModuleInstaller(
        $t->gate,
        new BackgroundTaskRecorder(),
        new PackageVerifier($t->trusted, []),
        null,
        null,
        new DatabaseActivator(app()),
        null,
        function (ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task, string $placed) use ($t): void {
            $t->enableCalls[] = [$install->uuid, $manifest->name, $manifest->version, $placed];
        },
        $t->releaseDir,
    );
}

/**
 * A queued install row with its background task, as the endpoint creates it.
 */
function mipInstall(): ModuleInstall
{
    $task = (new BackgroundTaskRecorder())->start(ModuleInstall::TASK_TYPE, 'Core', null);

    return ModuleInstall::query()->create([
        'name' => MIP_NAME,
        'version' => MIP_VERSION,
        'source' => ModuleInstall::SOURCE_HUB,
        'status' => ModuleInstall::STATUS_QUEUED,
        'background_task_uuid' => $task->uuid,
    ]);
}

/**
 * Let the backup gate succeed with a fake backup.
 */
function mipBackupOk(object $t): void
{
    $backup = (new Backup())->forceFill(['uuid' => $t->backupUuid]);
    $t->gate->shouldReceive('ensure')->once()->with(Backup::REASON_PRE_INSTALL, null)->andReturn($backup);
}

/**
 * Entries left in the staging folder.
 *
 * @return list<string>
 */
function mipStaging(object $t): array
{
    return array_values(array_diff(@scandir($t->runtimeDir.'/'.ModuleInstaller::STAGING_FOLDER) ?: [], ['.', '..']));
}

/**
 * Assert the install failed with $code, nothing was placed and the staging folder is empty.
 */
function mipAssertFailed(object $t, ModuleInstall $install, string $code): void
{
    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe($code)
        ->and($install->finished_at)->not->toBeNull()
        ->and($task->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and(mipStaging($t))->toBe([])
        ->and(file_exists($t->runtimeDir.'/'.MIP_NAME) || is_link($t->runtimeDir.'/'.MIP_NAME))->toBeFalse()
        ->and(is_dir($t->runtimeDir.'/.versions/'.MIP_NAME.'-'.MIP_VERSION))->toBeFalse()
        ->and($t->enableCalls)->toBe([])
        ->and(DB::table('module_statuses')->where('name', MIP_NAME)->exists())->toBeFalse()
        ->and((string) $task->message)->not->toContain($t->secret)
        ->and(json_encode($task->message_params))->not->toContain($t->secret)
        ->and((string) $install->step_message)->not->toContain($t->secret);
}

test('happy path: backup, ticket, signed download, verify, extract, place, version row, enable stub', function () {
    mipBackupOk($this);
    $install = mipInstall();

    mipInstaller($this)->run($install);

    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();
    $link = $this->runtimeDir.'/'.MIP_NAME;

    expect($install->status)->toBe(ModuleInstall::STATUS_PLACING)
        ->and($install->error_code)->toBeNull()
        ->and($install->finished_at)->not->toBeNull()
        ->and($install->backup_uuid)->toBe($this->backupUuid)
        ->and($install->zip_sha256)->toBe(hash('sha256', $this->zipBytes))
        ->and($install->key_id)->toBe($this->keyId)
        ->and(is_link($link))->toBeTrue()
        ->and(readlink($link))->toBe('.versions/'.MIP_NAME.'-'.MIP_VERSION)
        ->and(is_file($link.'/module.json'))->toBeTrue()
        ->and(mipStaging($this))->toBe([])
        ->and($this->enableCalls)->toHaveCount(1)
        ->and($this->enableCalls[0][0])->toBe($install->uuid)
        ->and($this->enableCalls[0][3])->toBe($this->runtimeDir.'/.versions/'.MIP_NAME.'-'.MIP_VERSION)
        ->and($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($task->result)->toMatchArray(['placed' => true, 'enabled' => false]);

    $row = DB::table('module_statuses')->where('name', MIP_NAME)->first();
    expect($row)->not->toBeNull()->and($row->version)->toBe(MIP_VERSION)->and((bool) $row->enabled)->toBeFalse();

    // The signed hashes were kept: the placed files match them.
    expect((new \Cms\Core\Services\Modules\Install\ModuleIntegrity($this->runtimeDir, new PackageVerifier($this->trusted, [])))->check(MIP_NAME)['state'])->toBe('ok');

    // The download was a signed request to the hub; the secret never travelled.
    Http::assertSent(function (ClientRequest $sent): bool {
        return $sent->method() === 'GET' && $sent->url() === $this->hub->download_url
            && $sent->hasHeader(PlatformSignature::HEADER_SIGNATURE) && $sent->hasHeader(PlatformSignature::HEADER_NONCE);
    });
    Http::assertSentCount(2);
    Http::assertNotSent(fn (ClientRequest $sent): bool => str_contains(json_encode($sent->headers()).$sent->body(), $this->secret));
});

test('a backup failure stops before anything is downloaded', function () {
    $this->gate->shouldReceive('ensure')->once()->andThrow(new BackupRequiredException('BACKUP_ALL_DESTINATIONS_FAILED'));
    $install = mipInstall();

    mipInstaller($this)->run($install);

    mipAssertFailed($this, $install, BackupRequiredException::CODE);
    expect($install->backup_uuid)->toBeNull();
    Http::assertNothingSent();
});

test('a download larger than the manifest zip_size is cut off and fails', function () {
    mipBackupOk($this);
    $this->hub->zip = $this->zipBytes.str_repeat('X', 200000);
    $install = mipInstall();

    mipInstaller($this)->run($install);

    mipAssertFailed($this, $install, ModuleInstaller::ERROR_DOWNLOAD_TOO_LARGE);
});

test('a download_url on another host is refused without contacting it', function () {
    mipBackupOk($this);
    $this->hub->download_url = 'https://evil.test/api/v2/public/platform/downloads/abc';
    $install = mipInstall();

    mipInstaller($this)->run($install);

    mipAssertFailed($this, $install, ModuleInstaller::ERROR_DOWNLOAD_HOST);
    Http::assertNotSent(fn (ClientRequest $sent): bool => str_contains($sent->url(), 'evil.test'));
    Http::assertSentCount(1);
});

test('a bad signature leaves staging empty and places nothing', function () {
    mipBackupOk($this);
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $doc = json_decode($this->sigJson, true);
    $doc['signature'] = base64_encode(sodium_crypto_sign_detached($this->manifestBytes, $other));
    $this->hub->signature_json = json_encode($doc);
    $install = mipInstall();

    mipInstaller($this)->run($install);

    mipAssertFailed($this, $install, PackageVerificationException::SIGNATURE);
    Http::assertNotSent(fn (ClientRequest $sent): bool => $sent->method() === 'GET');
});

test('a validly signed package for another version is refused', function () {
    mipBackupOk($this);
    $install = mipInstall();
    $install->forceFill(['version' => '2026.2.0'])->save();

    mipInstaller($this)->run($install);

    $install->refresh();
    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_PACKAGE_MISMATCH)
        ->and(is_link($this->runtimeDir.'/'.MIP_NAME))->toBeFalse()
        ->and(mipStaging($this))->toBe([]);
});
