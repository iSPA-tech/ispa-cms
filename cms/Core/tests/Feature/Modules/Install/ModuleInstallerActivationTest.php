<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * after placement the installer enables the module through ModuleStatusService (faked: it writes the
 * test-database status row only), runs migrate/sync/translation and cache commands through ArtisanSubprocess (faked:
 * records argv, runs nothing) and audit-logs the result. Runtime folder, statuses file and cache files are temp paths.
 */
const MIA_NAME = 'ProbeActivate';
const MIA_VERSION = '2026.1.0';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();

    $base = sys_get_temp_dir().'/install-activate-'.bin2hex(random_bytes(6));
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

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
    ]);

    $pair = sodium_crypto_sign_keypair();
    $this->signKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey(sodium_crypto_sign_publickey($pair));
    $this->trusted = [$this->keyId => base64_encode(sodium_crypto_sign_publickey($pair))];

    miaPackage($this, false);

    $this->downloadUrl = 'https://hub.test/api/v2/public/platform/downloads/'.bin2hex(random_bytes(8));
    $t = $this;
    Http::fake(function (ClientRequest $sent) use ($t) {
        if ($sent->method() === 'POST' && str_ends_with($sent->url(), ModuleInstaller::TICKET_PATH)) {
            $body = json_encode(['download_url' => $t->downloadUrl, 'expires_at' => '2026-11-06T10:05:00+00:00', 'signature_json' => $t->sigJson]);
            $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

            return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($t->secret, 200, $nonce, $body)]);
        }
        if ($sent->method() === 'GET' && $sent->url() === $t->downloadUrl) {
            return Http::response($t->zipBytes, 200);
        }

        return Http::response('', 404);
    });

    $this->backupUuid = (string) Str::uuid();
    $this->gate = Mockery::mock(BackupGate::class);
    $this->gate->shouldReceive('ensure')->once()->andReturn((new Backup())->forceFill(['uuid' => $this->backupUuid]));

    // Any artisan command started inside this process (Artisan::call) is recorded here.
    $this->inProcess = [];
    Event::listen(CommandStarting::class, function (CommandStarting $event): void {
        $this->inProcess[] = $event->command;
    });

    $this->user = User::factory()->create();
});

