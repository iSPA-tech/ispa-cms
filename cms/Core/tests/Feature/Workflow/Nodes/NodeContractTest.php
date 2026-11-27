<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Workflow\Nodes;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Cms\Core\Workflows\Nodes\Attributes\WorkflowNode;
use Cms\Core\Workflows\Nodes\ModuleNodeRunner;
use Cms\Core\Workflows\Nodes\NodeConfigUpgrade;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Nodes\NodeResult;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class);

final class NodeFixtureService
{
    #[WorkflowNode(
        type: 'fixture.greet',
        version: 2,
        safety: ['internal_write'],
        approval: 'on',
        label: 'text.workflow.node.fixture_greet.title',
        schema: ['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'x-expr' => true],
            'storeAs' => ['type' => 'string'],
        ]],
        defaults: ['name' => 'root.name', 'storeAs' => 'vars.greeting'],
        upgrader: NodeFixtureUpgrader::class,
        result: [['key' => 'text', 'type' => 'string', 'label' => 'Greeting text']],
    )]
    public function greet(array $config, WorkflowContext $context): array
    {
        return ['text' => 'Hello ' . $config['name']];
    }

    #[WorkflowNode(type: 'fixture.secret', safety: ['read'], permission: 'fixture.secret_use')]
    public function secret(array $config, WorkflowContext $context): array
    {
        return ['ok' => true];
    }

    #[WorkflowNode(type: 'fixture.spend', safety: ['ai_spend'], schema: ['type' => 'object', 'properties' => ['storeAs' => ['type' => 'string']]])]
    public function spend(array $config, WorkflowContext $context): NodeResult
    {
        return NodeResult::withUsage(['answer' => 42], ['task_key' => 'fixture', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_usd' => 0.0123]);
    }
}

final class NodeFixtureUpgrader implements NodeConfigUpgrade
{
    public function upgrade(int $from, array $config): array
    {
        if ($from === 1 && isset($config['who'])) {
            $config['name'] = $config['who'];
            unset($config['who']);
        }

        return $config;
    }
}

function nodeMigrate(): void
{
    foreach (['workflow_instance_steps', 'workflow_instances', 'workflow_connections', 'workflow_steps', 'workflow_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    $dir = base_path('cms/Core/database/migrations/');
    (require $dir . '2025_06_08_144311_create_workflows_data_tables.php')->up();
    (require $dir . '2026_11_24_000001_change_workflow_steps_step_type_to_string.php')->up();
    (require $dir . '2026_11_27_000100_add_uuid_to_workflow_projects.php')->up();
}

/** @param list<string> $extra */
function nodeUser(array $extra = []): User
{
    $user = new class extends User {
        /** @var list<string> */
        public array $keys = [];

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $user->keys = array_merge(['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.create', 'workflow_project.update'], $extra);
    $user->forceFill(['id' => random_int(1, 99999), 'name' => 'Node Tester', 'email' => uniqid('node_', true) . '@example.com', 'password' => 'x']);
    $user->exists = true;

    return $user;
}

function nodeProject(): WorkflowProject
{
    return WorkflowProject::query()->create([
        'name' => 'Nodes', 'slug' => 'nodes-' . uniqid(), 'trigger_event' => 'manual',
        'target_entity_type' => 'App\\Models\\User', 'is_active' => false, 'tags' => ['business'],
    ]);
}

/** @param array<string, array<string, mixed>> $steps */
function nodeGraph(array $steps, array $edges = []): array
{
    $nodes = [];
    foreach ($steps as $code => $data) {
        $nodes[] = ['id' => $code, 'type' => 'custom', 'position' => ['x' => 0, 'y' => 0], 'data' => ['step_code' => $code, 'name' => $code] + $data];
    }

    return [
        'nodes' => $nodes,
        'edges' => array_map(static fn (array $e): array => ['source' => $e[0], 'target' => $e[1]], $edges),
    ];
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    config([
        'core.workflow_security' => ['services' => [], 'jobs' => [], 'events' => [], 'commands' => [], 'models' => 'registry'],
        'core.workflow_nodes.allowlisted_namespaces' => ['Cms\\Core\\Tests\\Feature\\Workflow\\Nodes\\'],
    ]);
    NodeRegistry::registerModuleService(NodeFixtureService::class);
});

it('describes every runner key and keeps the legacy values storable', function (): void {
    $registry = NodeRegistry::current();

    foreach (array_keys(config('core.workflow.step_runners')) as $type) {
        expect($registry->has($type))->toBeTrue();
    }

    expect(StepTypeCatalog::storable())->toContain(...StepTypeCatalog::LEGACY)
        ->and(StepTypeCatalog::isStorable('fixture.greet'))->toBeTrue()
        ->and(StepTypeCatalog::isStorable('rocket'))->toBeFalse()
        ->and($registry->get('change')->safety)->toBe(['internal_write'])
        ->and($registry->get('ai_reasoning')->taintOutput)->toBeTrue();
});

it('discovers module nodes only from allowlisted namespaces', function (): void {
    expect(NodeRegistry::current()->get('fixture.greet')?->service)->toBe(NodeFixtureService::class);

    config(['core.workflow_nodes.allowlisted_namespaces' => ['Cms\\Content\\Services\\']]);

    expect(NodeRegistry::current()->has('fixture.greet'))->toBeFalse()
        ->and(StepTypeCatalog::isStorable('fixture.greet'))->toBeFalse();
});

it('runs a module node through ModuleNodeRunner with expressions and storeAs', function (): void {
    $runner = app(StepRunnerRegistry::class)->get('fixture.greet');
    expect($runner)->toBeInstanceOf(ModuleNodeRunner::class);

    $context = new WorkflowContext(new WorkflowProject(['name' => 'Ana']));
    $step = new StepDefinition(1, 1, 'fixture.greet', 'g', 'g', 'G', ['name' => 'root.name', 'storeAs' => 'vars.greeting', '_v' => 2]);
    $result = $runner->run($step, $context);

    expect($result->status)->toBe('success')
        ->and($context->getVar('greeting'))->toBe(['text' => 'Hello Ana']);

    $bad = $runner->run(new StepDefinition(1, 1, 'fixture.greet', 'g', 'g', 'G', ['storeAs' => 'vars.greeting']), $context);
    expect($bad->status)->toBe('failed')->and($bad->error['message'])->toContain('invalid config');
});

it('passes the usage a module node reports to the run budget and stores only its data', function (): void {
    $context = new WorkflowContext(new WorkflowProject());
    $step = new StepDefinition(1, 1, 'fixture.spend', 's', 's', 'S', ['storeAs' => 'vars.spent']);
    $result = app(StepRunnerRegistry::class)->get('fixture.spend')->run($step, $context);

    expect($result->status)->toBe('success')
        ->and($result->usage())->toMatchArray(['tokens_in' => 10, 'tokens_out' => 5, 'cost_usd' => 0.0123])
        ->and($result->resultData)->toBe(['answer' => 42])
        ->and($context->getVar('spent'))->toBe(['answer' => 42]);

    // A plain array still works and reports nothing.
    $plain = app(StepRunnerRegistry::class)->get('fixture.greet')
        ->run(new StepDefinition(1, 1, 'fixture.greet', 'g', 'g', 'G', ['name' => '"Bo"', '_v' => 2]), $context);
    expect($plain->usage())->toBeNull();
});

it('refuses a permissioned module node when no delegating user holds the permission', function (): void {
    $runner = app(StepRunnerRegistry::class)->get('fixture.secret');
    $result = $runner->run(new StepDefinition(1, 1, 'fixture.secret', 's', 's', 'S', []), new WorkflowContext(new WorkflowProject()));

    expect($result->status)->toBe('failed')->and($result->error['message'])->toContain('lacks permission fixture.secret_use');
});

it('upgrades a config per node version, treating a missing _v as 1', function (): void {
    $upgrader = new StepConfigUpgrader();

    $old = $upgrader->upgrade(['who' => 'root.name'], 'fixture.greet');
    expect($old['config'])->toBe(['name' => 'root.name', '_v' => 2])
        ->and(collect($old['_upgrade_log'])->pluck('type'))->toContain('node_version_upgrade');

    $current = $upgrader->upgrade(['name' => 'x', '_v' => 2], 'fixture.greet');
    expect($current['config'])->toBe(['name' => 'x', '_v' => 2]);

    // Core nodes at v1 never get a `_v` added.
    expect($upgrader->upgrade(['message' => 'hi'], 'log_message')['config'])->not->toHaveKey('_v');
});

it('reports every catalog problem of a graph at once and saves nothing', function (): void {
    nodeMigrate();
    Sanctum::actingAs(nodeUser());
    $project = nodeProject();

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", nodeGraph([
        'schema_bad' => ['step_type' => 'set_variable', 'step_config' => ['assignments' => 'nope']],
        'too_new' => ['step_type' => 'log_message', 'step_config' => ['message' => 'x', '_v' => 9]],
        'no_perm' => ['step_type' => 'fixture.secret', 'step_config' => []],
        'missing' => ['step_type' => 'fixture.greet', 'step_config' => ['storeAs' => 'vars.g']],
    ]))->assertStatus(422)
        ->assertJsonPath('data.errors.0.code', 'config_invalid')
        ->assertJsonPath('data.errors.1.code', 'config_version_unsupported')
        ->assertJsonPath('data.errors.2.code', 'permission_denied')
        ->assertJsonPath('data.errors.3.code', 'config_invalid');

    expect(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('lets a user with the node permission save it, and keeps old configs without _v valid', function (): void {
    nodeMigrate();
    Sanctum::actingAs(nodeUser(['fixture.secret_use']));
    $project = nodeProject();

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", nodeGraph([
        'secret' => ['step_type' => 'fixture.secret', 'step_config' => []],
        'old' => ['step_type' => 'retrieve', 'step_config' => ['model' => \Cms\Core\Models\Tag::class, 'where' => ['slug' => 'root.slug'], 'first' => 'true', 'limit' => '5']],
        'ai' => ['step_type' => 'ai_reasoning', 'step_config' => []],
    ]))->assertOk();

    expect(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(3);
});

it('refuses an AI-fed write without an approval and accepts it with one', function (): void {
    nodeMigrate();
    Sanctum::actingAs(nodeUser());
    $project = nodeProject();

    $change = ['step_type' => 'change', 'step_config' => ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'steps.ai.answer']]]];
    $ai = ['step_type' => 'ai_reasoning', 'step_config' => ['goal' => 'name it']];

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", nodeGraph(['ai' => $ai, 'write' => $change], [['ai', 'write']]))
        ->assertStatus(422)
        ->assertJsonPath('data.errors.0.code', 'taint_needs_approval')
        ->assertJsonPath('data.errors.0.step_code', 'write');

    // Taint also flows through a variable copy.
    $copy = ['step_type' => 'set_variable', 'step_config' => ['assignments' => [['key' => 'vars.name', 'valueExpr' => 'steps.ai.answer']]]];
    $write2 = ['step_type' => 'change', 'step_config' => ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'vars.name']]]];
    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", nodeGraph(['ai' => $ai, 'copy' => $copy, 'write' => $write2], [['ai', 'copy'], ['copy', 'write']]))
        ->assertStatus(422)->assertJsonPath('data.errors.0.code', 'taint_needs_approval');

    $this->putJson("/api/v2/admin/workflows/{$project->uuid}", nodeGraph(
        ['ai' => $ai, 'ok' => ['step_type' => 'approval', 'step_config' => ['mode' => 'human']], 'write' => $change],
        [['ai', 'ok'], ['ok', 'write']]
    ))->assertOk();
});

