<?php

declare(strict_types=1);

use App\Support\Modules\ArtisanSubprocess;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\StarterPacks\StarterPackApplier;
use Cms\Core\Services\StarterPacks\StarterPackManifest;
use Cms\Core\Services\StarterPacks\StarterPackPlan;
use Cms\Core\Services\StarterPacks\StarterPackPlanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

/** Shared state of the command fakes. */
class SpcState
{
    /** @var list<string> Calls in order. */
    public static array $log = [];

    /** @var array<string, mixed> Options handed to apply() or queue(). */
    public static array $options = [];

    public static ?int $userId = null;

    /** @var list<string> Blockers the fake planner reports. */
    public static array $blockers = [];

    /** @var list<array<string, mixed>> Irreversible module the fake planner reports. */
    public static array $irreversible = [];

    /** Status the fake applier leaves on the ledger entry. */
    public static string $outcome = StarterPackApplication::STATUS_SUCCEEDED;

    /** Reset every field. */
    public static function reset(): void
    {
        self::$log = [];
        self::$options = [];
        self::$userId = null;
        self::$blockers = [];
        self::$irreversible = [];
        self::$outcome = StarterPackApplication::STATUS_SUCCEEDED;
    }
}

/** Planner that reads nothing and reports what the test asks for. */
class SpcPlanner extends StarterPackPlanner
{
    public function __construct()
    {
    }

    public function plan(StarterPackManifest $pack, ?int $actingUserId = null): StarterPackPlan
    {
        SpcState::$log[] = 'plan';
        $modules = [
            ['name' => 'Core', 'constraint' => '*', 'version' => '1.0.0', 'state' => StarterPackPlan::STATE_ENABLED, 'irreversible' => []],
            ['name' => 'Helpdesk', 'constraint' => '*', 'version' => '1.2.0', 'state' => StarterPackPlan::STATE_TO_INSTALL, 'irreversible' => SpcState::$irreversible],
        ];

        return new StarterPackPlan(
            $pack->key,
            $pack->version,
            $modules,
            ['status' => 'changes', 'sections' => ['settings' => ['status' => 'changes', 'counts' => ['create' => 2, 'update' => 1, 'unchanged' => 0, 'blocked' => 0, 'skipped' => 0], 'total' => 3]], 'warnings' => 0],
            ['created' => ['Agent'], 'skipped' => [], 'missing_permissions' => []],
            null,
            [['module' => 'Helpdesk', 'importer' => 'helpdesk.kb', 'file' => 'content/kb.json', 'counts' => ['articles' => 4]]],
            SpcState::$blockers,
            ['Sample warning.'],
        );
    }
}

/** Applier that records its calls and writes a ledger entry instead of running steps. */
class SpcApplier extends StarterPackApplier
{
    public function __construct()
    {
    }

    public function queue(StarterPackManifest $pack, ?int $userId, array $options = []): StarterPackApplication
    {
        SpcState::$log[] = 'queue';
        SpcState::$options = $options;
        SpcState::$userId = $userId;

        return $this->entry($pack, $userId, StarterPackApplication::STATUS_STARTED);
    }

    public function apply(StarterPackManifest $pack, ?int $userId, array $options = [], ?StarterPackApplication $application = null): StarterPackApplication
    {
        SpcState::$log[] = 'apply';
        SpcState::$options = $options;
        SpcState::$userId = $userId;

        return $this->entry($pack, $userId, SpcState::$outcome);
    }

    /** Ledger entry with every step succeeded (or failed at the content step). */
    private function entry(StarterPackManifest $pack, ?int $userId, string $status): StarterPackApplication
    {
        $steps = array_map(static fn (string $name): array => [
            'name' => $name,
            'status' => $status === StarterPackApplication::STATUS_STARTED ? 'pending' : ($status === StarterPackApplication::STATUS_FAILED && $name === 'content' ? 'failed' : 'succeeded'),
            'counts' => [],
            'message' => $status === StarterPackApplication::STATUS_FAILED && $name === 'content' ? 'The sample content is not available yet; apply the pack again in a few minutes.' : null,
        ], StarterPackApplier::STEPS);

        return StarterPackApplication::query()->create([
            'pack_key' => $pack->key,
            'pack_version' => $pack->version,
            'status' => $status,
            'steps' => $steps,
            'applied_by' => $userId,
            'background_task_uuid' => '00000000-0000-4000-8000-0000000000aa',
            'started_at' => now(),
        ]);
    }
}

