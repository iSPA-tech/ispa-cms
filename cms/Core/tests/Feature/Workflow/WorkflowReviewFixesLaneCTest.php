<?php

declare(strict_types=1);

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
 * Review fix 36 (2026-11-25), small items of lane C: the designer's function list needs workflow rights, and the run
 * detail answers a numeric id only and gives the full run data only to people who may edit the workflow.
 */

require_once __DIR__ . '/Guard/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware();
    wgUser('owner'); // user id 1, a Webmaster
});

it('lists designer functions only to people with workflow rights', function (): void {
    Sanctum::actingAs(wgUser('stranger', ['agentic.viewAny']));
    $this->getJson('/api/v2/admin/workflows/function-list')->assertStatus(403);

    Sanctum::actingAs(wgUser('designer', ['workflow_project.viewAny']));
    $this->getJson('/api/v2/admin/workflows/function-list')->assertOk();
});

it('answers the run detail for numeric ids only and hides run data from viewers who may not edit', function (): void {
    $project = WorkflowProject::query()->create(['name' => 'Runs', 'slug' => 'runs-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => 'none', 'is_active' => false]);
    $instance = WorkflowInstance::query()->create([
        'project_id' => $project->id, 'target_type' => WorkflowProject::class, 'status' => 'completed',
        'context_snapshot' => ['vars' => ['customer_email' => 'person@example.com'], 'trigger' => ['event' => 'manual']],
    ]);

    Sanctum::actingAs(wgUser('viewer', ['workflow_project.viewAny', 'workflow_project.view']));
    $this->getJson('/api/v2/admin/workflow/instances/abc')->assertNotFound();
    $viewed = $this->getJson('/api/v2/admin/workflow/instances/' . $instance->id)->assertOk();
    expect($viewed->json('data.instance.context_snapshot'))->toBeNull()
        ->and($viewed->json('data.instance.status'))->toBe('completed')
        ->and(json_encode($viewed->json()))->not->toContain('person@example.com');

    Sanctum::actingAs(wgUser('editor', ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.update']));
    $edited = $this->getJson('/api/v2/admin/workflow/instances/' . $instance->id)->assertOk();
    expect($edited->json('data.instance.context_snapshot.vars.customer_email'))->toBe('person@example.com');
});
