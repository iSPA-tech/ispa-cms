<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Mockery;
use App\Models\User;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Jobs\RunAgentScheduleJob;
use Cms\Core\Services\Agentic\AgentScheduleService;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgenticAdhocDispatchService;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Tests\Fixtures\Agents\SampleToolService;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\Runners\AgentActionRunner;

/**
 * workflow instances and agent runs record the delegating user, tools
 * receive that user as `actor`, and nothing inside a workflow can change it.
 */
class AgentRunDelegationTest extends TestCase
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
        $this->grantProbe($this->profile);

        $authorization = Mockery::mock(AgenticAuthorization::class);
        $authorization->shouldReceive('canOperate')->andReturn(true);
        app()->instance(AgenticAuthorization::class, $authorization);
    }

    public function test_launch_records_launching_user_and_tool_receives_actor(): void
    {
        $user = $this->makeUser('launcher');
        $target = DelegationDummyTarget::query()->create(['name' => 'Target']);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v2/admin/agentic/runs/launch', [
            'tool_name' => 'demo.actor_probe',
            'target_type' => DelegationDummyTarget::class,
            'target_id' => $target->id,
            'goal' => 'Probe actor',
        ])->assertOk();

        $run = AgentRun::query()->sole();
        $this->assertSame($user->id, $run->delegated_by_user_id);
        $this->assertSame($user->id, WorkflowInstance::query()->sole()->delegated_by_user_id);
        $this->assertSame(['actor_id' => $user->id], $run->toolCalls()->sole()->output_payload);
    }

    public function test_tool_input_actor_does_not_override_delegating_user(): void
    {
        $user = $this->makeUser('owner');

        $result = $this->dispatch(['actor' => 1], $user);
        $this->assertSame(['actor_id' => $user->id], $result['result']->resultData['output']);

        $result = $this->dispatch(['actor' => 1], null);
        $this->assertSame(['actor_id' => null], $result['result']->resultData['output']);
        $this->assertNull($result['run']->delegated_by_user_id);
    }

    public function test_rerun_acts_for_the_user_who_reruns(): void
    {
        $owner = $this->makeUser('owner');
        $rerunner = $this->makeUser('rerunner');
        $source = $this->dispatch([], $owner)['run'];
        $this->assertSame('completed', $source->status);

        $this->actingAs($rerunner, 'sanctum');
        $this->postJson("/api/v2/admin/agentic/runs/{$source->id}/rerun")->assertOk();

        $rerun = AgentRun::query()->where('source_run_id', $source->id)->sole();
        $this->assertSame($rerunner->id, $rerun->delegated_by_user_id);
        $this->assertSame($rerunner->id, $rerun->workflowInstance->delegated_by_user_id);
        $this->assertSame(['actor_id' => $rerunner->id], $rerun->toolCalls()->sole()->output_payload);
    }

    public function test_schedule_acts_for_its_creator(): void
    {
        $creator = $this->makeUser('creator');
        $run = $this->executeSchedule($creator->id);

        $this->assertSame($creator->id, $run->delegated_by_user_id);
        $this->assertSame($creator->id, $run->workflowInstance->delegated_by_user_id);
        $this->assertSame(['actor_id' => $creator->id], $run->toolCalls()->sole()->output_payload);
    }

    public function test_schedule_without_creator_acts_for_nobody(): void
    {
        $run = $this->executeSchedule(null);

        $this->assertNull($run->delegated_by_user_id);
        $this->assertNull($run->workflowInstance->delegated_by_user_id);
        $this->assertSame(['actor_id' => null], $run->toolCalls()->sole()->output_payload);
    }

    public function test_store_as_may_not_write_runtime_vars(): void
    {
        $user = $this->makeUser('owner');
        $instance = WorkflowInstance::query()->create(['status' => 'running', 'delegated_by_user_id' => $user->id]);

        foreach (['vars.__runtime.instance_id', 'vars.__runtime', 'vars. __runtime.x'] as $storeAs) {
            $context = $this->contextFor($instance);
            $result = app(AgentActionRunner::class)->run($this->probeStep(['storeAs' => $storeAs]), $context);

            $this->assertSame('failed', $result->status, $storeAs);
            $this->assertSame('ai_action: storeAs may not write __runtime', $result->error['message'] ?? null);
            $this->assertSame($instance->id, $context->getVarPath('__runtime.instance_id'));
        }

        $this->assertSame(0, AgentRun::query()->count());
    }

    public function test_deleting_the_user_nulls_delegation_and_later_steps_get_no_actor(): void
    {
        $user = $this->makeUser('leaver');
        $result = $this->dispatch([], $user);
        $instance = $result['instance'];
        $this->assertSame(['actor_id' => $user->id], $result['result']->resultData['output']);

        $user->forceDelete();

        $this->assertNull($instance->fresh()->delegated_by_user_id);
        $this->assertNull($result['run']->fresh()->delegated_by_user_id);

        $next = app(AgentActionRunner::class)->run($this->probeStep(), $this->contextFor($instance));

        $this->assertSame('success', $next->status);
        $this->assertSame(['actor_id' => null], $next->resultData['output']);
        $this->assertNull(AgentRun::query()->find($next->resultData['agent_run_id'])->delegated_by_user_id);
    }

    public function test_scope_snapshot_is_taken_for_a_registered_scoped_resource(): void
    {
        $user = $this->makeUser('scoped');
        $summary = ['level' => 'own', 'own' => true, 'dimensions' => []];

        $resources = new ScopedResourceRegistry();
        $resources->register(ScopedResource::make('fixture.delegation', DelegationDummyTarget::class, 'fixture.delegation.view'));
        app()->instance(ScopedResourceRegistry::class, $resources);

        $scopeSummary = Mockery::mock(ScopeSummary::class);
        $scopeSummary->shouldReceive('for')
            ->once()
            ->withArgs(fn (User $actor, string $key) => $actor->id === $user->id && $key === 'fixture.delegation.view')
            ->andReturn($summary);
        app()->instance(ScopeSummary::class, $scopeSummary);

        $scoped = DelegationDummyTarget::query()->create(['name' => 'Scoped']);
        $run = $this->dispatch([], $user, DelegationDummyTarget::class, $scoped->id)['run'];
        $this->assertSame($summary, $run->scope_snapshot);

        $unscoped = DelegationOtherTarget::query()->create(['name' => 'Unscoped']);
        $run = $this->dispatch([], $user, DelegationOtherTarget::class, $unscoped->id)['run'];
        $this->assertNull($run->scope_snapshot);

        $run = $this->dispatch([], null, DelegationDummyTarget::class, $scoped->id)['run'];
        $this->assertNull($run->scope_snapshot);
    }

    /**
     * @param array<string, mixed> $toolInput
     * @return array<string, mixed>
     */
    protected function dispatch(array $toolInput, ?User $actor, ?string $targetType = null, ?int $targetId = null): array
    {
        return app(AgenticAdhocDispatchService::class)->dispatchDeterministicTool(
            profile: $this->profile,
            toolName: 'demo.actor_probe',
            toolInput: $toolInput,
            moduleScope: 'demo',
            targetType: $targetType,
            targetId: $targetId,
            actor: $actor,
        );
    }

    protected function executeSchedule(?int $creatorId): AgentRun
    {
        $target = DelegationDummyTarget::query()->create(['name' => 'Scheduled']);
        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $this->profile->id,
            'tool_definition_id' => AgentToolDefinition::query()->where('tool_name', 'demo.actor_probe')->value('id'),
            'created_by_user_id' => $creatorId,
            'name' => 'Probe schedule',
            'status' => 'running',
            'module_scope' => 'demo',
            'target_type' => DelegationDummyTarget::class,
            'target_id' => (string) $target->id,
            'cadence_minutes' => 15,
            'tool_input' => ['actor' => 1],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunAgentScheduleJob($schedule->id, 'active'))->handle(app(AgentScheduleService::class));

        return AgentRun::query()->where('trigger_reference_id', (string) $schedule->id)->sole();
    }

    protected function contextFor(WorkflowInstance $instance): WorkflowContext
    {
        $context = new WorkflowContext(new DelegationDummyTarget(), ['event' => 'test']);
        $context->setRuntime('instance_id', $instance->id);

        return $context;
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

    protected function grantProbe(AgentProfile $profile): void
    {
        app(AgentToolRegistry::class)->syncDiscoveredService(SampleToolService::class, 'demo', 'testing');

        $definition = AgentToolDefinition::query()->where('tool_name', 'demo.actor_probe')->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => ['enabled' => true, 'safety_override' => null],
        ]);
    }

    protected function migrateTables(): void
    {
        foreach (['delegation_dummy_targets', 'delegation_other_targets'] as $table) {
            Schema::create($table, function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Deleting a user revokes the user's API tokens (TokenRevoker); the table is part of the app's own migrations, so
        // production always has it and the fixture only needs the columns the revoker reads.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name')->nullable();
            $table->string('token', 64)->nullable();
            $table->text('abilities')->nullable();
            $table->timestamps();
        });

        // The revoker also writes an audit entry.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action')->nullable();
            $table->text('changed')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('agent')->nullable();
            $table->text('url')->nullable();
            $table->text('options')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
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

class DelegationDummyTarget extends Model
{
    protected $table = 'delegation_dummy_targets';
    protected $guarded = [];
}

class DelegationOtherTarget extends Model
{
    protected $table = 'delegation_other_targets';
    protected $guarded = [];
}
