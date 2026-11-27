<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Runs the real Core migrations that shape workflow_steps (the original enum, then the string change).
 */
function stepTypeMigrate(callable $beforeChange = null): void
{
    foreach (['workflow_instance_steps', 'workflow_instances', 'workflow_connections', 'workflow_steps', 'workflow_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    $dir = base_path('cms/Core/database/migrations/');
    (require $dir . '2025_06_08_144311_create_workflows_data_tables.php')->up();

    if ($beforeChange) {
        $beforeChange();
    }

    (require $dir . '2026_11_24_000001_change_workflow_steps_step_type_to_string.php')->up();
    (require $dir . '2026_11_27_000100_add_uuid_to_workflow_projects.php')->up();
}

function stepTypeActingUser(): User
{
    $user = new class extends User {
        public function permissionKeys(): array
        {
            return ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.create', 'workflow_project.update'];
        }
    };
    $user->forceFill(['id' => random_int(1, 99999), 'name' => 'Step Type Tester', 'email' => uniqid('st_', true) . '@example.com', 'password' => 'x']);
    $user->exists = true;

    return $user;
}

function stepTypeProject(): WorkflowProject
{
    return WorkflowProject::query()->create([
        'name' => 'Types', 'slug' => 'types-' . uniqid(), 'trigger_event' => 'manual',
        'target_entity_type' => 'App\\Models\\User', 'is_active' => false, 'tags' => ['business'],
    ]);
}

/**
 * @param array<string, array<string, mixed>> $steps step code => node data (step_type, step_config)
 */
function stepTypeNodes(array $steps): array
{
    $nodes = [];
    $x = 0;
    foreach ($steps as $code => $data) {
        $nodes[] = ['id' => $code, 'type' => 'custom', 'position' => ['x' => $x += 100, 'y' => 0], 'data' => ['step_code' => $code, 'name' => $code] + $data];
    }

    return $nodes;
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    config(['core.workflow_security' => ['services' => [], 'jobs' => [], 'events' => [], 'commands' => [], 'models' => 'registry']]);
});

it('lists every runner key and the legacy values as storable', function (): void {
    $storable = StepTypeCatalog::storable();

    expect($storable)->toContain('retrieve', 'ai_reasoning', 'create_object', 'loop_each', 'blueprint_callable', 'script', 'parallel')
        ->and(StepTypeCatalog::isStorable('rocket'))->toBeFalse();
});

it('keeps an existing script row unchanged through the migration', function (): void {
    stepTypeMigrate(function (): void {
        $projectId = DB::table('workflow_projects')->insertGetId([
            'name' => 'Old', 'slug' => 'old', 'target_entity_type' => 'X', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workflow_steps')->insert([
            'project_id' => $projectId, 'step_code' => 'legacy', 'name' => 'Legacy', 'step_type' => 'script',
            'step_config' => json_encode(['node_type' => 'custom']), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    expect(DB::table('workflow_steps')->where('step_code', 'legacy')->value('step_type'))->toBe('script');

    // No runner is registered for `script`: the step keeps failing with a plain message, it never runs another runner.
    $step = \Cms\Core\Workflows\Runtime\StepDefinition::fromModel(WorkflowStep::query()->where('step_code', 'legacy')->firstOrFail());
    $result = app(\Cms\Core\Workflows\Runtime\StepRunnerRegistry::class)->get($step->stepType)
        ->run($step, new \Cms\Core\Workflows\Runtime\WorkflowContext(new WorkflowProject()));

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toStartWith('Unknown step_type: script');
});

it('saves modern runner types and keeps them after reload', function (): void {
    stepTypeMigrate();
    Sanctum::actingAs(stepTypeActingUser());
    $project = stepTypeProject();

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", [
        'nodes' => stepTypeNodes([
            'r' => ['step_type' => 'retrieve', 'step_config' => ['model' => \Cms\Core\Models\Tag::class, 'storeAs' => 'vars.p']],
            'ai' => ['step_type' => 'ai_reasoning', 'step_config' => []],
            'c' => ['step_type' => 'create_object', 'step_config' => ['model' => \Cms\Core\Models\Tag::class]],
            'l' => ['step_type' => 'loop_each', 'step_config' => []],
        ]),
        'edges' => [],
    ])->assertOk();

    $types = WorkflowStep::query()->where('project_id', $project->id)->pluck('step_type', 'step_code')->all();

    expect($types)->toBe(['r' => 'retrieve', 'ai' => 'ai_reasoning', 'c' => 'create_object', 'l' => 'loop_each']);
});

it('refuses a node with an unknown type and lists its step code', function (): void {
    stepTypeMigrate();
    Sanctum::actingAs(stepTypeActingUser());
    $project = stepTypeProject();

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", [
        'nodes' => stepTypeNodes(['ok' => ['step_type' => 'log_message'], 'bad_one' => ['step_type' => 'rocket']]),
        'edges' => [],
    ])->assertStatus(422)->assertJsonPath('message', 'Unknown step type for steps: bad_one.');

    expect(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('refuses a blueprint_callable step whose class the guard refuses', function (): void {
    stepTypeMigrate();
    Sanctum::actingAs(stepTypeActingUser());
    $project = stepTypeProject();

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", [
        'nodes' => stepTypeNodes(['call' => ['step_type' => 'blueprint_callable', 'step_config' => [
            'callableType' => 'command', 'class' => \Illuminate\Database\Console\WipeCommand::class,
        ]]]),
        'edges' => [],
    ])->assertStatus(422)->assertJsonPath('message', 'call: blueprint_callable: class not allowed');

    expect(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('lets the config package import a modern type and refuse a refused class', function (): void {
    stepTypeMigrate();

    $row = fn (array $step): array => [
        'slug' => 'pkg-' . uniqid(), 'name' => 'Pkg', 'trigger_event' => null, 'target_entity_type' => 'X',
        'is_active' => true, 'tags' => null, 'description' => null,
        'steps' => [['step_code' => 's1', 'name' => 'S1', 'step_config' => null, 'input_schema' => null,
            'output_schema' => null, 'position_x' => 0, 'position_y' => 0, 'is_start' => true] + $step],
        'connections' => [],
    ];

    $modern = $row(['step_type' => 'loop_each']);
    $refused = $row(['step_type' => 'blueprint_callable', 'step_config' => [
        'callableType' => 'service', 'class' => \Illuminate\Support\Facades\Artisan::class, 'method' => 'call',
    ]]);

    $section = new WorkflowsSection();
    $modernItem = $section->diff([$modern], new DiffContext())->item($modern['slug']);
    $refusedItem = $section->diff([$refused], new DiffContext())->item($refused['slug']);

    expect($modernItem['action'])->toBe('create')
        ->and($refusedItem['action'])->toBe('blocked')
        ->and($refusedItem['reason'])->toBe('step s1: blueprint_callable: class not allowed');
});
