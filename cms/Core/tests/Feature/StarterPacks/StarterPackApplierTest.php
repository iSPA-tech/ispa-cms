<?php

declare(strict_types=1);

use App\Support\Modules\ArtisanSubprocess;
use Cms\Core\Contracts\StarterContentImporter;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\ApplyStarterPackJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterPackLoader;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Cms\Core\Services\StarterPacks\StarterRoleApplier;
use Cms\Core\Services\StarterPacks\StarterWorkflowApplier;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

/** Shared state of the module installer and configuration package fakes. */
class SpaState
{
    /** @var list<string> Calls in order. */
    public static array $log = [];

    /** @var array<string, string> Fake module name => plan state. */
    public static array $modules = [];

    /** @var list<int> Progress percentages recorded on the task. */
    public static array $progress = [];

    public static bool $backupFails = false;

    public static bool $configFails = false;

    public static bool $configApplied = false;

    /** @var array<string, mixed>|null Choices the configuration apply run carried. */
    public static ?array $configChoices = null;

    /** Reset every field. */
    public static function reset(): void
    {
        self::$log = [];
        self::$modules = [];
        self::$progress = [];
        self::$backupFails = false;
        self::$configFails = false;
        self::$configApplied = false;
        self::$configChoices = null;
    }
}

/** Planner whose module list adds the fake modules of SpaState (the release module scan is not faked). */
class SpaPlanner extends StarterPackPlanner
{
    public function plan(StarterPackManifest $pack, ?int $actingUserId = null): StarterPackPlan
    {
        $plan = parent::plan($pack, $actingUserId);
        $modules = $plan->modules;
        foreach (SpaState::$modules as $name => $state) {
            $modules[] = ['name' => $name, 'constraint' => '*', 'version' => '1.0.0', 'state' => $state, 'irreversible' => []];
        }

        return new StarterPackPlan($plan->packKey, $plan->packVersion, $modules, $plan->config, $plan->roles, $plan->workflows, $plan->content, $plan->blockers, $plan->warnings);
    }
}

/** Backup gate that succeeds or fails on demand. */
class SpaBackupGate extends BackupGate
{
    public function __construct()
    {
    }

    public function ensure(string $reason, ?int $userId, ?BackgroundTask $task = null): Backup
    {
        SpaState::$log[] = 'backup:' . $reason;
        if (SpaState::$backupFails) {
            throw new BackupRequiredException('BACKUP_ALL_DESTINATIONS_FAILED');
        }

        return (new Backup())->forceFill(['uuid' => '00000000-0000-4000-8000-000000000001']);
    }
}

/** Installer that marks the install row succeeded and the module enabled. */
class SpaInstaller extends ModuleInstaller
{
    public function __construct()
    {
    }

    public function run(ModuleInstall $install): void
    {
        SpaState::$log[] = 'install:' . $install->name;
        $install->forceFill(['status' => ModuleInstall::STATUS_SUCCEEDED])->save();
        SpaState::$modules[$install->name] = StarterPackPlan::STATE_ENABLED;
    }
}

/** Artisan runner that records commands; `cms:module-status set X on` enables fake module X. */
class SpaArtisan extends ArtisanSubprocess
{
    public function run(array $args, int $timeout): ProcessResult
    {
        SpaState::$log[] = 'artisan:' . implode(' ', $args);
        if (($args[0] ?? '') === 'cms:module-status' && ($args[3] ?? '') === 'on') {
            SpaState::$modules[$args[2]] = StarterPackPlan::STATE_ENABLED;
        }

        return Process::result('', '', 0);
    }
}

/** Differ whose dry run offers one new setting and one changed setting until the configuration was applied. */
class SpaDiffer extends ConfigPackageDiffer
{
    public function __construct()
    {
    }

