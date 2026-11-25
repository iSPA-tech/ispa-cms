<?php

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Tests\Support\WorkflowTestVersions;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Review finding 29: writes a run causes outside its own queued job (an inline execution, an approval applied over
 * HTTP) carry the run's trigger chain, so the model-event loop guard sees them.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    if (DB::getDriverName() === 'sqlite') {
        // Same SQLite-only rebuild as WorkflowHumanNodesTest: drop the stale step_id foreign key.
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'workflow_instance_steps')->value('sql');
        $fixed = preg_replace('/,\s*foreign key\("step_id"\) references "workflow_instances"\("id"\)[^,)]*/i', '', $sql);
        if ($fixed !== null && $fixed !== $sql) {
            DB::statement((string) preg_replace('/^CREATE TABLE "?workflow_instance_steps"?/i', 'CREATE TABLE "workflow_instance_steps_fixed"', $fixed));
            DB::statement('INSERT INTO workflow_instance_steps_fixed SELECT * FROM workflow_instance_steps');
            DB::statement('DROP TABLE workflow_instance_steps');
            DB::statement('ALTER TABLE workflow_instance_steps_fixed RENAME TO workflow_instance_steps');
        }
    }
    TriggerChain::reset();
});

function chainUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'chain', 'username' => 'chain-' . Str::lower(Str::random(6)),
        'email' => 'chain-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ])->fresh();
}

/**
 * @param array<string, array{0: string, 1: array<string, mixed>}> $steps
 * @param list<array{0: string, 1: string, 2: ?string}> $edges
 */
function chainInstance(array $steps, array $edges, User $user): \Cms\Core\Models\Workflows\WorkflowInstance
{
    $project = WorkflowProject::query()->create(['name' => 'Chain', 'slug' => 'chain-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $made = [];
    $x = 1;
    foreach ($steps as $code => [$type, $config]) {
        $made[$code] = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => $type, 'step_code' => $code, 'step_config' => $config, 'position_x' => $x, 'is_start' => $x === 1]);
        $x++;
    }
    foreach ($edges as [$from, $to, $handle]) {
        WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle]]);
    }

    return WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => $user->id, 'status' => 'pending', 'delegated_by_user_id' => $user->id]);
}

it('carries the run chain while a run executes outside a queued job', function (): void {
    $seen = [];
    app(StepRunnerRegistry::class)->register('chain_probe', new class($seen) implements \Cms\Core\Workflows\Runtime\Contracts\StepRunner {
        public function __construct(private array &$seen)
        {
        }

        public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
        {
            $this->seen[] = TriggerChain::currentInstanceId();

            return StepRunResult::success();
        }
    });
    $instance = chainInstance(['probe' => ['chain_probe', []]], [], chainUser());

    app(WorkflowGraphExecutor::class)->execute($instance->id);

    expect($seen)->toBe([$instance->id])
        ->and(TriggerChain::currentInstanceId())->toBeNull();
});

it('carries the run chain while an approval of the run is applied', function (): void {
    $user = chainUser();
    $instance = chainInstance([
        'ask' => ['approval', ['title' => 'Check', 'payload' => "{'headline': 'Old'}", 'notify' => false]],
        'ok' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]],
    ], [['ask', 'ok', 'approved']], $user);
    app(WorkflowGraphExecutor::class)->execute($instance->id);
    expect($instance->fresh()->status)->toBe('awaiting_approval');

    $seen = [];
    AgentApproval::updated(function (AgentApproval $approval) use (&$seen): void {
        if ($approval->apply_outcome === 'applied') {
            $seen[] = TriggerChain::currentInstanceId();
        }
    });

    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    TriggerChain::reset();
    app(AgentApprovalService::class)->approveAndApply($approval, $user, 'fine');

    expect($seen)->not->toBeEmpty()
        ->and($seen[0])->toBe($instance->id);
});
