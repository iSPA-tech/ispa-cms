<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Mockery;
use ReflectionMethod;
use App\Models\User;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgenticAdhocDispatchService;
use Cms\Core\Tests\Fixtures\Agents\SampleToolService;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Exceptions\InvalidWorkflowContextException;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Cms\Core\Workflows\Runtime\Runners\AgentActionRunner;

/**
 * only the workflow executor writes `vars.__runtime`; workflow steps
 * cannot point a run at another instance or drop the delegating user.
 */
class WorkflowRuntimeVarsGuardTest extends TestCase
{
    protected AgentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();

        config()->set('core.agentic.discovery.default_environment', 'testing');
        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\']
        ));

        // Occupy user id 1 (always a webmaster) so test users are plain accounts.
        User::query()->create(['name' => 'Webmaster', 'email' => 'webmaster@example.test', 'password' => 'secret']);

        $this->profile = AgentProfile::query()->create([
            'name' => 'Actor Probe',
            'slug' => 'actor-probe',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);

        app(AgentToolRegistry::class)->syncDiscoveredService(SampleToolService::class, 'demo', 'testing');
        $definition = AgentToolDefinition::query()->where('tool_name', 'demo.actor_probe')->firstOrFail();
        $this->profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => ['enabled' => true, 'safety_override' => null],
        ]);

        $authorization = Mockery::mock(AgenticAuthorization::class);
        $authorization->shouldReceive('canOperate')->andReturn(true);
        app()->instance(AgenticAuthorization::class, $authorization);

        app(StepRunnerRegistry::class)->register('runtime_echo', new RuntimeEchoRunner());
    }

    public function test_steps_may_not_write_runtime_vars_and_later_runs_keep_the_delegating_user(): void
    {
        $owner = $this->makeUser('owner');
        $victim = $this->makeUser('victim');
        $instance = WorkflowInstance::query()->create(['status' => 'running', 'delegated_by_user_id' => $owner->id]);
        $other = WorkflowInstance::query()->create(['status' => 'running', 'delegated_by_user_id' => $victim->id]);
        RuntimeGuardHijackService::$instanceId = $other->id;

        $attacks = [
            'aggregate_list' => ['aggregate_list', [
                'collectionExpr' => 'vars.items',
                'operation' => 'max',
                'field' => 'id',
                'storeAs' => 'vars.__runtime.instance_id',
            ]],
            'blueprint_callable' => ['blueprint_callable', [
                'callableType' => 'service',
                'class' => RuntimeGuardHijackService::class,
                'method' => 'instanceId',
                'storeAs' => 'vars.__runtime.instance_id',
            ]],
            'clear __runtime' => ['clear_variable', ['targets' => ['vars.__runtime']]],
            'clear __runtime.instance_id' => ['clear_variable', ['targets' => ['vars.__runtime.instance_id']]],
            'ai_action' => ['ai_action', [
                'agentProfileSlug' => 'actor-probe',
                'toolName' => 'demo.actor_probe',
                'moduleScope' => 'demo',
                'toolInput' => [],
                'storeAs' => 'vars.__runtime',
            ]],
        ];

        foreach ($attacks as $label => [$type, $config]) {
            $context = $this->contextFor($instance);
            $context->setVarPath('items', [['id' => $other->id]]);
            $before = $context->getVarPath('__runtime');

            $result = $this->runStep($type, $config, $context);

            $this->assertSame('failed', $result->status, $label);
            $this->assertSame($before, $context->getVarPath('__runtime'), $label);

            $next = app(AgentActionRunner::class)->run($this->probeStep(), $context);
            $this->assertSame('success', $next->status, $label);
            $this->assertSame(['actor_id' => $owner->id], $next->resultData['output'], $label);
            $run = AgentRun::query()->find($next->resultData['agent_run_id']);
            $this->assertSame($owner->id, $run->delegated_by_user_id, $label);
            $this->assertSame($instance->id, $run->workflow_instance_id, $label);
        }

        // The ai_action pre-check keeps its message and starts no run.
        $context = $this->contextFor($instance);
        $result = app(AgentActionRunner::class)->run($this->probeStep(['storeAs' => 'vars.__runtime.instance_id']), $context);
        $this->assertSame('ai_action: storeAs may not write __runtime', $result->error['message'] ?? null);
        $this->assertSame(count($attacks), AgentRun::query()->count());
    }

    public function test_blueprint_callable_checks_store_as_before_calling(): void
    {
        $instance = WorkflowInstance::query()->create(['status' => 'running']);
        StoreAsSpyService::$calls = 0;

        $cases = [
            'vars.__runtime.x' => 'blueprint_callable: storeAs may not write __runtime',
            'vars.__runtime' => 'blueprint_callable: storeAs may not write __runtime',
            'other.x' => 'blueprint_callable: storeAs must start with vars.',
        ];

        foreach ($cases as $storeAs => $message) {
            $context = $this->contextFor($instance);
            $result = $this->runStep('blueprint_callable', [
                'callableType' => 'service',
                'class' => StoreAsSpyService::class,
                'method' => 'run',
                'storeAs' => $storeAs,
            ], $context);

            $this->assertSame('failed', $result->status, $storeAs);
            $this->assertSame($message, $result->error['message'] ?? null, $storeAs);
            $this->assertSame(0, StoreAsSpyService::$calls, $storeAs);
        }

        $context = $this->contextFor($instance);
        $result = $this->runStep('blueprint_callable', [
            'callableType' => 'service',
            'class' => StoreAsSpyService::class,
            'method' => 'run',
            'storeAs' => 'vars.result',
        ], $context);

        $this->assertSame('success', $result->status);
        $this->assertSame(1, StoreAsSpyService::$calls);
        $this->assertSame('ok', $context->getVarPath('result'));
    }

    public function test_context_refuses_runtime_writes_outside_set_runtime(): void
    {
        $context = new WorkflowContext(new GuardDummyTarget(), [], ['__runtime' => ['instance_id' => 5], 'a' => 1]);

        foreach ([
            fn () => $context->setVarPath('__runtime', []),
            fn () => $context->setVarPath('__runtime.instance_id', 9),
            fn () => $context->setVar('__runtime', []),
            fn () => $context->setVars(['a' => 2]),
            fn () => $context->setVars(['__runtime' => ['instance_id' => 9]]),
        ] as $index => $write) {
            try {
                $write();
                $this->fail("write {$index} was not refused");
            } catch (InvalidWorkflowContextException) {
            }
        }

        $this->assertSame(['__runtime' => ['instance_id' => 5], 'a' => 1], $context->getVars());

        // Replacing vars while keeping __runtime as is stays allowed (clear_variable relies on it).
        $context->setVars(['__runtime' => ['instance_id' => 5]]);
        $context->setRuntime('queued', true);
        $this->assertSame(['__runtime' => ['instance_id' => 5, 'queued' => true]], $context->getVars());

        // A stored snapshot keeps its __runtime.
        $restored = WorkflowContext::fromArray(['vars' => ['__runtime' => ['agent_schedule_id' => 3]]], new GuardDummyTarget());
        $this->assertSame(3, $restored->getVarPath('__runtime.agent_schedule_id'));
    }

    public function test_executor_sets_runtime_vars_and_keeps_stored_ones(): void
    {
        $owner = $this->makeUser('owner');
        $project = WorkflowProject::query()->create(['name' => 'Runtime echo']);
        $echo = WorkflowStep::query()->create([
            'project_id' => $project->id,
            'step_type' => 'runtime_echo',
            'step_code' => 'echo',
            'position_x' => 1,
        ]);
        $probe = WorkflowStep::query()->create([
            'project_id' => $project->id,
            'step_type' => 'ai_action',
            'step_code' => 'probe',
            'step_config' => $this->probeStep()->stepConfig,
            'position_x' => 2,
        ]);
        WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $echo->id, 'to_step_id' => $probe->id]);

        $instance = WorkflowInstance::query()->create([
            'project_id' => $project->id,
            'status' => 'pending',
            'delegated_by_user_id' => $owner->id,
            'context_snapshot' => ['vars' => ['__runtime' => ['agent_schedule_id' => 7]]],
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id, false);

        $this->assertSame('completed', $instance->fresh()->status);
        $echoStep = WorkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $echo->id)->sole();
        $this->assertEquals([
            'agent_schedule_id' => 7,
            'instance_id' => $instance->id,
            'queued' => false,
            'current_instance_step_id' => $echoStep->id,
        ], $echoStep->result_data['result']['payload']['runtime']);

        $probeStep = WorkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $probe->id)->sole();
        $run = AgentRun::query()->sole();
        $this->assertSame($owner->id, $run->delegated_by_user_id);
        $this->assertSame($instance->id, $run->workflow_instance_id);
        $this->assertSame($probeStep->id, $run->parent_workflow_step_id);
    }

    public function test_executor_fails_a_step_that_writes_runtime(): void
    {
        $owner = $this->makeUser('owner');
        $project = WorkflowProject::query()->create(['name' => 'Hijack']);
        $clear = WorkflowStep::query()->create([
            'project_id' => $project->id,
            'step_type' => 'clear_variable',
            'step_code' => 'clear',
            'step_config' => ['targets' => ['vars.__runtime']],
            'position_x' => 1,
        ]);
        $probe = WorkflowStep::query()->create([
            'project_id' => $project->id,
            'step_type' => 'ai_action',
            'step_code' => 'probe',
            'step_config' => $this->probeStep()->stepConfig,
            'position_x' => 2,
        ]);
        WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $clear->id, 'to_step_id' => $probe->id]);
        $instance = WorkflowInstance::query()->create([
            'project_id' => $project->id,
            'status' => 'pending',
            'delegated_by_user_id' => $owner->id,
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id, false);

        $this->assertSame('failed', $instance->fresh()->status);
        $this->assertSame('failed', WorkflowInstanceStep::query()->where('step_id', $clear->id)->sole()->status);
        $this->assertSame(0, AgentRun::query()->count());
    }

    public function test_adhoc_dispatch_sets_runtime_vars_over_a_supplied_snapshot(): void
    {
        $owner = $this->makeUser('owner');
        $victim = $this->makeUser('victim');
        $other = WorkflowInstance::query()->create(['status' => 'running', 'delegated_by_user_id' => $victim->id]);

        $result = app(AgenticAdhocDispatchService::class)->dispatchDeterministicTool(
            profile: $this->profile,
            toolName: 'demo.actor_probe',
            toolInput: [],
            moduleScope: 'demo',
            contextSnapshot: ['vars' => ['__runtime' => ['instance_id' => $other->id, 'queued' => true]]],
            actor: $owner,
        );

        $run = $result['run'];
        $this->assertSame($owner->id, $run->delegated_by_user_id);
        $this->assertSame($result['instance']->id, $run->workflow_instance_id);
        $this->assertNotNull($run->parent_workflow_step_id);
        $this->assertSame(
            WorkflowInstanceStep::query()->where('instance_id', $run->workflow_instance_id)->value('id'),
            $run->parent_workflow_step_id,
        );
        $this->assertFalse(data_get($run->context_snapshot, 'vars.__runtime.queued'));
        $this->assertSame(['actor_id' => $owner->id], $result['result']->resultData['output']);
    }

    public function test_expression_preview_ignores_request_runtime_vars(): void
    {
        $this->actingAs($this->makeUser('previewer'), 'sanctum');

        $this->postJson('/api/v2/admin/workflow/template/preview', [
            'template' => 'id={{ vars.__runtime.instance_id }};name={{ vars.name }}',
            'sampleVars' => ['__runtime' => ['instance_id' => 4242], 'name' => 'Bob'],
        ])->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.rendered', 'id=;name=Bob');
    }

    /**
     * Run a registered step runner the way the executor does (exceptions become a failed step).
     *
     * @param array<string, mixed> $config
     */
    protected function runStep(string $type, array $config, WorkflowContext $context): StepRunResult
    {
        $definition = new StepDefinition(null, null, $type, $type, $type, $type, $config, 1);
        $runStep = new ReflectionMethod(WorkflowGraphExecutor::class, 'runStep');

        return $runStep->invoke(app(WorkflowGraphExecutor::class), app(StepRunnerRegistry::class)->get($type), $definition, $context);
    }

    protected function contextFor(WorkflowInstance $instance): WorkflowContext
    {
        return WorkflowContext::fromArray(
            ['trigger' => ['event' => 'test'], 'vars' => ['__runtime' => ['instance_id' => $instance->id, 'queued' => false]]],
            new GuardDummyTarget(),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function probeStep(array $extra = []): StepDefinition
    {
        return new StepDefinition(
            id: null,
            projectId: null,
            stepType: 'ai_action',
            stepCode: 'probe',
            stepKey: 'probe',
            stepName: 'Probe',
            stepConfig: array_merge([
                'agentProfileSlug' => 'actor-probe',
                'toolName' => 'demo.actor_probe',
                'moduleScope' => 'demo',
                'toolInput' => [],
            ], $extra),
            order: 1,
        );
    }

    protected function makeUser(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => "{$name}@example.test", 'password' => 'secret']);
    }

    protected function migrateTables(): void
    {
        Schema::create('guard_dummy_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('context_snapshot')->nullable();
            $table->unsignedBigInteger('resume_step_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('trigger_event')->nullable();
            $table->string('target_entity_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('tags')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('step_code')->nullable();
            $table->string('name')->nullable();
            $table->string('step_type')->nullable();
            $table->json('step_config')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->boolean('is_start')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('from_step_id')->nullable();
            $table->unsignedBigInteger('to_step_id')->nullable();
            $table->json('condition')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->unsignedBigInteger('step_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('input_data')->nullable();
            $table->json('result_data')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([
            '2026_03_10_160000_create_agentic_runtime_tables.php',
            '2026_03_10_190000_add_agentic_run_controls_and_schedules.php',
            '2026_03_10_213000_add_stop_request_fields_to_agent_runs.php',
            '2026_03_10_221000_add_workflow_project_id_to_agent_schedules.php',
            '2026_11_05_120100_add_delegated_by_to_agentic_runs.php',
        ] as $file) {
            (require base_path("cms/Core/database/migrations/{$file}"))->up();
        }
    }
}

/**
 * Root model for the guard tests.
 */
class GuardDummyTarget extends Model
{
    protected $table = 'guard_dummy_targets';
    protected $guarded = [];
}

/**
 * Service a workflow could call to point a run at another instance.
 */
class RuntimeGuardHijackService
{
    public static ?int $instanceId = null;

    /**
     * Return the instance id the workflow tries to borrow.
     */
    public function instanceId(): ?int
    {
        return self::$instanceId;
    }
}

/**
 * Service that counts how often a workflow step actually called it.
 */
class StoreAsSpyService
{
    public static int $calls = 0;

    /**
     * Record the call and return a fixed value.
     */
    public function run(): string
    {
        self::$calls++;

        return 'ok';
    }
}

/**
 * Step runner that reports the runtime vars it sees.
 */
class RuntimeEchoRunner implements StepRunner
{
    /**
     * {@inheritdoc}
     */
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success(['runtime' => $context->getVarPath('__runtime')]);
    }
}