it('blocks an imported workflow whose AI output feeds a write without approval', function (): void {
    nodeMigrate();

    $row = [
        'slug' => 'pkg-' . uniqid(), 'name' => 'Pkg', 'trigger_event' => null, 'target_entity_type' => 'X',
        'is_active' => true, 'tags' => null, 'description' => null,
        'steps' => [
            ['step_code' => 'ai', 'name' => 'AI', 'step_type' => 'ai_reasoning', 'step_config' => ['goal' => 'x'], 'input_schema' => null, 'output_schema' => null, 'position_x' => 0, 'position_y' => 0, 'is_start' => true],
            ['step_code' => 'w', 'name' => 'W', 'step_type' => 'change', 'step_config' => ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'steps.ai.answer']]], 'input_schema' => null, 'output_schema' => null, 'position_x' => 0, 'position_y' => 0, 'is_start' => false],
            ['step_code' => 'v', 'name' => 'V', 'step_type' => 'log_message', 'step_config' => ['message' => 'x', '_v' => 7], 'input_schema' => null, 'output_schema' => null, 'position_x' => 0, 'position_y' => 0, 'is_start' => false],
        ],
        'connections' => [['from' => 'ai', 'to' => 'w', 'condition' => null], ['from' => 'w', 'to' => 'v', 'condition' => null]],
    ];

    $item = (new WorkflowsSection())->diff([$row], new DiffContext())->item($row['slug']);

    expect($item['action'])->toBe('blocked')
        ->and($item['reason'])->toContain('v: config version 7 is newer')
        ->and($item['reason'])->toContain('w: reads untrusted data from ai');
});