afterEach(function () {
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $var) {
        putenv($var);
        unset($_SERVER[$var]);
    }
    RuntimeModules::resetPath();
    DatabaseActivator::flushProcessState();
    miaRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function miaRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            miaRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Build the package zip and its signed manifest on $t.
 */
function miaPackage(object $t, bool $customCode): void
{
    $files = [
        MIA_NAME.'/module.json' => json_encode(['name' => MIA_NAME, 'version' => MIA_VERSION, 'providers' => ['Cms\\'.MIA_NAME.'\\Providers\\'.MIA_NAME.'ServiceProvider']]),
        MIA_NAME.'/composer.json' => json_encode(['name' => 'ispa/probe-activate', 'autoload' => ['psr-4' => ['Cms\\'.MIA_NAME.'\\' => 'app/']]]),
        MIA_NAME.'/app/Providers/'.MIA_NAME.'ServiceProvider.php' => "<?php\n\nnamespace Cms\\".MIA_NAME."\\Providers;\n",
    ];
    $zipPath = $t->tmp.'/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $t->zipBytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    $manifest = json_encode([
        'format' => 1,
        'name' => MIA_NAME,
        'version' => MIA_VERSION,
        'zip_sha256' => hash('sha256', $t->zipBytes),
        'zip_size' => strlen($t->zipBytes),
        'unpacked_size' => array_sum(array_map('strlen', $files)),
        'files' => array_map(fn (string $c): string => hash('sha256', $c), $files),
        'requires' => [],
        'irreversible' => false,
        'custom_code' => $customCode,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => $t->keyId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $t->sigJson = (new PackageSigner())->envelope($manifest, $t->signKey);
}

/**
 * Fake ArtisanSubprocess: records argv, runs nothing; commands in $failing exit 1.
 *
 * @param list<string> $failing
 */
function miaArtisan(array $failing = []): ArtisanSubprocess
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
function miaStatuses(): ModuleStatusService
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

function miaInstaller(object $t, ModuleStatusService $statuses, ArtisanSubprocess $artisan): ModuleInstaller
{
    return new ModuleInstaller(
        $t->gate,
        new BackgroundTaskRecorder(),
        new PackageVerifier($t->trusted, []),
        null,
        null,
        new DatabaseActivator(app()),
        null,
        null,
        $t->releaseDir,
        $statuses,
        $artisan,
    );
}

function miaInstall(object $t): ModuleInstall
{
    $task = (new BackgroundTaskRecorder())->start(ModuleInstall::TASK_TYPE, 'Core', (int) $t->user->id);

    return ModuleInstall::query()->create([
        'name' => MIA_NAME,
        'version' => MIA_VERSION,
        'source' => ModuleInstall::SOURCE_HUB,
        'status' => ModuleInstall::STATUS_QUEUED,
        'background_task_uuid' => $task->uuid,
        'requested_by' => (int) $t->user->id,
        'warnings' => [['code' => 'MODULE_WARN_RESOURCE_ONLY', 'message' => 'x', 'details' => []]],
    ]);
}

test('success: enabled through the service, commands run in order as subprocesses, audit row, task succeeded', function () {
    $statuses = miaStatuses();
    $artisan = miaArtisan();
    $install = miaInstall($this);

    miaInstaller($this, $statuses, $artisan)->run($install);

    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->error_code)->toBeNull()
        ->and($install->finished_at)->not->toBeNull()
        ->and($statuses->calls)->toBe([[MIA_NAME, true, $this->user->id, 'install']]);

    $commands = array_map(fn (array $args): string => implode(' ', $args), $artisan->calls);
    expect(array_slice($commands, 0, 4))->toBe([
        'module:migrate '.MIA_NAME.' --force',
        'cms:modules-sync '.MIA_NAME.' --skip-translations',
        'cms:translation-sync '.MIA_NAME,
        'cms:translation-generate --public-only',
    ])
        // Only the cache whose file exists is rebuilt, then workers restart.
        ->and($commands[4])->toBe('route:cache')
        ->and($commands[5])->toBe('queue:restart')
        ->and($commands)->not->toContain('config:cache')
        ->and($this->inProcess)->toBe([]);

    $row = DB::table('module_statuses')->where('name', MIA_NAME)->first();
    expect($row)->not->toBeNull()->and((bool) $row->enabled)->toBeTrue()->and($row->version)->toBe(MIA_VERSION);

    $audit = AuditLog::query()->where('action', 'action.module.installed')->sole();
    expect($audit->target_type)->toBe(ModuleInstall::class)
        ->and((int) $audit->target_id)->toBe((int) $install->id)
        ->and($audit->changed)->toMatchArray([
            'name' => MIA_NAME,
            'version' => MIA_VERSION,
            'zip_sha256' => hash('sha256', $this->zipBytes),
            'key_id' => $this->keyId,
            'backup_uuid' => $this->backupUuid,
            'source' => ModuleInstall::SOURCE_HUB,
        ]);

    expect($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($task->result)->toMatchArray(['enabled' => true, 'placed' => true, 'backup_uuid' => $this->backupUuid, 'warnings' => ['MODULE_WARN_RESOURCE_ONLY']])
        ->and(collect($install->warnings)->pluck('code')->all())->not->toContain(ModuleInstaller::WARN_ADMIN_BUILD_PENDING);
});

test('migration failure: module disabled again, files kept, row failed naming the backup, audit module.install_failed', function () {
    $statuses = miaStatuses();
    $artisan = miaArtisan(['module:migrate']);
    $install = miaInstall($this);

    miaInstaller($this, $statuses, $artisan)->run($install);

    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_MIGRATE)
        ->and($install->finished_at)->not->toBeNull()
        ->and((string) $install->step_message)->toContain($this->backupUuid)->toContain('restore it if data changed')
        ->and((string) $install->step_message)->not->toContain('boom')
        ->and($statuses->calls)->toBe([
            [MIA_NAME, true, $this->user->id, 'install'],
            [MIA_NAME, false, $this->user->id, 'install_failed'],
        ])
        ->and(array_map(fn (array $args): string => $args[0], $artisan->calls))->toBe(['module:migrate'])
        ->and($this->inProcess)->toBe([])
        // Files stay in place.
        ->and(is_link($this->runtimeDir.'/'.MIA_NAME))->toBeTrue()
        ->and(is_file($this->runtimeDir.'/'.MIA_NAME.'/module.json'))->toBeTrue();

    $row = DB::table('module_statuses')->where('name', MIA_NAME)->first();
    expect((bool) $row->enabled)->toBeFalse();

    expect($task->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and((string) $task->message)->toBe('text.background_tasks.step.module_install.failed_disabled')
        ->and($task->message_params['backup'])->toBe($this->backupUuid)
        ->and((string) $task->message)->not->toContain($this->secret)
        ->and(json_encode($task->message_params))->not->toContain($this->secret);

    $audit = AuditLog::query()->where('action', 'action.module.install_failed')->sole();
    expect($audit->changed)->toMatchArray([
        'name' => MIA_NAME,
        'error_code' => ModuleInstaller::ERROR_MIGRATE,
        'backup_uuid' => $this->backupUuid,
    ]);
    expect(AuditLog::query()->where('action', 'action.module.installed')->exists())->toBeFalse();
});

test('a failing translation sync fails the install with the activation code', function () {
    $statuses = miaStatuses();
    $artisan = miaArtisan(['cms:translation-sync']);
    $install = miaInstall($this);

    miaInstaller($this, $statuses, $artisan)->run($install);

    $install->refresh();
    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($install->error_code)->toBe(ModuleInstaller::ERROR_ACTIVATION)
        ->and(end($statuses->calls))->toBe([MIA_NAME, false, $this->user->id, 'install_failed'])
        ->and(array_map(fn (array $args): string => $args[0], $artisan->calls))->not->toContain('queue:restart');
});

test('a failing cache rebuild is not fatal', function () {
    $artisan = miaArtisan(['route:cache']);
    $install = miaInstall($this);

    miaInstaller($this, miaStatuses(), $artisan)->run($install);

    $commands = array_map(fn (array $args): string => $args[0], $artisan->calls);
    expect($install->refresh()->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($commands)->toContain('route:clear')
        ->and($commands)->toContain('queue:restart');
});

test('custom admin code runs the admin rebuild and warns when the checkout is not a deployed release', function () {
    miaPackage($this, true);
    $install = miaInstall($this);

    // A dev checkout with node and pnpm would build the admin for real (AdminRebuilder fallback); hide them here.
    $path = getenv('PATH');
    putenv('PATH='.sys_get_temp_dir().'/no-node-or-pnpm-here');
    try {
        miaInstaller($this, miaStatuses(), miaArtisan())->run($install);
    } finally {
        putenv('PATH='.$path);
    }

    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(collect($install->warnings)->pluck('code')->all())->toContain('MODULE_ADMIN_BUILD_NOT_DEPLOYED')
        ->and($task->result['warnings'])->toContain('MODULE_ADMIN_BUILD_NOT_DEPLOYED');
});

test('a skipped backup runs no dump, keeps backup_uuid null and audits who skipped it', function () {
    // Drop the beforeEach mock (it expects one ensure() call) and use one that refuses any.
    Mockery::resetContainer();
    $this->gate = Mockery::mock(BackupGate::class);
    $this->gate->shouldNotReceive('ensure');
    $statuses = miaStatuses();
    $artisan = miaArtisan();
    $install = miaInstall($this);
    $install->forceFill(['backup_skipped' => true])->save();

    miaInstaller($this, $statuses, $artisan)->run($install);

    $install->refresh();
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->backup_uuid)->toBeNull()
        ->and($install->backup_skipped)->toBeTrue()
        ->and($artisan->calls[0])->toBe(['module:migrate', MIA_NAME, '--force']);

    $skipped = AuditLog::query()->where('action', 'action.module.install_backup_skipped')->sole();
    expect((int) $skipped->user_id)->toBe((int) $this->user->id)
        ->and($skipped->changed)->toMatchArray(['name' => MIA_NAME, 'version' => MIA_VERSION, 'requested_by' => (int) $this->user->id]);
    expect(AuditLog::query()->where('action', 'action.module.installed')->sole()->changed)
        ->toMatchArray(['backup_uuid' => null, 'backup_skipped' => true]);
});

test('with the backup skipped a failed install points at the site\'s own backups, not a pre-install one', function () {
    // Drop the beforeEach mock (it expects one ensure() call) and use one that refuses any.
    Mockery::resetContainer();
    $this->gate = Mockery::mock(BackupGate::class);
    $this->gate->shouldNotReceive('ensure');
    $statuses = miaStatuses();
    $artisan = miaArtisan(['module:migrate']);
    $install = miaInstall($this);
    $install->forceFill(['backup_skipped' => true])->save();

    miaInstaller($this, $statuses, $artisan)->run($install);

    $install->refresh();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();
    expect($install->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and((string) $install->step_message)->toContain('No backup was taken')->toContain('scheduled backups')
        ->and((string) $install->step_message)->not->toContain('backed up before')
        ->and(json_encode($task->toArray()))->toContain('failed_disabled_no_backup');
});