beforeEach(function (): void {
    SpcState::reset();
    $this->spcRoot = sys_get_temp_dir() . '/starter-pack-apply-' . uniqid('', true);
    File::ensureDirectoryExists($this->spcRoot);

    foreach (['core_starter_pack_applications', 'role_user', 'roles', 'users'] as $table) {
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
    Schema::create('role_user', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('user_id');
    });
    (require base_path('cms/Core/database/migrations/2026_11_06_090000_create_starter_pack_applications_table.php'))->up();

    DB::table('users')->insert([
        ['id' => 1, 'username' => 'root', 'email' => 'root@example.test'],
        ['id' => 2, 'username' => 'editor', 'email' => 'editor@example.test'],
    ]);

    app()->instance(StarterPackPlanner::class, new SpcPlanner());
    app()->instance(StarterPackApplier::class, new SpcApplier());
    app()->instance(ArtisanSubprocess::class, new ArtisanSubprocess());
});

afterEach(function (): void {
    File::deleteDirectory($this->spcRoot);
});

/** Write a pack folder; $withConfig adds a configuration package file. */
function spcPack(string $root, string $key, bool $withConfig = true): void
{
    $manifest = [
        'pack_format' => 1, 'key' => $key, 'version' => '1.0.0',
        'name' => ['vi' => 'Gói', 'en' => 'Pack'], 'description' => ['vi' => 'Mô tả', 'en' => 'Description'],
        'modules' => ['Core' => '*'],
        'config' => $withConfig ? 'config.json' : null, 'roles' => null, 'workflows' => null,
        'content' => [], 'post_apply' => [],
    ];
    File::ensureDirectoryExists("{$root}/{$key}");
    File::put("{$root}/{$key}/pack.json", json_encode($manifest));
    if ($withConfig) {
        File::put("{$root}/{$key}/config.json", json_encode([
            'format' => \Cms\Core\Services\ConfigPackage\PackageFormat::FORMAT,
            'format_version' => \Cms\Core\Services\ConfigPackage\PackageFormat::VERSION,
            'created_at' => '2026-11-11T00:00:00+00:00',
            'sections' => [],
            'checksum' => \Cms\Core\Services\ConfigPackage\PackageFormat::checksum([]),
        ]));
    }
}

/** Run the command against the temp packs folder. */
function spcApply(object $test, array $args): \Illuminate\Testing\PendingCommand
{
    return $test->artisan('cms:starter-pack-apply', ['--path' => $test->spcRoot] + $args);
}

it('prints the plan and writes nothing on --dry-run', function (): void {
    spcPack($this->spcRoot, 'help-center');

    spcApply($this, ['key' => 'help-center', '--dry-run' => true, '--user' => '1'])
        ->expectsOutputToContain('Helpdesk')
        ->expectsOutputToContain('Sample warning.')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(0);

    expect(SpcState::$log)->toBe(['plan'])
        ->and(StarterPackApplication::query()->count())->toBe(0);
});

it('exits 1 and prints every blocker', function (): void {
    spcPack($this->spcRoot, 'help-center');
    SpcState::$blockers = ['Module Helpdesk is not published on the hub.', 'Another blocker.'];

    spcApply($this, ['key' => 'help-center', '--user' => '1'])
        ->expectsOutputToContain('Module Helpdesk is not published on the hub.')
        ->expectsOutputToContain('Another blocker.')
        ->assertExitCode(1);

    expect(SpcState::$log)->toBe(['plan']);
});

it('also refuses a blocker on --dry-run', function (): void {
    spcPack($this->spcRoot, 'help-center');
    SpcState::$blockers = ['Module Helpdesk is not published on the hub.'];

    spcApply($this, ['key' => 'help-center', '--dry-run' => true, '--user' => '1'])->assertExitCode(1);
});

it('exits 1 for an unknown key and lists the available keys', function (): void {
    spcPack($this->spcRoot, 'help-center');
    spcPack($this->spcRoot, 'blog');

    spcApply($this, ['key' => 'nope'])
        ->expectsOutputToContain('Unknown starter pack "nope". Available: blog, help-center.')
        ->assertExitCode(1);

    expect(SpcState::$log)->toBe([]);
});