it('serves the node catalog to workflow viewers, filtered by node permission', function (): void {
    Sanctum::actingAs(nodeUser());

    $response = $this->getJson('/api/v2/admin/workflow/node-catalog')->assertOk();
    $types = collect($response->json('data.nodes'))->pluck('type');

    expect($types)->toContain('retrieve', 'change', 'ai_reasoning', 'fixture.greet')
        ->not->toContain('fixture.secret', 'condition', 'blueprint_callable')
        ->and(collect($response->json('data.groups'))->pluck('key'))->toContain('flow', 'cms', 'ai', 'module')
        ->and($response->json('data.approval_types'))->toBe(['approval'])
        ->and($response->json('data.trigger_subject_events'))->toBe([]);

    // Events that name the trigger's own person (e-mail policy B) are listed; an event without a subject is not.
    config(['core.workflow_security.trigger_events' => [
        'App\\Events\\ContactMessageReceived' => ['schema' => [], 'subject' => ['kind' => 'contact_message', 'field' => 'message_uuid']],
        'App\\Events\\SomethingElse' => ['schema' => []],
    ]]);
    expect($this->getJson('/api/v2/admin/workflow/node-catalog')->json('data.trigger_subject_events'))->toBe(['App\\Events\\ContactMessageReceived']);

    $greet = collect($response->json('data.nodes'))->firstWhere('type', 'fixture.greet');
    expect($greet)->toMatchArray([
        'version' => 2, 'group' => 'module', 'module' => 'fixture', 'safety' => ['internal_write'],
        'approval_default' => 'on', 'taint_output' => false, 'outputs' => ['main'],
        'default_config' => ['name' => 'root.name', 'storeAs' => 'vars.greeting'],
    ])->and($greet['config_schema']['required'])->toBe(['name'])
        ->and($greet['result'])->toBe([['key' => 'text', 'type' => 'string', 'label_key' => 'text.workflow.result.text', 'label' => $greet['result'][0]['label']]])
        ->and($greet['result'][0]['label'])->toBeString()->not->toBe('')
        ->and($greet['result_type'])->toBe('object')
        ->and($greet['dynamic'])->toBeNull();

    $nodes = collect($response->json('data.nodes'))->keyBy('type');
    expect(collect($nodes['http_request']['result'])->pluck('key'))->toContain('status', 'body')
        ->and($nodes['ai_extract']['dynamic'])->toBe('schema')
        ->and($nodes['decision']['result'])->toBeNull();

    Sanctum::actingAs(nodeUser(['fixture.secret_use']));
    expect(collect($this->getJson('/api/v2/admin/workflow/node-catalog')->json('data.nodes'))->pluck('type'))->toContain('fixture.secret');
});

it('refuses the node catalog without workflow_project.viewAny', function (): void {
    $user = nodeUser();
    $user->keys = [];
    Sanctum::actingAs($user);

    $this->getJson('/api/v2/admin/workflow/node-catalog')->assertStatus(403);
});