    public function dryRun(array $package, int $userId): ConfigPackageRun
    {
        SpaState::$log[] = 'config:dry_run';
        $items = SpaState::$configApplied
            ? [['key' => 'site.new', 'action' => 'unchanged'], ['key' => 'site.name', 'action' => 'update']]
            : [['key' => 'site.new', 'action' => 'create'], ['key' => 'site.name', 'action' => 'update']];
        $counts = ['create' => SpaState::$configApplied ? 0 : 1, 'update' => 1, 'unchanged' => SpaState::$configApplied ? 1 : 0, 'blocked' => 0, 'skipped' => 0];
        $section = ['status' => 'changes', 'counts' => $counts, 'total' => 2, 'items' => $items, 'more' => 0];

        return ConfigPackageRun::query()->create([
            'kind' => ConfigPackageRun::KIND_DRY_RUN,
            'status' => ConfigPackageRun::STATUS_SUCCEEDED,
            'sections' => ['settings'],
            'package_checksum' => 'sha256:test',
            'summary' => ['sections' => ['settings' => ['status' => 'changes', 'counts' => $counts, 'total' => 2]], 'warnings' => 0, 'choices' => 0],
            'plan' => ['sections' => ['settings' => $section], 'choices' => []],
            'created_by' => $userId,
        ]);
    }
}

/** Configuration applier that records the choices it was given and succeeds or fails on demand. */
class SpaConfigApplier extends ConfigPackageApplier
{
    public function __construct()
    {
    }

    public function execute(ConfigPackageRun $run, int $userId, ?callable $onSection = null, ?BackgroundTask $task = null): ConfigPackageRun
    {
        SpaState::$log[] = 'config:apply';
        SpaState::$configChoices = (array) (((array) $run->plan)['choices'] ?? []);
        if (SpaState::$configFails) {
            $run->forceFill(['status' => ConfigPackageRun::STATUS_FAILED, 'summary' => ['error' => 'Some sections failed: settings.', 'sections' => []]])->save();

            return $run;
        }

        SpaState::$configApplied = true;
        $run->forceFill(['status' => ConfigPackageRun::STATUS_SUCCEEDED, 'summary' => [
            'error' => null,
            'sections' => ['settings' => ['status' => 'succeeded', 'updated' => 1, 'unchanged' => 0, 'skipped' => 1, 'blocked' => 0]],
        ]])->save();

        return $run;
    }
}

/** Recorder that also keeps every progress percentage. */
class SpaRecorder extends BackgroundTaskRecorder
{
    public function progress(BackgroundTask $task, int $percent, ?string $message = null, array $params = []): BackgroundTask
    {
        SpaState::$progress[] = $percent;

        return parent::progress($task, $percent, $message, $params);
    }
}

/** In-memory content importer. */
class SpaImporter implements StarterContentImporter
{
    /** @var list<string> */
    public static array $imported = [];

    /** @var array<string, mixed> Options of the last import() call. */
    public static array $options = [];

    public function export(array $options): array
    {
        return [];
    }

    public function id(): string
    {
        return 'fake-kb';
    }

    public function module(): string
    {
        return 'Core';
    }

    public function validate(array $data): array
    {
        return isset($data['items']) && is_array($data['items']) ? [] : ['items is required'];
    }

    public function plan(array $data): array
    {
        return ['items' => count(array_diff($data['items'] ?? [], self::$imported))];
    }

    public function import(array $data, array $options = []): array
    {
        SpaState::$log[] = 'import:' . $this->id();
        self::$options = $options;
        $new = array_values(array_diff($data['items'], self::$imported));
        self::$imported = array_merge(self::$imported, $new);

        return ['created' => ['items' => count($new)], 'skipped' => ['items' => count($data['items']) - count($new)]];
    }
}