it('applies in the same process with --sync and reports the steps', function (): void {
    spcPack($this->spcRoot, 'help-center');

    spcApply($this, ['key' => 'help-center', '--sync' => true, '--user' => 'root@example.test', '--status' => 'published', '--confirm-irreversible' => true])
        ->expectsOutputToContain('post_apply')
        ->expectsOutputToContain('Starter pack help-center 1.0.0: succeeded')
        ->assertExitCode(0);

    expect(SpcState::$log)->toBe(['plan', 'apply'])
        ->and(SpcState::$userId)->toBe(1)
        ->and(SpcState::$options[StarterPackApplier::OPTION_CONTENT_STATUS])->toBe('published')
        ->and(SpcState::$options[StarterPackApplier::OPTION_CONFIRM_IRREVERSIBLE])->toBeTrue()
        ->and(SpcState::$options[StarterPackApplier::OPTION_YIELD] ?? false)->toBeFalse();
});

it('exits 1 when the synchronous apply fails and shows the failed step', function (): void {
    spcPack($this->spcRoot, 'help-center');
    SpcState::$outcome = StarterPackApplication::STATUS_FAILED;

    spcApply($this, ['key' => 'help-center', '--sync' => true, '--user' => '1'])
        ->expectsOutputToContain('apply the pack again in a few minutes')
        ->expectsOutputToContain('Starter pack help-center 1.0.0: failed')
        ->assertExitCode(1);
});

it('dispatches the job and prints the task uuid without --sync', function (): void {
    spcPack($this->spcRoot, 'help-center');

    spcApply($this, ['key' => 'help-center', '--user' => '1'])
        ->expectsOutputToContain('Apply queued. Task 00000000-0000-4000-8000-0000000000aa')
        ->assertExitCode(0);

    expect(SpcState::$log)->toBe(['plan', 'queue'])
        ->and(SpcState::$options[StarterPackApplier::OPTION_CONTENT_STATUS])->toBe('draft');
});

it('requires a Webmaster user when the pack carries a configuration', function (): void {
    spcPack($this->spcRoot, 'help-center');

    spcApply($this, ['key' => 'help-center'])
        ->expectsOutputToContain('--user')
        ->assertExitCode(1);

    spcApply($this, ['key' => 'help-center', '--user' => '999'])
        ->expectsOutputToContain('No user found')
        ->assertExitCode(1);

    spcApply($this, ['key' => 'help-center', '--user' => 'editor@example.test'])
        ->expectsOutputToContain('is not a Webmaster')
        ->assertExitCode(1);

    expect(SpcState::$log)->toBe([]);
});

it('needs no user for a pack without configuration', function (): void {
    spcPack($this->spcRoot, 'plain', false);

    spcApply($this, ['key' => 'plain', '--sync' => true])->assertExitCode(0);

    expect(SpcState::$userId)->toBeNull();
});

it('rejects an unknown content status', function (): void {
    spcPack($this->spcRoot, 'help-center');

    spcApply($this, ['key' => 'help-center', '--user' => '1', '--status' => 'archived'])
        ->expectsOutputToContain('--status must be draft or published')
        ->assertExitCode(1);

    expect(SpcState::$log)->toBe([]);
});

it('asks for confirmation of irreversible module changes before queuing anything', function (): void {
    spcPack($this->spcRoot, 'help-center');
    SpcState::$irreversible = ['drops a column'];

    spcApply($this, ['key' => 'help-center', '--user' => '1'])
        ->expectsOutputToContain('--confirm-irreversible')
        ->assertExitCode(1);

    expect(SpcState::$log)->toBe(['plan']);
});

it('waits for the hand-over to finish with --wait', function (): void {
    spcPack($this->spcRoot, 'help-center');

    // The fake queue() leaves the entry started; finish it so the first poll sees the end state.
    spcApply($this, ['key' => 'help-center', '--user' => '1', '--wait' => true, '--timeout' => 1, '--interval' => 0])
        ->expectsOutputToContain('Waiting')
        ->assertExitCode(1);

    expect(StarterPackApplication::query()->count())->toBe(1);
});

it('finishes --wait when the ledger entry reaches an end state', function (): void {
    spcPack($this->spcRoot, 'help-center');
    app()->instance(StarterPackApplier::class, new class extends SpcApplier {
        public function queue(StarterPackManifest $pack, ?int $userId, array $options = []): StarterPackApplication
        {
            $application = parent::queue($pack, $userId, $options);
            $application->forceFill(['status' => StarterPackApplication::STATUS_SUCCEEDED])->save();

            return $application;
        }
    });

    spcApply($this, ['key' => 'help-center', '--user' => '1', '--wait' => true, '--timeout' => 5, '--interval' => 0])
        ->expectsOutputToContain('Starter pack help-center 1.0.0: succeeded')
        ->assertExitCode(0);
});
