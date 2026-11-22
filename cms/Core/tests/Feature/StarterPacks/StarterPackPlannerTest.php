<?php

declare(strict_types=1);

use Cms\Core\Contracts\StarterContentImporter;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\StarterPacks\StarterContentRegistry;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Cms\Core\Services\StarterPacks\StarterRoleApplier;
use Cms\Core\Services\StarterPacks\StarterWorkflowApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

/** In-memory importer: "plan" counts the items not imported yet. */
class SppFakeImporter implements StarterContentImporter
{
    /** @var list<string> */
    public array $imported = [];

    public function export(array $options): array
    {
        return [];
    }

    public function id(): string
    {
        return 'fake.kb';
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
        return ['items' => count(array_diff($data['items'] ?? [], $this->imported))];
    }

    public function import(array $data, array $options = []): array
    {
        $new = array_diff($data['items'], $this->imported);
        $this->imported = array_merge($this->imported, $new);

        return ['created' => ['items' => count($new)], 'skipped' => ['items' => 0]];
    }
}

beforeEach(function (): void {
    Cache::flush();
    foreach (['role_user', 'permission_role', 'permissions', 'roles', 'users', 'core_config_package_runs', 'core_starter_pack_applications'] as $table) {
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
    (require base_path('cms/Core/database/migrations/2026_11_06_090000_create_starter_pack_applications_table.php'))->up();

    Permission::create(['group' => 'helpdesk', 'name' => 'agent', 'title' => 'agent']);
    DB::table('users')->insert(['id' => 1, 'username' => 'root']);
    Role::create(['name' => 'Editor', 'custom' => ['level' => 5]]);
});

/**
 * Build a pack in a temp folder.
 *
 * @param array<string, string> $modules
 * @param array{roles?: list<array<string, mixed>>, items?: list<string>, config?: array<string, mixed>, version?: string, content?: bool} $opts
 */
function sppPack(array $modules = ['Core' => '*'], array $opts = []): StarterPackManifest
{
    $dir = sys_get_temp_dir() . '/spp-' . bin2hex(random_bytes(4));
    mkdir($dir . '/content', 0777, true);
    $resolved = ['config' => null, 'roles' => null, 'workflows' => null];
    if (isset($opts['roles'])) {
        file_put_contents($dir . '/roles.json', json_encode($opts['roles']));
        $resolved['roles'] = $dir . '/roles.json';
    }
    if (isset($opts['config'])) {
        file_put_contents($dir . '/config.json', json_encode($opts['config'], JSON_FORCE_OBJECT));
        $resolved['config'] = $dir . '/config.json';
    }
    $content = [];
    if ($opts['content'] ?? true) {
        file_put_contents($dir . '/content/kb.json', json_encode(['items' => $opts['items'] ?? ['a', 'b']]));
        $content[] = ['module' => 'Core', 'importer' => 'fake.kb', 'file' => 'content/kb.json', 'path' => $dir . '/content/kb.json'];
    }

    return new StarterPackManifest(
        $dir, 'demo', $opts['version'] ?? '1.0.0', ['vi' => 'Demo', 'en' => 'Demo'], ['vi' => 'Demo', 'en' => 'Demo'],
        $modules, $resolved['config'] ? 'config.json' : null, $resolved['roles'] ? 'roles.json' : null, null, $content, [], $resolved,
    );
}

/**
 * Planner with a fake hub and the fake importer registered.
 *
 * @param list<array<string, mixed>> $hub
 * @param array<string, list<class-string>>|null $probes
 * @param ConfigPackageDiffer|null $differ Replacement for the configuration package dry run.
 *
 * @return array{0: StarterPackPlanner, 1: SppFakeImporter}
 */
function sppPlanner(array $hub = [], bool $production = false, ?array $probes = null, ?ConfigPackageDiffer $differ = null, ?callable $preflight = null): array
{
    $client = new class($hub) {
        public function __construct(private array $modules)
        {
        }

        public function get(string $path): array
        {
            return ['modules' => $this->modules];
        }
    };
    $registry = new StarterContentRegistry();
    $importer = new SppFakeImporter();
    $registry->register($importer);

    $planner = new StarterPackPlanner(
        new ModuleCatalog(fn () => $client), $registry, app(StarterRoleApplier::class), app(StarterWorkflowApplier::class),
        $differ ?? app(ConfigPackageDiffer::class), fn (): bool => $production, $probes, $preflight,
    );

    return [$planner, $importer];
}

/**
 * Hub entry for a module.
 *
 * @param array<string, mixed> $version
 *
 * @return array<string, mixed>
 */
function sppHubModule(string $name, array $version = []): array
{
    return ['name' => $name, 'latest_version' => '1.0.0', 'versions' => [array_merge(['version' => '1.0.0', 'revoked' => false], $version)]];
}

it('plans everything as to do on an empty site', function (): void {
    [$planner] = sppPlanner([sppHubModule('Zzdemo', ['irreversible' => ['2026_01_01_x']])]);
    $role = [['name' => 'Help agent', 'permissions' => ['helpdesk|agent']]];

    $plan = $planner->plan(sppPack(['Core' => '*', 'Zzdemo' => '>=1.0.0'], ['roles' => $role]), 1);

    expect($plan->canApply())->toBeTrue()
        ->and($plan->hasChanges())->toBeTrue()
        ->and(array_column($plan->modules, 'state', 'name'))->toBe(['Core' => 'enabled', 'Zzdemo' => 'to_install'])
        ->and($plan->modules[1]['irreversible'])->toBe(['2026_01_01_x'])
        ->and($plan->roles['created'])->toBe(['Help agent'])
        ->and($plan->content[0]['counts'])->toBe(['items' => 2]);
});

it('shows nothing to do after the pack was applied', function (): void {
    [$planner, $importer] = sppPlanner();
    $role = [['name' => 'Help agent', 'permissions' => ['helpdesk|agent']]];
    $pack = sppPack(['Core' => '*'], ['roles' => $role]);

    app(StarterRoleApplier::class)->apply($role, 1);
    $importer->import(['items' => ['a', 'b']]);
    $plan = $planner->plan($pack, 1);

    expect($plan->hasChanges())->toBeFalse()
        ->and($plan->canApply())->toBeTrue()
        ->and($plan->roles['created'])->toBe([]);
});

it('is identical on a second call and writes nothing', function (): void {
    [$planner] = sppPlanner();
    $pack = sppPack(['Core' => '*'], ['roles' => [['name' => 'Help agent']], 'config' => []]);
    $tables = ['roles', 'permission_role', 'role_user', 'users', 'core_config_package_runs', 'core_starter_pack_applications'];
    $count = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
    $before = $count();
    $files = glob($pack->packDir . '/*');

    $first = $planner->plan($pack, 1);
    $second = $planner->plan($pack, 1);

    expect($second->toArray())->toBe($first->toArray())
        ->and($count())->toBe($before)
        ->and(glob($pack->packDir . '/*'))->toBe($files);
});

it('blocks a module that is not installed and not on the hub', function (): void {
    [$planner] = sppPlanner();

    $plan = $planner->plan(sppPack(['Core' => '*', 'Zzgone' => '*']), 1);

    expect($plan->canApply())->toBeFalse()->and($plan->blockers[0])->toContain('Zzgone');
});

it('blocks a module whose constraint no published version meets', function (): void {
    [$planner] = sppPlanner([sppHubModule('Zzdemo')]);

    $plan = $planner->plan(sppPack(['Core' => '*', 'Zzdemo' => '>=2.0.0']), 1);

    expect($plan->canApply())->toBeFalse()->and($plan->blockers[0])->toContain('No published version');
});

it('blocks an installed module that misses the version constraint', function (): void {
    [$planner] = sppPlanner();

    $plan = $planner->plan(sppPack(['Core' => '>=9999.0.0']), 1);

    expect($plan->canApply())->toBeFalse()->and($plan->blockers[0])->toContain('does not meet');
});

it('blocks a requires conflict', function (): void {
    [$planner] = sppPlanner([sppHubModule('Zzdemo', ['requires' => ['Zzmissing']])]);

    $plan = $planner->plan(sppPack(['Core' => '*', 'Zzdemo' => '*']), 1);

    expect($plan->canApply())->toBeFalse()->and($plan->blockers[0])->toContain('requires Zzmissing');
});

it('blocks a production site that holds content and allows an empty one or a dev site', function (): void {
    $probes = ['Core' => [Role::class]];

    [$prod] = sppPlanner([], true, $probes);
    expect($prod->plan(sppPack(), 1)->blockers[0])->toContain('flagged as production');

    DB::table('roles')->delete();
    expect($prod->plan(sppPack(), 1)->canApply())->toBeTrue();

    Role::create(['name' => 'Editor', 'custom' => ['level' => 5]]);
    [$dev] = sppPlanner([], false, $probes);
    expect($dev->plan(sppPack(), 1)->canApply())->toBeTrue();

    [$unknown] = sppPlanner([], true, []);
    $plan = $unknown->plan(sppPack(), 1);
    expect($plan->canApply())->toBeFalse()->and($plan->warnings)->not->toBe([]);
});

it('blocks a pack older than the version already applied', function (): void {
    [$planner] = sppPlanner();
    StarterPackApplication::create(['pack_key' => 'demo', 'pack_version' => '1.2.0', 'status' => 'succeeded', 'steps' => [], 'started_at' => now()]);
    StarterPackApplication::create(['pack_key' => 'demo', 'pack_version' => '3.0.0', 'status' => 'failed', 'steps' => [], 'started_at' => now()]);

    expect($planner->plan(sppPack(['Core' => '*'], ['version' => '1.1.0']), 1)->blockers[0])->toContain('older than version 1.2.0')
        ->and($planner->plan(sppPack(['Core' => '*'], ['version' => '1.2.0']), 1)->canApply())->toBeTrue();
});

it('blocks invalid configuration and leaves no run row behind', function (): void {
    [$planner] = sppPlanner();

    $plan = $planner->plan(sppPack(['Core' => '*'], ['config' => []]), 1);

    expect($plan->canApply())->toBeFalse()
        ->and($plan->config['status'])->toBe('failed')
        ->and(DB::table('core_config_package_runs')->count())->toBe(0);
});

it('blocks invalid content data', function (): void {
    [$planner] = sppPlanner();
    $pack = sppPack(['Core' => '*'], ['items' => []]);
    file_put_contents($pack->packDir . '/content/kb.json', '{"nope": 1}');

    $plan = $planner->plan($pack, 1);

    expect($plan->canApply())->toBeFalse()->and($plan->blockers[0])->toContain('items is required');
});

it('reports a content importer of a module that is not enabled yet as pending', function (): void {
    [$planner] = sppPlanner([sppHubModule('Zzdemo')]);
    $pack = sppPack(['Core' => '*', 'Zzdemo' => '*'], ['content' => false]);
    $pack = new StarterPackManifest(
        $pack->packDir, $pack->key, $pack->version, $pack->name, $pack->description, $pack->modules, null, null, null,
        [['module' => 'Zzdemo', 'importer' => 'zz.kb', 'file' => 'content/x.json', 'path' => $pack->packDir . '/content/x.json']], [], $pack->resolved,
    );

    $plan = $planner->plan($pack, 1);

    expect($plan->canApply())->toBeTrue()->and($plan->content[0]['counts'])->toBeNull()->and($plan->hasChanges())->toBeTrue();
});

/**
 * Differ whose dry run returns a fixed summary (no database access).
 *
 * @param array<string, array<string, int>> $counts Counts per configuration section.
 */
function sppFakeDiffer(array $counts): ConfigPackageDiffer
{
    return new class($counts) extends ConfigPackageDiffer {
        /** @param array<string, array<string, int>> $counts */
        public function __construct(private array $counts)
        {
        }

        public function dryRun(array $package, int $userId): ConfigPackageRun
        {
            $sections = [];
            foreach ($this->counts as $name => $counts) {
                $sections[$name] = ['status' => 'changes', 'counts' => $counts + ['create' => 0, 'update' => 0, 'unchanged' => 0, 'blocked' => 0, 'skipped' => 0], 'total' => array_sum($counts)];
            }

            return (new ConfigPackageRun())->forceFill(['status' => 'succeeded', 'summary' => ['sections' => $sections, 'warnings' => 0, 'choices' => 0]]);
        }
    };
}

it('warns that settings rows missing on the site are not added, naming the cause', function (): void {
    [$planner] = sppPlanner([], false, null, sppFakeDiffer(['settings' => ['create' => 2, 'blocked' => 45]]));

    $plan = $planner->plan(sppPack(['Core' => '*'], ['config' => ['sections' => []]]), 1);

    expect($plan->canApply())->toBeTrue()
        ->and($plan->warnings)->toContain("45 setting(s) of the configuration cannot be added because the module's default settings are not installed on this site yet.");
});

it('adds no missing-settings warning when no settings item is blocked', function (): void {
    [$planner] = sppPlanner([], false, null, sppFakeDiffer(['settings' => ['create' => 2, 'update' => 1], 'roles' => ['blocked' => 3]]));

    $plan = $planner->plan(sppPack(['Core' => '*'], ['config' => ['sections' => []]]), 1);

    expect(implode(' ', $plan->warnings))->not->toContain('default settings');
});

it('names the publisher and impact of a partner module the pack would install', function (): void {
    $details = ['publisher' => ['key' => 'acme', 'type' => 'partner', 'name' => 'Acme Studio'], 'impact' => ['permissions' => ['x'], 'menus' => 1, 'routes' => ['public' => 0, 'admin' => 2, 'api' => 0], 'tables_created' => [], 'tables_altered' => [], 'irreversible' => false, 'migrations' => 0]];
    $warning = ['code' => \Cms\Core\Services\Modules\Install\InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL, 'message' => 'x', 'details' => $details];
    $preflight = fn (string $name, string $version) => new \Cms\Core\Services\Modules\Install\PreflightResult($name, $version, [], $name === 'Gallery' ? [$warning] : [], []);
    [$planner] = sppPlanner([sppHubModule('Gallery'), sppHubModule('Plain')], preflight: $preflight);

    $plan = $planner->plan(sppPack(['Core' => '*', 'Gallery' => '>=1.0.0', 'Plain' => '>=1.0.0']), 1);
    $byName = array_column($plan->modules, null, 'name');

    expect($byName['Gallery']['third_party'])->toBe($details)
        ->and($byName['Plain']['third_party'])->toBeNull()
        ->and($byName['Core']['third_party'])->toBeNull();
});

it('plans a module without partner details when its install check cannot run', function (): void {
    [$planner] = sppPlanner([sppHubModule('Gallery')], preflight: function (): never {
        throw new RuntimeException('hub down');
    });

    $plan = $planner->plan(sppPack(['Core' => '*', 'Gallery' => '>=1.0.0']), 1);

    expect($plan->canApply())->toBeTrue()
        ->and(array_column($plan->modules, 'third_party', 'name')['Gallery'])->toBeNull();
});