beforeEach(function (): void {
    Cache::flush();
    SpaState::reset();
    SpaImporter::$imported = [];
    Event::fake([AuditLogEvent::class]);

    foreach (['role_user', 'permission_role', 'permissions', 'roles', 'users', 'core_config_package_runs', 'core_starter_pack_applications', 'core_background_tasks', 'core_module_installs'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100)->nullable();
        $table->text('custom')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->char('group', 50)->nullable();
        $table->char('name', 50)->nullable();
        $table->string('title', 255)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('permission_role', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('permission_id');
    });
    Schema::create('role_user', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('user_id');
    });
    Schema::create('core_config_package_runs', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->string('kind');
        $table->string('status')->default('queued');
        $table->json('sections');
        $table->string('package_checksum', 80)->nullable();
        $table->longText('payload')->nullable();
        $table->json('summary')->nullable();
        $table->json('plan')->nullable();
        $table->unsignedBigInteger('parent_run_id')->nullable();
        $table->uuid('background_task_uuid')->nullable();
        $table->string('backup_ref')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    foreach ([
        '2026_11_05_090000_create_core_background_tasks_table.php',
        '2026_11_05_150000_add_message_params_to_core_background_tasks_table.php',
        '2026_11_06_090000_create_starter_pack_applications_table.php',
        '2026_11_07_090000_create_core_module_installs_table.php',
        '2026_11_10_120000_add_upgrade_columns_to_core_module_installs_table.php',
    ] as $migration) {
        (require base_path('cms/Core/database/migrations/' . $migration))->up();
    }

    Permission::create(['group' => 'helpdesk', 'name' => 'agent', 'title' => 'agent']);
    DB::table('users')->insert(['id' => 1, 'username' => 'root']);

    $registry = new StarterContentRegistry();
    $registry->register(new SpaImporter());
    app()->instance(StarterContentRegistry::class, $registry);
    app()->instance(BackupGate::class, new SpaBackupGate());
    app()->instance(ModuleInstaller::class, new SpaInstaller());
    app()->instance(ArtisanSubprocess::class, new SpaArtisan());
    app()->instance(ConfigPackageDiffer::class, new SpaDiffer());
    app()->instance(ConfigPackageApplier::class, new SpaConfigApplier());
    app()->instance(BackgroundTaskRecorder::class, new SpaRecorder());
    app()->instance(StarterPackPlanner::class, new SpaPlanner(
        new ModuleCatalog(fn () => new class {
            public function get(string $path): array
            {
                return ['modules' => []];
            }
        }),
        $registry, app(StarterRoleApplier::class), app(StarterWorkflowApplier::class), app(ConfigPackageDiffer::class), fn (): bool => false,
    ));
    app()->instance(StarterPackApplier::class, spaApplier());
});

/** Applier built from the container fakes; the install preflight always passes. */
function spaApplier(): StarterPackApplier
{
    return app()->makeWith(StarterPackApplier::class, [
        'preflight' => fn (string $name, string $version): PreflightResult => new PreflightResult($name, $version, [], [], []),
    ]);
}

/**
 * Write a pack folder `<tmp>/demo` with pack.json, a configuration file, roles and content, and load it.
 *
 * @param list<string> $postApply
 */
function spaPack(array $postApply = ['cache-clear']): StarterPackManifest
{
    $dir = sys_get_temp_dir() . '/spa-' . bin2hex(random_bytes(4)) . '/demo';
    mkdir($dir . '/content', 0777, true);
    file_put_contents($dir . '/config.package.json', '{}');
    file_put_contents($dir . '/roles.json', json_encode([['name' => 'Help agent', 'permissions' => ['helpdesk|agent']]]));
    file_put_contents($dir . '/content/kb.json', json_encode(['items' => ['a', 'b']]));
    file_put_contents($dir . '/pack.json', json_encode([
        'pack_format' => 1,
        'key' => 'demo',
        'version' => '1.0.0',
        'name' => ['vi' => 'Gói thử', 'en' => 'Demo pack'],
        'description' => ['vi' => 'Gói thử', 'en' => 'Demo pack'],
        'modules' => ['Core' => '*'],
        'config' => 'config.package.json',
        'roles' => 'roles.json',
        'workflows' => null,
        'content' => [['module' => 'Core', 'importer' => 'fake-kb', 'file' => 'content/kb.json']],
        'post_apply' => $postApply,
    ]));

    return (new StarterPackLoader())->load($dir);
}

/**
 * Step statuses of an application, keyed by step name.
 *
 * @return array<string, string>
 */
function spaStatuses(StarterPackApplication $application): array
{
    return array_column((array) $application->steps, 'status', 'name');
}

