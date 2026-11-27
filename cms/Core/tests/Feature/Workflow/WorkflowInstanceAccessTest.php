<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * @param list<string> $permissions
 */
function instanceAccessUser(array $permissions, int $id = 501): User
{
    $user = new class extends User {
        /** @var list<string> */
        public array $grantedKeys = [];

        public function permissionKeys(): array
        {
            return $this->grantedKeys;
        }
    };
    $user->grantedKeys = $permissions;
    $user->forceFill(['id' => $id, 'name' => 'Run Viewer', 'email' => uniqid('rv_', true) . '@example.com', 'password' => 'x']);
    $user->exists = true;

    return $user;
}

beforeEach(function (): void {
    $this->withoutMiddleware();

    foreach (['workflow_instance_steps', 'workflow_instances', 'workflow_steps', 'workflow_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('workflow_projects', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->unsignedBigInteger('users_id')->nullable();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('trigger_event')->nullable();
        $table->string('target_entity_type')->nullable();
        $table->boolean('is_active')->default(true);
        $table->json('tags')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('workflow_steps', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->string('step_code')->nullable();
        $table->string('name')->nullable();
        $table->string('step_type', 64);
        $table->json('step_config')->nullable();
        $table->boolean('is_start')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('workflow_instances', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->string('status')->default('pending');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('workflow_instance_steps', function (Blueprint $table): void {
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

    $this->project = WorkflowProject::query()->create(['name' => 'Other', 'slug' => 'other', 'target_entity_type' => 'X']);
    WorkflowProject::query()->whereKey($this->project->id)->update(['users_id' => 9999]);
    $this->instance = WorkflowInstance::query()->create([
        'project_id' => $this->project->id, 'status' => 'failed', 'started_at' => now()->subSecond(), 'finished_at' => now(),
    ]);
    WorkflowInstanceStep::query()->create([
        'instance_id' => $this->instance->id, 'step_id' => 1, 'status' => 'failed', 'error_message' => 'secret detail',
        'result_data' => ['logs' => [['level' => 'error', 'message' => 'secret detail']]],
    ]);
});

it('refuses run history to a user without the workflow permission', function (): void {
    Sanctum::actingAs(instanceAccessUser([]));

    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}")->assertStatus(403);
    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}/steps")
        ->assertStatus(403)->assertDontSee('secret detail');
});

it('answers 403, not 404, for a missing instance without the permission', function (): void {
    Sanctum::actingAs(instanceAccessUser([]));

    $this->getJson('/api/v2/admin/workflow/instances/999999')->assertStatus(403);
    $this->getJson('/api/v2/admin/workflow/instances/999999/steps')->assertStatus(403);
});

it('shows run history to a user with workflow_project.viewAny', function (): void {
    Sanctum::actingAs(instanceAccessUser(['workflow_project.viewAny']));

    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}")
        ->assertOk()->assertJsonStructure(['data' => ['instance', 'project', 'summary']]);
    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}/steps")
        ->assertOk()->assertJsonFragment(['error_message' => 'secret detail']);
});

it('lets the owner of the project see its runs and nobody else without the permission', function (): void {
    Sanctum::actingAs(instanceAccessUser([], 9999));
    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}/steps")->assertOk();

    Sanctum::actingAs(instanceAccessUser([], 4242));
    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}/steps")->assertStatus(403);
});

it('refuses a guest', function (): void {
    $this->getJson("/api/v2/admin/workflow/instances/{$this->instance->id}")->assertStatus(403);
});
