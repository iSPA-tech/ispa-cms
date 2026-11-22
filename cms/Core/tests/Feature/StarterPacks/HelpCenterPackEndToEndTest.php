<?php

declare(strict_types=1);

use App\Support\Modules\ArtisanSubprocess;
use Cms\Core\Contracts\StarterContentImporter;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
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
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * End-to-end apply of the shipped help-center pack: the real loader, planner, applier and role applier run
 * on the real pack files; only the pieces that need a full site are replaced (module enabling in a fresh process,
 * the safety backup, the configuration package engine and the Helpdesk importer, which needs the Helpdesk schema).
 * The scratch-site script `storage/sandbox/starter-pack-scratch.sh` runs the same pack with every real service.
 */
class E2eState
{
    /** @var array<string, bool> Module name => enabled. */
    public static array $enabled = [];

    public static bool $contentThrows = false;

    /** @var list<string> Article keys the fake importer created. */
    public static array $articles = [];

    public static int $backups = 0;

    public static function reset(): void
    {
        self::$enabled = ['Core' => true, 'Content' => false, 'Account' => false, 'Helpdesk' => false];
        self::$contentThrows = false;
        self::$articles = [];
        self::$backups = 0;
    }
}

/** Planner whose module states follow E2eState (the real release scan is not what this test is about). */
class E2ePlanner extends StarterPackPlanner
{
    public function plan(StarterPackManifest $pack, ?int $actingUserId = null): StarterPackPlan
    {
        $plan = parent::plan($pack, $actingUserId);
        $modules = [];
        foreach (E2eState::$enabled as $name => $enabled) {
            $modules[] = ['name' => $name, 'constraint' => '*', 'version' => '1.0.0', 'state' => $enabled ? StarterPackPlan::STATE_ENABLED : StarterPackPlan::STATE_TO_ENABLE, 'irreversible' => []];
        }

        // The modules above count as present, so the real catalog's "not installed / not published" blockers do not
        // apply; an install without the Account or Helpdesk folders would otherwise stop at preflight.
        $blockers = array_values(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => preg_match('/^(Module \S+ is not installed|No published version of module )/', $blocker) !== 1,
        ));

        return new StarterPackPlan($plan->packKey, $plan->packVersion, $modules, $plan->config, $plan->roles, $plan->workflows, $plan->content, $blockers, $plan->warnings);
    }
}

/** Counts backups instead of writing one. */
class E2eBackupGate extends BackupGate
{
    public function __construct()
    {
    }

    public function ensure(string $reason, ?int $userId, ?BackgroundTask $task = null): Backup
    {
        ++E2eState::$backups;

        return (new Backup())->forceFill(['uuid' => '00000000-0000-4000-8000-000000000002']);
    }
}

/** `cms:module-status set X on` enables module X; every other command succeeds. */
class E2eArtisan extends ArtisanSubprocess
{
    public function run(array $args, int $timeout): ProcessResult
    {
        if (($args[0] ?? '') === 'cms:module-status' && ($args[3] ?? '') === 'on') {
            E2eState::$enabled[$args[2]] = true;
        }

        return Process::result('', '', 0);
    }
}

/** Configuration engine stand-in: the pack's settings are already on the site. */
class E2eDiffer extends ConfigPackageDiffer
{
    public function __construct()
    {
    }

    public function dryRun(array $package, int $userId): ConfigPackageRun
    {
        return ConfigPackageRun::query()->create([
            'kind' => ConfigPackageRun::KIND_DRY_RUN,
            'status' => ConfigPackageRun::STATUS_SUCCEEDED,
            'sections' => ['settings'],
            'package_checksum' => 'sha256:test',
            'summary' => ['sections' => [], 'warnings' => 0, 'choices' => 0],
            'plan' => ['sections' => ['settings' => ['status' => 'no_changes', 'counts' => ['create' => 0, 'update' => 0, 'unchanged' => 46, 'blocked' => 0, 'skipped' => 0], 'total' => 46, 'items' => [], 'more' => 0]], 'choices' => []],
            'created_by' => $userId,
        ]);
    }
}

/** Configuration applier that succeeds without writing. */
class E2eConfigApplier extends ConfigPackageApplier
{
    public function __construct()
    {
    }

    public function execute(ConfigPackageRun $run, int $userId, ?callable $onSection = null, ?BackgroundTask $task = null): ConfigPackageRun
    {
        $run->forceFill(['status' => ConfigPackageRun::STATUS_SUCCEEDED, 'summary' => ['error' => null, 'sections' => ['settings' => ['updated' => 0, 'unchanged' => 46, 'skipped' => 0, 'blocked' => 0]]]])->save();

        return $run;
    }
}

/** Helpdesk knowledge-base importer stand-in: creates each article once, can be told to throw. */
class E2eKbImporter implements StarterContentImporter
{
    public function export(array $options): array
    {
        return [];
    }

    public function id(): string
    {
        return 'helpdesk.kb';
    }

