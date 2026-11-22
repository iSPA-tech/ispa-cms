<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentToolCall;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\WorkflowContextToolService;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AgenticRunControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Messages are asserted in English; the app default locale is vi.
        app()->setLocale('en');
        $this->withoutMiddleware();
        $this->migrateTables();
        $this->actingAs(User::query()->create([
            'name' => 'Webmaster',
            'email' => 'webmaster@example.test',
            'password' => 'secret',
        ]), 'sanctum');
    }

    public function test_index_lists_runs_with_latest_runtime_state(): void
    {
        $graph = $this->seedRunGraph();

        $response = $this->getJson('/api/v2/admin/agentic/runs?status=awaiting_apply');

        $response->assertOk()
            ->assertJsonPath('data.data.0.id', $graph['run']->id)
            ->assertJsonPath('data.data.0.status', 'awaiting_apply')
            ->assertJsonPath('data.data.0.actions.can_cancel', true)
            ->assertJsonPath('data.data.0.actions.can_rerun', false)
            ->assertJsonPath('data.data.0.profile.slug', 'content-proposer')
            ->assertJsonPath('data.data.0.workflow.id', $graph['instance']->id)
            ->assertJsonPath('data.data.0.workflow.status', 'awaiting_apply')
            ->assertJsonPath('data.data.0.latest_step.status', 'awaiting_apply')
            ->assertJsonPath('data.data.0.latest_approval.status', 'approved')
            ->assertJsonPath('data.data.0.latest_artifact.artifact_type', 'content_post_description_patch')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_show_returns_detailed_run_graph(): void
    {
        $graph = $this->seedRunGraph();

        $response = $this->getJson("/api/v2/admin/agentic/runs/{$graph['run']->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $graph['run']->id)
            ->assertJsonPath('data.actions.can_cancel', true)
            ->assertJsonPath('data.steps.0.id', $graph['runStep']->id)
            ->assertJsonPath('data.approvals.0.id', $graph['approval']->id)
            ->assertJsonPath('data.artifacts.0.id', $graph['artifact']->id)
            ->assertJsonPath('data.tool_calls.0.id', $graph['toolCall']->id);
    }

    public function test_cancel_endpoint_marks_run_and_workflow_as_cancelled(): void
    {
        $graph = $this->seedRunGraph();

        $response = $this->postJson("/api/v2/admin/agentic/runs/{$graph['run']->id}/cancel", [
            'note' => 'Cancelled by operator.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.terminal_reason', 'operator_cancelled')
            ->assertJsonPath('data.workflow.status', 'cancelled');

        $this->assertSame('cancelled', $graph['run']->fresh()->status);
        $this->assertSame('operator_cancelled', $graph['run']->fresh()->terminal_reason);
        $this->assertSame('cancelled', $graph['instance']->fresh()->status);
        $this->assertSame('cancelled', $graph['approval']->fresh()->status);
        $this->assertSame('cancelled', $graph['approval']->fresh()->apply_outcome);
    }

    public function test_rerun_endpoint_creates_follow_up_run_with_source_lineage(): void
    {
        $target = AgenticRunControllerDummyTarget::query()->create(['name' => 'Target']);
        $profile = AgentProfile::query()->create([
            'name' => 'Context Inspector',
            'slug' => 'context-inspector',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);
        $this->grantToolToProfile($profile, WorkflowContextToolService::class, 'core.inspect_workflow_context', 'core');

        $instance = WorkflowInstance::query()->create([
            'status' => 'completed',
            'target_type' => AgenticRunControllerDummyTarget::class,
            'target_id' => $target->id,
            'context_snapshot' => [
                'vars' => [
                    'rerun_marker' => 'yes',
                ],
            ],
        ]);

        $workflowStep = WorkflowInstanceStep::query()->create([
            'instance_id' => $instance->id,
            'status' => 'success',
        ]);

        $sourceRun = AgentRun::query()->create([
            'agent_profile_id' => $profile->id,
            'workflow_instance_id' => $instance->id,
            'parent_workflow_step_id' => $workflowStep->id,
            'environment' => 'testing',
            'module_scope' => 'core',
            'target_type' => AgenticRunControllerDummyTarget::class,
            'target_id' => (string) $target->id,
            'status' => 'completed',
            'input_goal' => 'Inspect workflow context',
            'context_snapshot' => [
                'vars' => [
                    'rerun_marker' => 'yes',
                ],
            ],
            'budget_usage' => ['steps' => 1, 'tool_calls' => 1, 'tokens' => 0],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        AgentRunStep::query()->create([
            'run_id' => $sourceRun->id,
            'step_index' => 1,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => 'core.inspect_workflow_context',
            'reasoning_summary' => 'First execution.',
            'observation_summary' => 'Completed.',
            'status' => 'completed',
        ]);

        $sourceStep = AgentRunStep::query()->latest('id')->first();

        AgentToolCall::query()->create([
            'run_id' => $sourceRun->id,
            'run_step_id' => $sourceStep?->id,
            'tool_definition_id' => AgentToolDefinition::query()->where('tool_name', 'core.inspect_workflow_context')->value('id'),
            'tool_binding_id' => null,
            'input_payload' => ['requested_var' => '__runtime.instance_id'],
            'status' => 'completed',
            'retry_scope' => 'pipeline',
            'retry_count' => 0,
            'idempotency_key' => 'source-run',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/runs/{$sourceRun->id}/rerun");

        $response->assertOk()
            ->assertJsonPath('data.source_run_id', $sourceRun->id)
            ->assertJsonPath('data.trigger_mode', 'rerun')
            ->assertJsonPath('data.source_run.id', $sourceRun->id);

        $rerun = AgentRun::query()->latest('id')->first();

        $this->assertNotNull($rerun);
        $this->assertNotSame($sourceRun->id, $rerun->id);
        $this->assertSame($sourceRun->id, $rerun->source_run_id);
        $this->assertSame('rerun', $rerun->trigger_mode);
    }

    public function test_cancel_endpoint_rejects_applied_runs(): void
    {
        $graph = $this->seedRunGraph();
        $graph['run']->update([
            'status' => 'applied',
            'post_approval_outcome' => 'applied',
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/runs/{$graph['run']->id}/cancel", [
            'note' => 'Too late.',
        ]);

        $response->assertStatus(400);
        $this->assertSame('applied', $graph['run']->fresh()->status);
    }

    public function test_stop_endpoint_marks_run_as_stop_requested_and_workflow_as_cancelling(): void
    {
        $graph = $this->seedRunningRunGraph();

        $response = $this->postJson("/api/v2/admin/agentic/runs/{$graph['run']->id}/stop", [
            'note' => 'Operator requested a cooperative stop.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.operator_request_state', 'stop_requested')
            ->assertJsonPath('data.workflow.status', 'cancelling')
            ->assertJsonPath('data.actions.can_stop', false);

        $this->assertSame('stop_requested', $graph['run']->fresh()->operator_request_state);
        $this->assertSame('cancelling', $graph['instance']->fresh()->status);
        $this->assertSame('cancelling', $graph['workflowStep']->fresh()->status);
    }

    public function test_rerun_endpoint_rejects_runs_waiting_for_apply(): void
    {
        $graph = $this->seedRunGraph();

        $response = $this->postJson("/api/v2/admin/agentic/runs/{$graph['run']->id}/rerun");

        $response->assertStatus(400);
        $this->assertSame(1, AgentRun::query()->count());
    }

    public function test_launch_endpoint_creates_ad_hoc_run_for_granted_tool(): void
    {
        $target = AgenticRunControllerDummyTarget::query()->create(['name' => 'Launch Target']);

        $profile = AgentProfile::query()->create([
            'name' => 'Reader',
            'slug' => 'reader',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);

        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\']
        ));

        $this->grantToolToProfile(
            $profile,
            \Cms\Core\Services\Agentic\WorkflowContextToolService::class,
            'core.inspect_workflow_context',
            'core',
        );

        $response = $this->postJson('/api/v2/admin/agentic/runs/launch', [
            'tool_name' => 'core.inspect_workflow_context',
            'target_type' => AgenticRunControllerDummyTarget::class,
            'target_id' => $target->id,
            'goal' => 'Test launch',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task launched.');

        // Run was created (status may be completed or failed depending on tool execution context)
        $this->assertSame(1, AgentRun::query()->where('input_goal', 'Test launch')->count());
        $this->assertNotNull($response->json('data.run.id'));
    }

    public function test_launch_endpoint_rejects_tool_without_grant(): void
    {
        AgentProfile::query()->create([
            'name' => 'Empty',
            'slug' => 'empty-profile',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v2/admin/agentic/runs/launch', [
            'tool_name' => 'nonexistent.tool',
            'target_type' => 'App\\Models\\User',
            'target_id' => 1,
        ]);

        $response->assertStatus(400)
            ->assertJsonFragment(['message' => 'No active profile grants tool [nonexistent.tool].']);
    }

    public function test_launch_endpoint_works_without_target_id(): void
    {
        $profile = AgentProfile::query()->create([
            'name' => 'Ops',
            'slug' => 'ops-test',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);

        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\']
        ));

        $this->grantToolToProfile(
            $profile,
            \Cms\Core\Services\Agentic\WorkflowContextToolService::class,
            'core.inspect_workflow_context',
            'core',
        );

        $response = $this->postJson('/api/v2/admin/agentic/runs/launch', [
            'tool_name' => 'core.inspect_workflow_context',
            'target_type' => 'App\\Models\\User',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task launched.');
    }

    public function test_launch_endpoint_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v2/admin/agentic/runs/launch', []);

        $response->assertStatus(400);
    }

    /**
     * @return array{run: AgentRun, runStep: AgentRunStep, artifact: AgentArtifact, approval: AgentApproval, toolCall: AgentToolCall, instance: WorkflowInstance}
     */
    protected function seedRunGraph(): array
    {
        $profile = AgentProfile::query()->create([
            'name' => 'Content Proposer',
            'slug' => 'content-proposer',
            'capability_class' => 'class_2_staged_mutation',
            'status' => 'active',
        ]);

        $instance = WorkflowInstance::query()->create([
            'status' => 'awaiting_apply',
            'resume_step_id' => 99,
            'context_snapshot' => [
                'vars' => [
                    'proposal_bundle' => [
                        'approval_id' => 1,
                    ],
                ],
            ],
        ]);

        $workflowStep = WorkflowInstanceStep::query()->create([
            'instance_id' => $instance->id,
            'status' => 'awaiting_apply',
        ]);

        $run = AgentRun::query()->create([
            'agent_profile_id' => $profile->id,
            'workflow_instance_id' => $instance->id,
            'parent_workflow_step_id' => $workflowStep->id,
            'environment' => 'testing',
            'module_scope' => 'content',
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => '77',
            'status' => 'awaiting_apply',
            'input_goal' => 'Refresh post description',
            'context_snapshot' => ['root' => ['id' => 77]],
            'budget_usage' => ['steps' => 1, 'tool_calls' => 1, 'tokens' => 0],
            'started_at' => now(),
        ]);

        $runStep = AgentRunStep::query()->create([
            'run_id' => $run->id,
            'step_index' => 1,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => 'content.propose_post_description_refresh',
            'reasoning_summary' => 'Created staged proposal.',
            'observation_summary' => 'Awaiting explicit apply.',
            'status' => 'awaiting_apply',
        ]);

        $artifact = AgentArtifact::query()->create([
            'run_id' => $run->id,
            'artifact_type' => 'content_post_description_patch',
            'subject_type' => 'Cms\\Content\\Models\\Post',
            'subject_id' => '77',
            'source_snapshot_ref' => 'content.post:77',
            'original_data_snapshot' => ['description' => 'Original'],
            'payload' => ['summary' => 'Refresh description.'],
            'storage_driver' => 'database',
            'version' => 1,
            'render_hint' => 'text_diff',
            'apply_contract_version' => 'v1',
        ]);

        $approval = AgentApproval::query()->create([
            'run_id' => $run->id,
            'artifact_id' => $artifact->id,
            'status' => 'approved',
            'apply_outcome' => 'awaiting_apply',
            'diff_data' => ['type' => 'text_diff'],
        ]);

        $toolCall = AgentToolCall::query()->create([
            'run_id' => $run->id,
            'run_step_id' => $runStep->id,
            'tool_definition_id' => null,
            'tool_binding_id' => null,
            'input_payload' => ['post_id' => 77],
            'status' => 'completed',
            'retry_scope' => 'pipeline',
            'retry_count' => 0,
            'idempotency_key' => 'run:1:step:1',
            'output_artifact_id' => $artifact->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return compact('run', 'runStep', 'artifact', 'approval', 'toolCall', 'instance');
    }

    /**
     * @return array{run: AgentRun, runStep: AgentRunStep, instance: WorkflowInstance, workflowStep: WorkflowInstanceStep}
     */
    protected function seedRunningRunGraph(): array
    {
        $profile = AgentProfile::query()->create([
            'name' => 'Ops Observer',
            'slug' => 'ops-observer',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
        ]);

        $instance = WorkflowInstance::query()->create([
            'status' => 'running',
            'context_snapshot' => ['vars' => []],
        ]);

        $workflowStep = WorkflowInstanceStep::query()->create([
            'instance_id' => $instance->id,
            'status' => 'running',
        ]);

        $run = AgentRun::query()->create([
            'agent_profile_id' => $profile->id,
            'workflow_instance_id' => $instance->id,
            'parent_workflow_step_id' => $workflowStep->id,
            'environment' => 'testing',
            'module_scope' => 'core',
            'target_type' => AgenticRunControllerDummyTarget::class,
            'target_id' => '501',
            'status' => 'running',
            'input_goal' => 'Observe runtime health',
            'context_snapshot' => ['root' => ['id' => 501]],
            'budget_usage' => ['steps' => 1, 'tool_calls' => 0, 'tokens' => 0],
            'started_at' => now(),
        ]);

        $runStep = AgentRunStep::query()->create([
            'run_id' => $run->id,
            'step_index' => 1,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => 'core.agentic_health_overview',
            'reasoning_summary' => 'Executing ops overview.',
            'status' => 'running',
        ]);

        return compact('run', 'runStep', 'instance', 'workflowStep');
    }

    protected function migrateTables(): void
    {
        Schema::create('agent_action_dummy_targets', function (Blueprint $table) {
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

        $migration = require base_path('cms/Core/database/migrations/2026_03_10_160000_create_agentic_runtime_tables.php');
        $migration->up();
        $controlMigration = require base_path('cms/Core/database/migrations/2026_03_10_190000_add_agentic_run_controls_and_schedules.php');
        $controlMigration->up();
        $stopMigration = require base_path('cms/Core/database/migrations/2026_03_10_213000_add_stop_request_fields_to_agent_runs.php');
        $stopMigration->up();
        (require base_path('cms/Core/database/migrations/2026_11_05_120100_add_delegated_by_to_agentic_runs.php'))->up();
    }

    protected function grantToolToProfile(
        AgentProfile $profile,
        string $toolClass,
        string $toolName,
        string $moduleScope,
    ): AgentToolDefinition {
        app(AgentToolRegistry::class)->syncDiscoveredService($toolClass, $moduleScope, 'testing');

        $definition = AgentToolDefinition::query()->where('tool_name', $toolName)->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => [
                'enabled' => true,
                'safety_override' => null,
            ],
        ]);

        return $definition;
    }
}

class AgenticRunControllerDummyTarget extends Model
{
    protected $table = 'agent_action_dummy_targets';
    protected $guarded = [];
}
