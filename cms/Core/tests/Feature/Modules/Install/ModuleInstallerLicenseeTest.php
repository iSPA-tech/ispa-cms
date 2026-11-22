<?php

declare(strict_types=1);

/*
 * site side: a premium hub download may be a per-grant copy with `<Name>/LICENSEE.json` and
 * `<Name>/LICENSEE.sig` added. The signed ticket answer names its zip hash and size; the installer checks the copy
 * against them, SafeExtractor still checks every signed file, and the module is placed with the mark. Older hubs send
 * no `licensee` and the signed package installs as before.
 */

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

const MIL_NAME = 'ProbeLicensed';
const MIL_VERSION = '2026.1.0';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-licensee-'.bin2hex(random_bytes(6));
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
        MIL_NAME.'/module.json' => json_encode(['name' => MIL_NAME, 'version' => MIL_VERSION, 'edition' => 'premium', 'providers' => ['Cms\\'.MIL_NAME.'\\Providers\\'.MIL_NAME.'ServiceProvider']]),
        MIL_NAME.'/composer.json' => json_encode(['name' => 'ispa/probe-licensed', 'autoload' => ['psr-4' => ['Cms\\'.MIL_NAME.'\\' => 'app/']]]),
        MIL_NAME.'/app/Providers/'.MIL_NAME.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".MIL_NAME."\\Providers;\n",
    ];
    $zipPath = $this->tmp.'/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $this->zipBytes = (string) file_get_contents($zipPath);
    $this->files = $files;
    unlink($zipPath);

    $this->manifestBytes = json_encode([
        'format' => 1,
        'name' => MIL_NAME,
        'version' => MIL_VERSION,
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
        'licensee' => null,
        'ticket_body' => null,
    ];
    $hub = $this->hub;
    $secret = $this->secret;
    Http::fake(function (ClientRequest $sent) use ($hub, $secret) {
        if ($sent->method() === 'POST' && str_ends_with($sent->url(), ModuleInstaller::TICKET_PATH)) {
            $hub->ticket_body = json_decode($sent->body(), true);
            $answer = ['download_url' => $hub->download_url, 'expires_at' => '2026-11-06T10:05:00+00:00', 'signature_json' => $hub->signature_json];
            if ($hub->licensee !== null) {
                $answer['licensee'] = $hub->licensee;
            }
            $body = json_encode($answer);
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
    milRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function milRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            milRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Build the installer with the test collaborators; the enable step records its calls.
 */
function milInstaller(object $t): ModuleInstaller
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
function milInstall(): ModuleInstall
{
    $task = (new BackgroundTaskRecorder())->start(ModuleInstall::TASK_TYPE, 'Core', null);

    return ModuleInstall::query()->create([
        'name' => MIL_NAME,
        'version' => MIL_VERSION,
        'source' => ModuleInstall::SOURCE_HUB,
        'status' => ModuleInstall::STATUS_QUEUED,
        'background_task_uuid' => $task->uuid,
    ]);
}

/**
 * Let the backup gate succeed with a fake backup.
 */
function milBackupOk(object $t): void
{
    $backup = (new Backup())->forceFill(['uuid' => $t->backupUuid]);
    $t->gate->shouldReceive('ensure')->once()->with(Backup::REASON_PRE_INSTALL, null)->andReturn($backup);
}

/**
 * Entries left in the staging folder.
 *
 * @return list<string>
 */
function milStaging(object $t): array
{
    return array_values(array_diff(@scandir($t->runtimeDir.'/'.ModuleInstaller::STAGING_FOLDER) ?: [], ['.', '..']));
}

/**
 * Assert the install failed with $code, nothing was placed and the staging folder is empty.
 */
function milAssertFailed(object $t, ModuleInstall $install, string $code): void
{
    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe($code)
        ->and($install->finished_at)->not->toBeNull()
        ->and($task->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and(milStaging($t))->toBe([])
        ->and(file_exists($t->runtimeDir.'/'.MIL_NAME) || is_link($t->runtimeDir.'/'.MIL_NAME))->toBeFalse()
        ->and(is_dir($t->runtimeDir.'/.versions/'.MIL_NAME.'-'.MIL_VERSION))->toBeFalse()
        ->and($t->enableCalls)->toBe([])
        ->and(DB::table('module_statuses')->where('name', MIL_NAME)->exists())->toBeFalse()
        ->and((string) $task->message)->not->toContain($t->secret)
        ->and(json_encode($task->message_params))->not->toContain($t->secret)
        ->and((string) $install->step_message)->not->toContain($t->secret);
}

/**
 * Build a marked copy of the package (signed files plus the licensee files) and let the hub serve it.
 *
 * @param array<string, string>|null $files signed files to put in the copy (default: the signed set)
 */
function milServeCopy(object $t, ?array $files = null, array $mark = []): string
{
    $mark = $mark ?: [
        MIL_NAME.'/LICENSEE.json' => json_encode(['schema' => 1, 'grant' => 'grant-uuid', 'domain' => 'site.example.test', 'date' => '2026-11-15T03:00:00+00:00', 'module' => MIL_NAME, 'version' => MIL_VERSION]),
        MIL_NAME.'/LICENSEE.sig' => json_encode(['alg' => 'hmac-sha256', 'signature' => base64_encode(str_repeat('s', 32))]),
    ];
    $path = $t->tmp.'/copy.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (($files ?? $t->files) + $mark as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $t->hub->zip = (string) file_get_contents($path);
    unlink($path);
    $t->hub->licensee = ['zip_sha256' => hash('sha256', $t->hub->zip), 'zip_size' => strlen($t->hub->zip)];

    return $t->hub->zip;
}

/**
 * Run one install and return the refreshed row.
 */
function milRun(object $t): ModuleInstall
{
    $install = milInstall();
    milInstaller($t)->run($install);

    return $install->refresh();
}

test('the ticket request asks for the buyer mark', function () {
    milBackupOk($this);
    milRun($this);

    expect($this->hub->ticket_body)->toBe(['name' => MIL_NAME, 'version' => MIL_VERSION, 'licensee' => true]);
});

test('a marked premium copy named by the ticket installs with LICENSEE.json in the module folder', function () {
    milBackupOk($this);
    milServeCopy($this);

    $install = milRun($this);

    $link = $this->runtimeDir.'/'.MIL_NAME;
    expect($install->status)->toBe(ModuleInstall::STATUS_PLACING)
        ->and($install->error_code)->toBeNull()
        ->and($install->zip_sha256)->toBe(hash('sha256', $this->zipBytes))
        ->and(json_decode((string) file_get_contents($link.'/LICENSEE.json'), true)['grant'])->toBe('grant-uuid')
        ->and(is_file($link.'/LICENSEE.sig'))->toBeTrue()
        ->and(milStaging($this))->toBe([])
        ->and($this->enableCalls)->toHaveCount(1);
    // The integrity report still reads the signed files as unchanged; the mark is not one of them.
    expect((new \Cms\Core\Services\Modules\Install\ModuleIntegrity($this->runtimeDir, new PackageVerifier($this->trusted, [])))->check(MIL_NAME)['state'])->toBe('ok');
});

test('an older hub that names no copy still installs the signed package', function () {
    milBackupOk($this);

    $install = milRun($this);

    expect($install->status)->toBe(ModuleInstall::STATUS_PLACING)
        ->and(file_exists($this->runtimeDir.'/'.MIL_NAME.'/LICENSEE.json'))->toBeFalse();
});

test('a marked copy the ticket does not name is refused as a package mismatch', function () {
    milBackupOk($this);
    milServeCopy($this);
    $this->hub->licensee = null;

    $install = milRun($this);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED);
    expect(in_array($install->error_code, [ModuleInstaller::ERROR_PACKAGE_MISMATCH, ModuleInstaller::ERROR_DOWNLOAD_TOO_LARGE], true))->toBeTrue();
    expect(file_exists($this->runtimeDir.'/'.MIL_NAME))->toBeFalse();
});

test('a marked copy with a changed signed file is refused', function () {
    milBackupOk($this);
    $files = $this->files;
    $files[MIL_NAME.'/app/Providers/'.MIL_NAME.'ServiceProvider.php'] .= "// changed\n";
    milServeCopy($this, $files);

    $install = milRun($this);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and(file_exists($this->runtimeDir.'/'.MIL_NAME))->toBeFalse();
});

test('a malformed or oversized copy description in the ticket is refused before downloading', function (array $licensee) {
    milBackupOk($this);
    $this->hub->licensee = $licensee;

    $install = milRun($this);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)->and($install->error_code)->toBe(ModuleInstaller::ERROR_TICKET);
    Http::assertSentCount(1);
})->with([
    'bad hash' => [['zip_sha256' => 'nothex', 'zip_size' => 10]],
    'size as text' => [['zip_sha256' => str_repeat('a', 64), 'zip_size' => '10']],
    'far too large' => [['zip_sha256' => str_repeat('a', 64), 'zip_size' => 100_000_000]],
]);

test('a marked copy of a package that is not premium is refused', function () {
    $this->files[MIL_NAME.'/module.json'] = json_encode(['name' => MIL_NAME, 'version' => MIL_VERSION, 'edition' => 'open']);
    $manifest = json_decode($this->manifestBytes, true);
    $manifest['files'][MIL_NAME.'/module.json'] = hash('sha256', $this->files[MIL_NAME.'/module.json']);
    $manifest['unpacked_size'] = array_sum(array_map('strlen', $this->files));
    $this->hub->signature_json = (new PackageSigner())->envelope(json_encode($manifest, JSON_UNESCAPED_SLASHES), $this->signKey);
    milBackupOk($this);
    milServeCopy($this);

    $install = milRun($this);

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(\Cms\Core\Services\Modules\Packages\SafeExtractor::LICENSEE)
        ->and(file_exists($this->runtimeDir.'/'.MIL_NAME))->toBeFalse();
});
