<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Models\User;
use Mockery;
use Cms\Core\Jobs\RunAgentScheduleJob;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentSchedule;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Services\Agentic\AgentScheduleService;
use Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService;
use Cms\Core\Services\Agentic\WorkflowContextToolService;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Services\Agentic\PostReadToolService;

class AgenticScheduleControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();
        \Cms\Core\Tests\Support\WorkflowTestVersions::ensureSchema(); // schedule wrappers are published versions (B1)
        $this->actingAs(User::query()->create([
            'name' => 'Webmaster',
            'email' => 'webmaster@example.test',
            'password' => 'secret',
        ]), 'sanctum');
    }

    public function test_store_endpoint_creates_schedule_for_granted_profile_tool_pair(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $response = $this->postJson('/api/v2/admin/agentic/schedules', [
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Nightly context check',
            'module_scope' => 'core',
            'cadence_minutes' => 30,
            'tool_input' => [
                'requested_var' => '__runtime.instance_id',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nightly context check')
            ->assertJsonPath('data.profile.slug', $profile->slug)
            ->assertJsonPath('data.tool_definition.tool_name', $definition->tool_name);

        $schedule = AgentSchedule::query()->first();

        $this->assertNotNull($schedule);
        $this->assertSame(1, AgentSchedule::query()->count());
        $this->assertNotNull($schedule->workflow_project_id);
        $this->assertDatabaseHas('workflow_projects', [
            'id' => $schedule->workflow_project_id,
            'slug' => 'agentic-schedule-' . $schedule->id,
            'target_entity_type' => AgentSchedule::class,
        ]);
    }

    public function test_store_endpoint_defaults_module_scope_from_tool_definition_when_omitted(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndToolForContent();

        $response = $this->postJson('/api/v2/admin/agentic/schedules', [
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Content digest',
            'cadence_minutes' => 60,
            'tool_input' => [
                'limit' => 3,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.module_scope', 'content')
            ->assertJsonPath('data.environment', 'testing');

        $schedule = AgentSchedule::query()->latest('id')->first();

        $this->assertNotNull($schedule);
        $this->assertSame('content', $schedule->module_scope);
        $this->assertSame('testing', $schedule->environment);
    }

    public function test_dispatch_endpoint_marks_schedule_running_and_queues_job(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Queued run',
            'status' => 'active',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/dispatch");

        $response->assertOk()
            ->assertJsonPath('data.status', 'running');

        $this->assertSame('running', $schedule->fresh()->status);
        Queue::assertPushed(RunAgentScheduleJob::class, fn (RunAgentScheduleJob $job) => $job->agentScheduleId() === $schedule->id && $job->steadyStateAfterTerminal() === 'active');
    }

    public function test_dispatch_endpoint_allows_operator_without_provision_permission(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Operator dispatch',
            'status' => 'active',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $authorization = Mockery::mock(AgenticAuthorization::class);
        $authorization->shouldReceive('canOperate')->once()->andReturnTrue();
        $authorization->shouldReceive('canProvision')->never();
        $this->app->instance(AgenticAuthorization::class, $authorization);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/dispatch");

        $response->assertOk()
            ->assertJsonPath('data.status', 'running');

        Queue::assertPushed(RunAgentScheduleJob::class, fn (RunAgentScheduleJob $job) => $job->agentScheduleId() === $schedule->id && $job->steadyStateAfterTerminal() === 'active');
    }

    public function test_dispatch_endpoint_rejects_duplicate_dispatch_for_running_schedule(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Already running',
            'status' => 'running',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/dispatch");

        $response->assertStatus(400)
            ->assertJsonPath('message', "Agent schedule [{$schedule->id}] can not be dispatched from status [running].");

        Queue::assertNothingPushed();
    }

    public function test_dispatch_endpoint_rejects_paused_schedule(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Paused schedule',
            'status' => 'paused',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/dispatch");

        $response->assertStatus(400)
            ->assertJsonPath('message', "Agent schedule [{$schedule->id}] can not be dispatched from status [paused].");

        Queue::assertNothingPushed();
    }

    public function test_run_once_endpoint_marks_paused_schedule_running_and_queues_paused_steady_state_job(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Run once schedule',
            'status' => 'paused',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/run-once");

        $response->assertOk()
            ->assertJsonPath('data.status', 'running');

        Queue::assertPushed(RunAgentScheduleJob::class, fn (RunAgentScheduleJob $job) => $job->agentScheduleId() === $schedule->id && $job->steadyStateAfterTerminal() === 'paused');
    }

    public function test_resume_dispatch_endpoint_marks_paused_schedule_running_and_queues_active_steady_state_job(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Resume and run schedule',
            'status' => 'paused',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->postJson("/api/v2/admin/agentic/schedules/{$schedule->id}/resume-dispatch");

        $response->assertOk()
            ->assertJsonPath('data.status', 'running');

        Queue::assertPushed(RunAgentScheduleJob::class, fn (RunAgentScheduleJob $job) => $job->agentScheduleId() === $schedule->id && $job->steadyStateAfterTerminal() === 'active');
    }

    public function test_update_endpoint_can_pause_schedule(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Pause me',
            'status' => 'active',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->addMinutes(15),
        ]);

        $response = $this->patchJson("/api/v2/admin/agentic/schedules/{$schedule->id}", [
            'status' => 'paused',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.actions.can_resume', true)
            ->assertJsonPath('data.actions.can_pause', false);

        $this->assertSame('paused', $schedule->fresh()->status);
    }

    public function test_update_endpoint_resyncs_wrapper_step_configuration(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Sync wrapper',
            'status' => 'active',
            'module_scope' => 'core',
            'environment' => 'testing',
            'cadence_minutes' => 15,
            'tool_input' => [
                'requested_var' => '__runtime.instance_id',
            ],
            'next_run_at' => now()->addMinutes(15),
        ]);

        app(\Cms\Core\Services\Agentic\AgentScheduleWorkflowProjectService::class)->ensureWrapper($schedule);

        $response = $this->patchJson("/api/v2/admin/agentic/schedules/{$schedule->id}", [
            'module_scope' => 'core-ops',
            'goal' => 'Updated goal',
            'tool_input' => [
                'requested_var' => '__runtime.agent_schedule_id',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_project.id', $schedule->workflow_project_id);

        $step = WorkflowStep::query()
            ->where('project_id', $schedule->workflow_project_id)
            ->where('step_code', 'scheduled_agent_action')
            ->first();

        $this->assertNotNull($step);
        $this->assertSame('core-ops', $step->step_config['moduleScope'] ?? null);
        $this->assertSame('"Updated goal"', $step->step_config['goal'] ?? null);
        $this->assertSame('"__runtime.agent_schedule_id"', $step->step_config['toolInput']['requested_var'] ?? null);
    }

    public function test_schedule_job_executes_and_updates_last_run_summary(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();
        $target = AgenticScheduleControllerDummyTarget::query()->create(['name' => 'Target']);

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Immediate run',
            'status' => 'running',
            'module_scope' => 'core',
            'target_type' => AgenticScheduleControllerDummyTarget::class,
            'target_id' => (string) $target->id,
            'cadence_minutes' => 15,
            'tool_input' => [
                'requested_var' => '__runtime.instance_id',
            ],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunAgentScheduleJob($schedule->id, 'active'))->handle(app(AgentScheduleService::class));

        $schedule->refresh();

        $this->assertSame('active', $schedule->status);
        $this->assertNotNull($schedule->last_run_id);
        $this->assertSame('completed', $schedule->last_outcome);
        $this->assertNotNull($schedule->workflow_project_id);

        $run = AgentRun::query()->find($schedule->last_run_id);
        $this->assertNotNull($run);
        $this->assertSame('schedule', $run->trigger_mode);
        $this->assertSame($schedule->id, (int) $run->trigger_reference_id);
        $this->assertNotNull($run->workflow_instance_id);

        $instance = WorkflowInstance::query()->find($run->workflow_instance_id);
        $this->assertNotNull($instance);
        $this->assertSame($schedule->workflow_project_id, $instance->project_id);

        $instanceStep = WorkflowInstanceStep::query()
            ->where('instance_id', $instance->id)
            ->whereNotNull('step_id')
            ->first();

        $this->assertNotNull($instanceStep);

        $project = WorkflowProject::query()->find($schedule->workflow_project_id);
        $this->assertNotNull($project);
        $this->assertSame('agentic-schedule-' . $schedule->id, $project->slug);
        $this->assertSame(AgentSchedule::class, $project->target_entity_type);
    }

    public function test_schedule_job_falls_back_to_agent_schedule_target_when_no_explicit_target_is_defined(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Fallback target run',
            'status' => 'running',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'tool_input' => [
                'requested_var' => '__runtime.instance_id',
            ],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunAgentScheduleJob($schedule->id, 'active'))->handle(app(AgentScheduleService::class));

        $schedule->refresh();

        $this->assertNotNull($schedule->workflow_project_id);

        $project = WorkflowProject::query()->find($schedule->workflow_project_id);
        $this->assertNotNull($project);
        $this->assertSame(AgentSchedule::class, $project->target_entity_type);

        $run = AgentRun::query()->find($schedule->last_run_id);
        $this->assertNotNull($run);

        $instance = WorkflowInstance::query()->find($run->workflow_instance_id);
        $this->assertNotNull($instance);
        $this->assertSame(AgentSchedule::class, $instance->target_type);
        $this->assertSame((string) $schedule->id, (string) $instance->target_id);
    }

    public function test_schedule_job_resolves_module_scoped_content_tool_when_schedule_scope_is_missing(): void
    {
        $postRepo = \Mockery::mock(PostInterface::class);
        $postRepo->shouldReceive('getLatestPosts')
            ->once()
            ->with(2)
            ->andReturn([
                ['id' => 101, 'name' => 'Post One', 'status' => 'published'],
                ['id' => 102, 'name' => 'Post Two', 'status' => 'published'],
            ]);
        $this->app->instance(PostInterface::class, $postRepo);

        [$profile, $definition] = $this->seedGrantedProfileAndToolForContent();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Content latest posts',
            'status' => 'running',
            'environment' => 'testing',
            'module_scope' => null,
            'cadence_minutes' => 15,
            'tool_input' => [
                'limit' => 2,
            ],
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunAgentScheduleJob($schedule->id, 'active'))->handle(app(AgentScheduleService::class));

        $schedule->refresh();

        $this->assertSame('active', $schedule->status);
        $this->assertSame('completed', $schedule->last_outcome);
        $this->assertNull($schedule->last_error);

        $step = WorkflowStep::query()
            ->where('project_id', $schedule->workflow_project_id)
            ->where('step_code', 'scheduled_agent_action')
            ->first();

        $this->assertNotNull($step);
        $this->assertSame('content', $step->step_config['moduleScope'] ?? null);
        $this->assertSame(PostReadToolService::class, $step->step_config['toolClass'] ?? null);
    }

    public function test_schedule_job_run_once_returns_schedule_to_paused_after_terminal_completion(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Paused one-off',
            'status' => 'running',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        (new RunAgentScheduleJob($schedule->id, 'paused'))->handle(app(AgentScheduleService::class));

        $schedule->refresh();

        $this->assertSame('paused', $schedule->status);
        $this->assertSame('completed', $schedule->last_outcome);
    }

    public function test_runtime_sync_keeps_schedule_running_for_non_terminal_workflow_status(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Awaiting apply schedule',
            'status' => 'running',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $originalNextRunAt = optional($schedule->next_run_at)->toJSON();

        $instance = WorkflowInstance::query()->create([
            'project_id' => null,
            'target_type' => AgentSchedule::class,
            'target_id' => $schedule->id,
            'status' => 'awaiting_apply',
            'context_snapshot' => [
                'vars' => [
                    '__runtime' => [
                        'agent_schedule_id' => $schedule->id,
                    ],
                ],
            ],
            'finished_at' => null,
        ]);

        app(AgentScheduleRuntimeSyncService::class)->syncFromWorkflowInstance($instance);

        $schedule->refresh();

        $this->assertSame('running', $schedule->status);
        $this->assertSame('awaiting_apply', $schedule->last_outcome);
        $this->assertSame($originalNextRunAt, optional($schedule->next_run_at)->toJSON());
    }

    public function test_runtime_sync_releases_schedule_when_workflow_instance_finishes(): void
    {
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Completed schedule',
            'status' => 'running',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        $instance = WorkflowInstance::query()->create([
            'project_id' => null,
            'target_type' => AgentSchedule::class,
            'target_id' => $schedule->id,
            'status' => 'completed',
            'context_snapshot' => [
                'vars' => [
                    '__runtime' => [
                        'agent_schedule_id' => $schedule->id,
                    ],
                ],
            ],
            'finished_at' => now(),
        ]);

        app(AgentScheduleRuntimeSyncService::class)->syncFromWorkflowInstance($instance);

        $schedule->refresh();

        $this->assertSame('active', $schedule->status);
        $this->assertSame('completed', $schedule->last_outcome);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_agentic_run_schedules_command_queues_due_schedules(): void
    {
        Queue::fake();
        [$profile, $definition] = $this->seedGrantedProfileAndTool();

        $schedule = AgentSchedule::query()->create([
            'agent_profile_id' => $profile->id,
            'tool_definition_id' => $definition->id,
            'name' => 'Due schedule',
            'status' => 'active',
            'module_scope' => 'core',
            'cadence_minutes' => 15,
            'next_run_at' => now()->subMinute(),
        ]);

        Artisan::call('cms:agentic-run-schedules', ['--limit' => 10]);

        $this->assertStringContainsString('Queued 1 agentic schedule(s).', Artisan::output());
        $this->assertSame('running', $schedule->fresh()->status);
        Queue::assertPushed(RunAgentScheduleJob::class, fn (RunAgentScheduleJob $job) => $job->agentScheduleId() === $schedule->id);
    }

    /**
     * @return array{0: AgentProfile, 1: AgentToolDefinition}
     */
    protected function seedGrantedProfileAndTool(): array
    {
        $profile = AgentProfile::query()->create([
            'name' => 'Maintenance Runner',
            'slug' => 'maintenance-runner',
            'capability_class' => 'class_3_autonomous_maintenance',
            'status' => 'active',
        ]);

        app(AgentToolRegistry::class)->syncDiscoveredService(WorkflowContextToolService::class, 'core', 'testing');

        $definition = AgentToolDefinition::query()->where('tool_name', 'core.inspect_workflow_context')->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => [
                'enabled' => true,
                'safety_override' => null,
            ],
        ]);

        return [$profile, $definition];
    }

    /**
     * @return array{0: AgentProfile, 1: AgentToolDefinition}
     */
    protected function seedGrantedProfileAndToolForContent(): array
    {
        $profile = AgentProfile::query()->create([
            'name' => 'Content Maintainer',
            'slug' => 'content-maintainer',
            'capability_class' => 'class_3_autonomous_maintenance',
            'status' => 'active',
        ]);

        app(AgentToolRegistry::class)->syncDiscoveredService(PostReadToolService::class, 'content', 'testing');

        $definition = AgentToolDefinition::query()->where('tool_name', 'content.latest_posts')->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => [
                'enabled' => true,
                'safety_override' => null,
            ],
        ]);

        return [$profile, $definition];
    }

    protected function migrateTables(): void
    {
        Schema::create('agentic_schedule_dummy_targets', function (Blueprint $table) {
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
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
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
            $table->string('target_entity_type');
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

        $migration = require base_path('cms/Core/database/migrations/2026_03_10_160000_create_agentic_runtime_tables.php');
        $migration->up();
        $controlMigration = require base_path('cms/Core/database/migrations/2026_03_10_190000_add_agentic_run_controls_and_schedules.php');
        $controlMigration->up();
        $wrapperMigration = require base_path('cms/Core/database/migrations/2026_03_10_221000_add_workflow_project_id_to_agent_schedules.php');
        $wrapperMigration->up();
        (require base_path('cms/Core/database/migrations/2026_11_05_120100_add_delegated_by_to_agentic_runs.php'))->up();
    }
}

class AgenticScheduleControllerDummyTarget extends Model
{
    protected $table = 'agentic_schedule_dummy_targets';
    protected $guarded = [];
}
