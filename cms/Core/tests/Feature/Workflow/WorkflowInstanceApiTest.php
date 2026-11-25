<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class WorkflowInstanceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();

        // Run history needs the workflow permission (WorkflowInstanceAccessTest covers the refusals).
        $user = new class extends \App\Models\User {
            public function permissionKeys(): array
            {
                return ['workflow_project.viewAny'];
            }
        };
        $user->forceFill(['id' => 1, 'name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'x']);
        $user->exists = true;
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    public function test_show_returns_summary(): void
    {
        $instance = WorkflowInstance::create([
            'status' => 'completed',
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);

        $res = $this->getJson("/api/v2/admin/workflow/instances/{$instance->id}");
        $res->assertStatus(200)->assertJsonStructure([
            'data' => ['instance', 'project', 'summary'],
        ]);
    }

    public function test_steps_returns_trace(): void
    {
        $instance = WorkflowInstance::create([
            'status' => 'failed',
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);

        WorkflowInstanceStep::create([
            'instance_id' => $instance->id,
            'step_id' => 1,
            'status' => 'failed',
            'error_message' => 'oops',
            'result_data' => ['logs' => [['level' => 'error', 'message' => 'oops']]],
        ]);

        $res = $this->getJson("/api/v2/admin/workflow/instances/{$instance->id}/steps");
        $res->assertStatus(200)
            ->assertJsonFragment(['status' => 'failed'])
            ->assertJsonFragment(['error_message' => 'oops']);
    }

    protected function migrateTables(): void
    {
        Schema::dropIfExists('workflow_instance_steps');
        Schema::dropIfExists('workflow_instances');

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
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
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