    public function module(): string
    {
        return 'Helpdesk';
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function plan(array $data): array
    {
        $keys = array_column($data['articles'] ?? [], 'translation_key');

        return ['products' => 1, 'categories' => count($data['categories'] ?? []), 'articles' => count(array_diff($keys, E2eState::$articles)) * 2];
    }

    public function import(array $data, array $options = []): array
    {
        if (E2eState::$contentThrows) {
            throw new RuntimeException('Content import broken on purpose.');
        }
        $new = array_values(array_diff(array_column($data['articles'], 'translation_key'), E2eState::$articles));
        E2eState::$articles = array_merge(E2eState::$articles, $new);

        return ['created' => ['articles' => count($new) * 2], 'skipped' => []];
    }
}

/** Absolute path of the shipped help-center pack. */
function e2ePackDir(): string
{
    return base_path('cms/Core/resources/starter-packs/help-center');
}

/**
 * Row counts and newest updated_at of the tables the roles step writes.
 *
 * @return array<string, array{int, string|null}>
 */
function e2eFingerprint(): array
{
    $out = [];
    foreach (['roles', 'permissions', 'permission_role'] as $table) {
        $out[$table] = [DB::table($table)->count(), Schema::hasColumn($table, 'updated_at') ? DB::table($table)->max('updated_at') : null];
    }

    return $out;
}

beforeEach(function (): void {
    Cache::flush();
    E2eState::reset();
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

    foreach (['kb_editor', 'agent', 'admin'] as $name) {
        Permission::create(['group' => 'helpdesk', 'name' => $name, 'title' => $name]);
    }
    DB::table('users')->insert(['id' => 1, 'username' => 'root']);

    $registry = new StarterContentRegistry();
    $registry->register(new E2eKbImporter());
    app()->instance(StarterContentRegistry::class, $registry);
    app()->instance(BackupGate::class, new E2eBackupGate());
    app()->instance(ArtisanSubprocess::class, new E2eArtisan());
    app()->instance(ConfigPackageDiffer::class, new E2eDiffer());
    app()->instance(ConfigPackageApplier::class, new E2eConfigApplier());
    app()->instance(StarterPackPlanner::class, new E2ePlanner(
        new ModuleCatalog(fn () => new class {
            public function get(string $path): array
            {
                return ['modules' => []];
            }
        }),
        $registry, app(StarterRoleApplier::class), app(StarterWorkflowApplier::class), app(ConfigPackageDiffer::class), fn (): bool => false,
    ));
});

/** Applier built from the container fakes; the install preflight always passes (every module is present). */
function e2eApplier(): StarterPackApplier
{
    return app()->makeWith(StarterPackApplier::class, [
        'preflight' => fn (string $name, string $version): PreflightResult => new PreflightResult($name, $version, [], [], []),
    ]);
}

/**
 * Step statuses of an application, keyed by step name.
 *
 * @return array<string, string>
 */
function e2eStatuses(StarterPackApplication $application): array
{
    return array_column((array) $application->steps, 'status', 'name');
}

it('applies the help-center pack, survives a broken content step, and a repeat changes no row', function (): void {
    $pack = (new StarterPackLoader(dirname(e2ePackDir())))->load(e2ePackDir());

    // Break the run on purpose: the content step throws.
    E2eState::$contentThrows = true;
    $failed = e2eApplier()->apply($pack, 1);

    expect($failed->status)->toBe(StarterPackApplication::STATUS_FAILED)
        ->and(e2eStatuses($failed)['content'])->toBe('failed')
        ->and(e2eStatuses($failed)['post_apply'])->toBe('not_run')
        ->and(e2eStatuses($failed)['modules'])->toBe('succeeded')
        ->and(E2eState::$enabled)->toBe(['Core' => true, 'Content' => true, 'Account' => true, 'Helpdesk' => true])
        ->and(Role::query()->count())->toBe(3)
        ->and(E2eState::$articles)->toBe([]);

    // The re-run completes it and skips what the first run finished.
    E2eState::$contentThrows = false;
    $done = e2eApplier()->apply($pack, 1);

    expect($done->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(e2eStatuses($done)['modules'])->toBe('skipped')
        ->and(e2eStatuses($done)['content'])->toBe('succeeded')
        ->and(E2eState::$articles)->toHaveCount(7)
        ->and(StarterPackApplication::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'succeeded']);

    // Three roles with the permissions the pack names.
    $permissions = Role::query()->with('permissions')->get()->mapWithKeys(
        fn (Role $role): array => [$role->name => $role->permissions->map(fn ($p): string => $p->group . '|' . $p->name)->sort()->values()->all()],
    )->all();
    expect($permissions)->toBe([
        'Help center editor' => ['helpdesk|kb_editor'],
        'Help center agent' => ['helpdesk|agent', 'helpdesk|kb_editor'],
        'Help center admin' => ['helpdesk|admin', 'helpdesk|agent', 'helpdesk|kb_editor'],
    ]);

    // The plan after the apply has nothing left to create.
    $plan = app(StarterPackPlanner::class)->plan($pack, 1);
    expect($plan->roles['created'])->toBe([])
        ->and(array_column($plan->modules, 'state'))->toBe(array_fill(0, 4, StarterPackPlan::STATE_ENABLED))
        ->and(array_column(array_column($plan->content, 'counts'), 'articles'))->toBe([0]);

    // A second apply changes no row (the ledger gains its own entry and the safety backup is taken again).
    $before = e2eFingerprint();
    $backups = E2eState::$backups;
    sleep(1);
    $again = e2eApplier()->apply($pack, 1);

    expect($again->status)->toBe(StarterPackApplication::STATUS_SUCCEEDED)
        ->and(e2eFingerprint())->toBe($before)
        ->and(E2eState::$articles)->toHaveCount(7)
        ->and(E2eState::$backups)->toBe($backups + 1);
});