it('runs every step in order after the backup and records the ledger, the task and the audit entry', function (): void {
    SpaState::$modules = ['Zzinstall' => StarterPackPlan::STATE_TO_INSTALL, 'Zzenable' => StarterPackPlan::STATE_TO_ENABLE];

    $application = spaApplier()->apply(spaPack(), 1);

    expect($application->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(array_keys(spaStatuses($application)))->toBe(StarterPackApplier::STEPS)
        ->and(array_values(array_unique(spaStatuses($application))))->toBe(['succeeded'])
        ->and($application->finished_at)->not->toBeNull()
        ->and($application->applied_by)->toBe(1);

    // The planner's dry run comes first (rolled back); every write follows the backup.
    expect(SpaState::$log)->toBe([
        'config:dry_run',
        'backup:pre_install',
        'install:Zzinstall',
        'artisan:cms:module-status set Zzenable on --reason=starter_pack',
        'config:dry_run',
        'config:apply',
        'import:fake-kb',
        'artisan:cache:clear',
    ]);

    // Add-only: the changed setting is never overwritten.
    expect(SpaState::$configChoices)->toBe(['settings' => ['site.name' => false]]);

    $steps = collect($application->steps)->keyBy('name');
    expect($steps['modules']['counts'])->toBe(['installed' => 1, 'enabled' => 1, 'unchanged' => 1])
        ->and($steps['roles']['counts']['created'])->toBe(1)
        ->and($steps['content']['counts'])->toBe(['created' => 2, 'skipped' => 0])
        ->and(array_keys($steps['backup']))->toBe(['name', 'status', 'counts', 'message']);

    $task = BackgroundTask::query()->where('uuid', $application->background_task_uuid)->firstOrFail();
    expect($task->type)->toBe(ApplyStarterPackJob::TASK_TYPE)
        ->and($task->module)->toBe('Core')
        ->and($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($task->progress)->toBe(100)
        ->and($task->result['backup_uuid'])->toBe('00000000-0000-4000-8000-000000000001');

    expect(Role::query()->where('name', 'Help agent')->count())->toBe(1)
        ->and(ModuleInstall::query()->where('name', 'Zzinstall')->value('status'))->toBe('succeeded');

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'starter_pack.applied'
        && $e->changed['pack_key'] === 'demo' && $e->changed['pack_version'] === '1.0.0' && $e->user_id === 1);
});

it('regenerates only the public language packs in the translation-sync final step', function (): void {
    $application = spaApplier()->apply(spaPack(['translation-sync', 'cache-clear']), 1);

    // A release install keeps the code tree read-only for the web user: a full generate would fail on lang/ files.
    expect($application->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(array_values(array_filter(SpaState::$log, fn (string $entry): bool => str_starts_with($entry, 'artisan:'))))->toBe([
            'artisan:cms:translation-sync',
            'artisan:cms:translation-generate --public-only',
            'artisan:cache:clear',
        ]);
});

it('hands the content status option to the importers as status', function (): void {
    SpaImporter::$options = ['untouched'];

    spaApplier()->apply(spaPack(), 1, [StarterPackApplier::OPTION_CONTENT_STATUS => 'published']);
    expect(SpaImporter::$options)->toBe(['status' => 'published']);

    SpaImporter::$imported = [];
    spaApplier()->apply(spaPack(), 1);
    expect(SpaImporter::$options)->toBe([]);
});

it('stops everything when the backup fails and writes nothing', function (): void {
    SpaState::$modules = ['Zzinstall' => StarterPackPlan::STATE_TO_INSTALL];
    SpaState::$backupFails = true;

    $application = spaApplier()->apply(spaPack(), 1);

    expect($application->status)->toBe(StarterPackApplication::STATUS_FAILED)
        ->and(spaStatuses($application))->toBe([
            'preflight' => 'succeeded', 'backup' => 'failed', 'modules' => 'not_run', 'config' => 'not_run',
            'roles' => 'not_run', 'workflows' => 'not_run', 'content' => 'not_run', 'post_apply' => 'not_run',
        ])
        ->and(collect($application->steps)->firstWhere('name', 'backup')['message'])->toBe('backup failed, nothing changed')
        ->and(SpaState::$log)->toBe(['config:dry_run', 'backup:pre_install'])
        ->and(Role::query()->where('name', 'Help agent')->exists())->toBeFalse()
        ->and(ModuleInstall::query()->count())->toBe(0)
        ->and(ConfigPackageRun::query()->count())->toBe(0)
        ->and(SpaImporter::$imported)->toBe([]);

    $task = BackgroundTask::query()->where('uuid', $application->background_task_uuid)->firstOrFail();
    expect($task->status)->toBe(BackgroundTask::STATUS_FAILED)->and($task->message)->toBe('backup failed, nothing changed');

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'starter_pack.apply_failed' && $e->changed['step'] === 'backup');
});

it('keeps the installed modules and records the rest as not run when the configuration fails', function (): void {
    SpaState::$modules = ['Zzinstall' => StarterPackPlan::STATE_TO_INSTALL];
    SpaState::$configFails = true;

    $application = spaApplier()->apply(spaPack(), 1);

    expect($application->status)->toBe(StarterPackApplication::STATUS_FAILED)
        ->and(spaStatuses($application))->toBe([
            'preflight' => 'succeeded', 'backup' => 'succeeded', 'modules' => 'succeeded', 'config' => 'failed',
            'roles' => 'not_run', 'workflows' => 'not_run', 'content' => 'not_run', 'post_apply' => 'not_run',
        ])
        ->and(SpaState::$modules['Zzinstall'])->toBe(StarterPackPlan::STATE_ENABLED)
        ->and(ModuleInstall::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(Role::query()->where('name', 'Help agent')->exists())->toBeFalse()
        ->and(BackgroundTask::query()->where('uuid', $application->background_task_uuid)->value('status'))->toBe('failed');
});

it('completes the remaining steps on a second run without duplicates', function (): void {
    SpaState::$modules = ['Zzinstall' => StarterPackPlan::STATE_TO_INSTALL];
    SpaState::$configFails = true;
    $pack = spaPack();
    $failed = spaApplier()->apply($pack, 1);

    SpaState::$configFails = false;
    SpaState::$log = [];
    $second = spaApplier()->apply($pack, 1);

    expect($second->id)->not->toBe($failed->id)
        ->and($second->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(spaStatuses($second)['modules'])->toBe('skipped')
        ->and(spaStatuses($second)['config'])->toBe('succeeded')
        ->and(SpaState::$log)->not->toContain('install:Zzinstall')
        ->and(SpaState::$log)->toContain('backup:pre_install');

    $third = spaApplier()->apply($pack, 1);

    expect($third->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(ModuleInstall::query()->count())->toBe(1)
        ->and(Role::query()->where('name', 'Help agent')->count())->toBe(1)
        ->and(SpaImporter::$imported)->toBe(['a', 'b'])
        ->and(collect($third->steps)->firstWhere('name', 'roles')['counts']['created'])->toBe(0)
        ->and(StarterPackApplication::query()->where('status', 'failed')->pluck('id')->all())->toBe([$failed->id]);
});

it('reports progress as the share of finished steps', function (): void {
    spaApplier()->apply(spaPack(), 1);

    expect(SpaState::$progress)->toBe([0, 12, 25, 37, 50, 62, 75, 87]);
});

it('queues the job once with tries 1 and refuses a second apply while one runs', function (): void {
    Queue::fake();
    $pack = spaPack();

    $application = spaApplier()->queue($pack, 1);

    Queue::assertPushed(ApplyStarterPackJob::class, 1);
    Queue::assertPushed(ApplyStarterPackJob::class, fn (ApplyStarterPackJob $job): bool => $job->tries === 1
        && $job->applicationUuid === $application->uuid && $job->hop === 0);

    $task = BackgroundTask::query()->where('uuid', $application->background_task_uuid)->firstOrFail();
    expect($application->status)->toBe(StarterPackApplication::STATUS_STARTED)
        ->and(array_values(array_unique(spaStatuses($application))))->toBe(['pending'])
        ->and($task->type)->toBe('core.starter_pack.apply')
        ->and($task->module)->toBe('Core')
        ->and($task->status)->toBe(BackgroundTask::STATUS_QUEUED);

    expect(fn () => spaApplier()->queue($pack, 1))->toThrow(StarterPackException::class, 'Another starter pack is being applied');
    Queue::assertPushed(ApplyStarterPackJob::class, 1);
});

it('hands over to a fresh job after a module change and finishes there', function (): void {
    Queue::fake();
    SpaState::$modules = ['Zzenable' => StarterPackPlan::STATE_TO_ENABLE];
    $application = spaApplier()->queue(spaPack(), 1);
    $job = new ApplyStarterPackJob($application->uuid, spaPackDir($application));

    $job->handle(app(StarterPackApplier::class), new StarterPackLoader());

    $application->refresh();
    expect($application->status)->toBe(StarterPackApplication::STATUS_STARTED)
        ->and(spaStatuses($application))->toMatchArray(['backup' => 'succeeded', 'modules' => 'succeeded', 'config' => 'pending']);
    $next = null;
    Queue::assertPushed(ApplyStarterPackJob::class, function (ApplyStarterPackJob $pushed) use (&$next): bool {
        if ($pushed->hop === 1) {
            $next = $pushed;
        }

        return $pushed->hop === 1;
    });

    SpaState::$log = [];
    $next->handle(app(StarterPackApplier::class), new StarterPackLoader());

    $application->refresh();
    expect($application->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(SpaState::$log)->not->toContain('backup:pre_install')
        ->and(SpaState::$log)->not->toContain('artisan:cms:module-status set Zzenable on --reason=starter_pack')
        ->and(Role::query()->where('name', 'Help agent')->count())->toBe(1);
});

it('waits for a fresh worker when a content importer is still missing after the hand-over', function (): void {
    Queue::fake();
    $application = spaApplier()->queue(spaPack(), 1);
    app()->instance(StarterContentRegistry::class, new StarterContentRegistry());

    (new ApplyStarterPackJob($application->uuid, spaPackDir($application), [], 1))->handle(spaApplier(), new StarterPackLoader());

    Queue::assertPushed(ApplyStarterPackJob::class, fn (ApplyStarterPackJob $job): bool => $job->hop === 2);
    expect($application->refresh()->status)->toBe(StarterPackApplication::STATUS_STARTED)
        ->and(SpaState::$log)->toBe([]);
});

it('waits while a worker still sees the old module set and fails on the last attempt', function (): void {
    Queue::fake();
    SpaState::$modules = ['Zzenable' => StarterPackPlan::STATE_TO_ENABLE];
    $application = spaApplier()->queue(spaPack(), 1);
    $dir = spaPackDir($application);
    (new ApplyStarterPackJob($application->uuid, $dir))->handle(spaApplier(), new StarterPackLoader());

    // An old worker: the module the run enabled still reads as disabled.
    SpaState::$modules['Zzenable'] = StarterPackPlan::STATE_TO_ENABLE;
    (new ApplyStarterPackJob($application->uuid, $dir, [], 1))->handle(spaApplier(), new StarterPackLoader());

    Queue::assertPushed(ApplyStarterPackJob::class, fn (ApplyStarterPackJob $job): bool => $job->hop === 2);
    expect($application->refresh()->status)->toBe(StarterPackApplication::STATUS_STARTED);

    (new ApplyStarterPackJob($application->uuid, $dir, [], ApplyStarterPackJob::MAX_HOPS))->handle(spaApplier(), new StarterPackLoader());

    $application->refresh();
    expect($application->status)->toBe(StarterPackApplication::STATUS_FAILED)
        ->and(collect($application->steps)->firstWhere('name', 'modules')['message'])->toContain('apply the pack again')
        ->and(spaStatuses($application)['config'])->toBe('not_run')
        ->and(Role::query()->where('name', 'Help agent')->exists())->toBeFalse();
});

it('closes the ledger and the task when the job dies', function (): void {
    Queue::fake();
    $application = spaApplier()->queue(spaPack(), 1);

    (new ApplyStarterPackJob($application->uuid, spaPackDir($application)))->failed(new RuntimeException('worker killed'));

    $application->refresh();
    expect($application->status)->toBe(StarterPackApplication::STATUS_FAILED)
        ->and(array_values(array_unique(spaStatuses($application))))->toBe(['not_run'])
        ->and(BackgroundTask::query()->where('uuid', $application->background_task_uuid)->value('status'))->toBe('failed');
});

/** Pack folder of the queued job of an application. */
function spaPackDir(StarterPackApplication $application): string
{
    $dir = null;
    Queue::assertPushed(ApplyStarterPackJob::class, function (ApplyStarterPackJob $job) use ($application, &$dir): bool {
        if ($job->applicationUuid === $application->uuid && $job->hop === 0) {
            $dir = $job->packDir;
        }

        return true;
    });

    return (string) $dir;
}

it('takes no backup when the plan has no write step', function (): void {
    $pack = spaPack();
    $first = spaApplier()->apply($pack, 1);
    expect($first->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(SpaState::$log)->toContain('backup:pre_install');

    SpaState::$log = [];
    $again = spaApplier()->apply($pack, 1);

    expect($again->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(SpaState::$log)->not->toContain('backup:pre_install')
        ->and(spaStatuses($again)['backup'])->toBe('skipped')
        ->and(collect($again->steps)->firstWhere('name', 'backup')['message'])->toContain('Nothing to change');
});

it('still takes the backup when the plan has a write step', function (): void {
    SpaState::$modules = ['Zzenable' => StarterPackPlan::STATE_TO_ENABLE];

    $application = spaApplier()->apply(spaPack(), 1);

    expect($application->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(SpaState::$log)->toContain('backup:pre_install')
        ->and(spaStatuses($application)['backup'])->toBe('succeeded');
});
