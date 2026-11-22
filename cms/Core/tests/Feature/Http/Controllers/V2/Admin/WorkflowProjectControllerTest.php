<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;

/**
 * Workflow project admin API contract (system-managed wrapper guard, rename, graph edge canonicalisation).
 *
 * The acting user is granted the `workflow_project.*` permission keys explicitly. The earlier fixture only
 * overrode isWebmaster()/roleLevel(), which the policy ignores, and relied on `config(['app.env' => 'local'])`,
 * which does not change app()->environment() and so never enabled the policy's local bypass; every call was
 * answered with the generic permission-denied text instead of reaching the controller logic under test.
 */
class WorkflowProjectControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config(['app.env' => 'local']);
        $this->migrateTables();

        Sanctum::actingAs($this->makeWebmasterUser());
    }

    public function test_update_rejects_system_managed_workflow_wrapper(): void
    {
        $project = WorkflowProject::query()->create([
            'name' => 'System Wrapper',
            'slug' => 'agentic-schedule-99',
            'trigger_event' => 'agentic.schedule.execute',
            'target_entity_type' => null,
            'is_active' => true,
            'tags' => ['agentic', 'schedule-wrapper', 'system-managed'],
            'description' => 'Managed by schedule',
        ]);

        $this->putJson("/api/v2/admin/workflows/{$project->id}", [
            'name' => 'Edited Wrapper',
        ])->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'System-managed workflow wrappers can not be modified here.');

        $this->assertSame('System Wrapper', $project->fresh()->name);
    }

    public function test_destroy_rejects_system_managed_workflow_wrapper(): void
    {
        $project = WorkflowProject::query()->create([
            'name' => 'System Wrapper',
            'slug' => 'agentic-schedule-100',
            'trigger_event' => 'agentic.schedule.execute',
            'target_entity_type' => null,
            'is_active' => true,
            'tags' => ['agentic', 'schedule-wrapper', 'system-managed'],
            'description' => 'Managed by schedule',
        ]);

        $this->deleteJson("/api/v2/admin/workflows/{$project->id}")
            ->assertStatus(400)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'System-managed workflow wrappers can not be modified here.');

        $this->assertNull($project->fresh()->deleted_at);
    }

    public function test_update_allows_normal_workflow_project(): void
    {
        $project = WorkflowProject::query()->create([
            'name' => 'Normal Workflow',
            'slug' => 'normal-workflow',
            'trigger_event' => 'manual',
            'target_entity_type' => null,
            'is_active' => true,
            'tags' => ['business'],
            'description' => 'Operator managed',
        ]);

        $this->putJson("/api/v2/admin/workflows/{$project->id}", [
            'name' => 'Renamed Workflow',
        ])->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('Renamed Workflow', $project->fresh()->name);
    }

    public function test_update_canonicalizes_builder_decision_edges(): void
    {
        $project = WorkflowProject::query()->create([
            'name' => 'Decision Workflow',
            'slug' => 'decision-workflow',
            'trigger_event' => 'manual',
            'target_entity_type' => null,
            'is_active' => true,
            'tags' => ['business'],
            'description' => 'Decision test',
        ]);

        $response = $this->putJson("/api/v2/admin/workflows/{$project->id}", [
            'nodes' => [
                [
                    'id' => 'input',
                    'type' => 'input',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['step_code' => 'input', 'name' => 'Input'],
                ],
                [
                    'id' => 'decision',
                    'type' => 'decision',
                    'position' => ['x' => 120, 'y' => 0],
                    'data' => [
                        'step_code' => 'decision',
                        'name' => 'Decision',
                        'step_type' => 'decision',
                        'step_config' => [
                            'yes_output' => 'target-a',
                            'no_output' => 'target-b',
                        ],
                    ],
                ],
                [
                    'id' => 'yes',
                    'type' => 'custom',
                    'position' => ['x' => 240, 'y' => -40],
                    'data' => ['step_code' => 'yes', 'name' => 'Yes'],
                ],
                [
                    'id' => 'no',
                    'type' => 'custom',
                    'position' => ['x' => 240, 'y' => 40],
                    'data' => ['step_code' => 'no', 'name' => 'No'],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge-input-decision',
                    'source' => 'input',
                    'target' => 'decision',
                    'data' => ['condition' => null],
                ],
                [
                    'id' => 'edge-decision-yes',
                    'source' => 'decision',
                    'target' => 'yes',
                    'sourceHandle' => 'target-a',
                    'data' => ['condition' => 'true'],
                ],
                [
                    'id' => 'edge-decision-no',
                    'source' => 'decision',
                    'target' => 'no',
                    'sourceHandle' => 'target-b',
                    'data' => ['condition' => 'false'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.edges.1.sourceHandle', 'target-a')
            ->assertJsonPath('data.edges.1.data.condition_value', 'target-a')
            ->assertJsonPath('data.edges.2.sourceHandle', 'target-b')
            ->assertJsonPath('data.edges.2.data.condition_value', 'target-b');

        $decisionEdges = WorkflowConnection::query()
            ->where('project_id', $project->id)
            ->whereHas('fromStep', fn ($query) => $query->where('step_code', 'decision'))
            ->orderBy('id')
            ->get()
            ->pluck('condition')
            ->values();

        $this->assertSame('equals', $decisionEdges[0]['condition_type'] ?? null);
        $this->assertSame('target-a', $decisionEdges[0]['condition_value'] ?? null);
        $this->assertSame('equals', $decisionEdges[1]['condition_type'] ?? null);
        $this->assertSame('target-b', $decisionEdges[1]['condition_value'] ?? null);
    }

    protected function migrateTables(): void
    {
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
    }

    protected function makeWebmasterUser(): User
    {
        $user = new class extends User {
            public function isWebmaster(): bool
            {
                return true;
            }

            public function roleLevel(): int
            {
                return 0;
            }

            /** Permission keys the policy resolves through hasRole(). */
            public function permissionKeys(): array
            {
                return [
                    'workflow_project.viewAny',
                    'workflow_project.view',
                    'workflow_project.create',
                    'workflow_project.update',
                    'workflow_project.delete',
                ];
            }
        };

        $user->forceFill([
            'id' => random_int(1, 99999),
            'name' => 'Workflow Tester',
            'email' => uniqid('workflow_', true) . '@example.com',
            'password' => 'secret',
        ]);
        $user->exists = true;

        return $user;
    }
}
