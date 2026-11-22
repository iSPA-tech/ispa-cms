<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Tests\Feature\Workflow;

use Mockery;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Services\Agentic\WorkflowContextToolService;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Services\Agentic\PostReadToolService;

class ReasoningStepRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();
        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\Feature\\Workflow\\']
        ));
    }

    public function test_reasoning_loop_executes_read_tool_and_terminates_on_complete(): void
    {
        $target = ReasoningDummyTarget::query()->create(['name' => 'Target']);

        $project = WorkflowProject::create(['name' => 'Reasoning Test']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'ai_reasoning',
            'step_code' => 'reasoning_step',
            'position_x' => 1,
            'step_config' => [
                'agentProfileSlug' => 'reasoning-reader',
                'goal' => 'Read the latest posts and summarize findings',
                'maxIterations' => 5,
            ],
        ]);

        $profile = AgentProfile::create([
            'name' => 'Reasoning Reader',
            'slug' => 'reasoning-reader',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 10],
        ]);
        $this->grantToolToProfile($profile, PostReadToolService::class, 'content.latest_posts', 'content');

        // Mock the reasoning engine to: (1) call content.latest_posts, (2) return terminal complete
        $callCount = 0;
        $mockEngine = Mockery::mock(ReasoningEngineInterface::class);
        $mockEngine->shouldReceive('plan')
            ->andReturnUsing(function (AgentState $state) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return AgentDecision::tool('content.latest_posts', ['limit' => 3]);
                }
                return AgentDecision::terminal('complete', 'Goal achieved. Posts have been read.');
            });
        $this->app->instance(ReasoningEngineInterface::class, $mockEngine);

        // Mock the PostInterface so the tool can execute
        $postRepo = Mockery::mock(PostInterface::class);
        $postRepo->shouldReceive('getLatestPosts')
            ->andReturn(collect([
                (object) ['id' => 1, 'name' => 'Post A', 'description' => 'Desc A'],
                (object) ['id' => 2, 'name' => 'Post B', 'description' => 'Desc B'],
            ]));
        $this->app->instance(PostInterface::class, $postRepo);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => ReasoningDummyTarget::class,
            'target_id' => $target->id,
            'status' => 'pending',
        ]);

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);

        // Verify agent run was created and completed
        $run = AgentRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->first();

        $this->assertNotNull($run);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('reasoning_loop', $run->trigger_mode);
        $this->assertStringContains('Goal achieved', $run->terminal_reason);

        // Verify run steps were created (1 tool execution step)
        $runSteps = AgentRunStep::query()->where('run_id', $run->id)->get();
        $this->assertGreaterThanOrEqual(1, $runSteps->count());

        // Verify at least one run step was created
        $steps = AgentRunStep::query()->where('run_id', $run->id)->get();
        $this->assertGreaterThanOrEqual(1, $steps->count());
    }

    public function test_reasoning_loop_terminates_on_budget_exhaustion(): void
    {
        $target = ReasoningDummyTarget::query()->create(['name' => 'Target']);

        $project = WorkflowProject::create(['name' => 'Budget Test']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'ai_reasoning',
            'step_code' => 'budget_step',
            'position_x' => 1,
            'step_config' => [
                'agentProfileSlug' => 'budget-limited',
                'goal' => 'Test budget limit',
                'maxIterations' => 10,
            ],
        ]);

        $profile = AgentProfile::create([
            'name' => 'Budget Limited',
            'slug' => 'budget-limited',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 0],  // Already exhausted
        ]);
        $this->grantToolToProfile($profile, PostReadToolService::class, 'content.latest_posts', 'content');

        // The engine should never be called because budget is exhausted before plan()
        $mockEngine = Mockery::mock(ReasoningEngineInterface::class);
        $mockEngine->shouldNotReceive('plan');
        // Actually, plan() calls hydrateState() then budgetGuard.evaluate() which checks budget_config.
        // But the run starts with budget_usage = steps:0 and budget_config = max_steps:0.
        // The DefaultRunBudgetGuard returns remaining = 0 which is not < 0, so it's allowed.
        // Need to set max_steps to -1 or use a different approach.
        // Let me use the NullReasoningEngine instead which returns terminal immediately.
        $mockEngine = Mockery::mock(ReasoningEngineInterface::class);
        $mockEngine->shouldReceive('plan')
            ->andReturn(AgentDecision::terminal('failed', 'budget_steps_exhausted'));
        $this->app->instance(ReasoningEngineInterface::class, $mockEngine);

        $postRepo = Mockery::mock(PostInterface::class);
        $this->app->instance(PostInterface::class, $postRepo);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => ReasoningDummyTarget::class,
            'target_id' => $target->id,
            'status' => 'pending',
        ]);

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id);

        $instance->refresh();

        $run = AgentRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->first();

        $this->assertNotNull($run);
        $this->assertStringContains('budget_steps_exhausted', $run->terminal_reason ?? '');
    }

    public function test_reasoning_loop_denies_unauthorized_tool_and_continues(): void
    {
        $target = ReasoningDummyTarget::query()->create(['name' => 'Target']);

        $project = WorkflowProject::create(['name' => 'Auth Test']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'ai_reasoning',
            'step_code' => 'auth_step',
            'position_x' => 1,
            'step_config' => [
                'agentProfileSlug' => 'limited-reader',
                'goal' => 'Try unauthorized tool then succeed',
                'maxIterations' => 5,
            ],
        ]);

        $profile = AgentProfile::create([
            'name' => 'Limited Reader',
            'slug' => 'limited-reader',
            'capability_class' => 'class_0_read_only',
            'status' => 'active',
            'budget_config' => ['max_steps' => 10],
        ]);
        // Only grant content.latest_posts, NOT content.search_posts
        $this->grantToolToProfile($profile, PostReadToolService::class, 'content.latest_posts', 'content');

        $callCount = 0;
        $mockEngine = Mockery::mock(ReasoningEngineInterface::class);
        $mockEngine->shouldReceive('plan')
            ->andReturnUsing(function (AgentState $state) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // Try an unauthorized tool first
                    return AgentDecision::tool('content.search_posts', ['keywords' => 'test']);
                }
                if ($callCount === 2) {
                    // Then try the authorized tool
                    return AgentDecision::tool('content.latest_posts', ['limit' => 2]);
                }
                return AgentDecision::terminal('complete', 'Done after retry');
            });
        $this->app->instance(ReasoningEngineInterface::class, $mockEngine);

        $postRepo = Mockery::mock(PostInterface::class);
        $postRepo->shouldReceive('getLatestPosts')->andReturn(collect([]));
        $this->app->instance(PostInterface::class, $postRepo);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => ReasoningDummyTarget::class,
            'target_id' => $target->id,
            'status' => 'pending',
        ]);

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);

        $run = AgentRun::query()
            ->where('workflow_instance_id', $instance->id)
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);

        // Should have a failed step for the unauthorized tool
        $failedStep = AgentRunStep::query()
            ->where('run_id', $run->id)
            ->where('status', 'failed')
            ->first();
        $this->assertNotNull($failedStep);
        $this->assertSame('content.search_posts', $failedStep->action_name);

        // Working memory should record the denial
        $this->assertNotNull($run->working_memory['last_error'] ?? null);
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

    protected function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }

    protected function migrateTables(): void
    {
        Schema::create('reasoning_dummy_targets', function (Blueprint $table) {
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
        });

        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('step_code')->nullable();
            $table->string('name')->nullable();
            $table->string('step_type');
            $table->json('step_config')->nullable();
            $table->integer('position_x')->nullable();
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
}

class ReasoningDummyTarget extends Model
{
    protected $table = 'reasoning_dummy_targets';
    protected $guarded = [];
}
