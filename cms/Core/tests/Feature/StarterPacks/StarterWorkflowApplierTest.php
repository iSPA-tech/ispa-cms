<?php

declare(strict_types=1);

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterWorkflowApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    foreach (['workflow_connections', 'workflow_steps', 'workflow_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('workflow_projects', function (Blueprint $table) {
        $table->id();
        $table->uuid('uuid')->nullable()->unique();
        $table->string('name');
        $table->string('slug')->unique();
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
        $table->string('step_type');
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
});

/**
 * A valid starter workflow entry with two steps and one connection.
 *
 * @param array<string, mixed> $override
 *
 * @return array<string, mixed>
 */
function swaEntry(string $slug = 'starter_flow', array $override = []): array
{
    return $override + [
        'slug' => $slug,
        'name' => 'Starter flow',
        'trigger_event' => 'order.paid',
        'target_entity_type' => 'App\\Models\\Order',
        'tags' => ['starter'],
        'description' => 'A starter workflow',
        'steps' => [
            ['step_code' => 'start', 'name' => 'Start', 'step_type' => 'event', 'step_config' => [], 'position_x' => 0, 'position_y' => 0, 'is_start' => true],
            ['step_code' => 'work', 'name' => 'Work', 'step_type' => 'job', 'step_config' => [], 'position_x' => 100, 'position_y' => 0, 'is_start' => false],
        ],
        'connections' => [['from' => 'start', 'to' => 'work', 'condition' => null]],
    ];
}

/**
 * Resolve the applier from the container.
 */
function swaApplier(): StarterWorkflowApplier
{
    return app(StarterWorkflowApplier::class);
}

it('creates a workflow with its steps and connection pointing at the new rows', function (): void {
    $result = swaApplier()->apply([swaEntry()]);

    expect($result)->toBe(['created' => ['starter_flow'], 'skipped' => []]);

    $project = WorkflowProject::query()->where('slug', 'starter_flow')->firstOrFail();
    $steps = WorkflowStep::query()->where('project_id', $project->id)->orderBy('id')->get();
    expect($steps)->toHaveCount(2);
    expect($steps->pluck('step_code')->all())->toBe(['start', 'work']);

    $connections = WorkflowConnection::query()->where('project_id', $project->id)->get();
    expect($connections)->toHaveCount(1);
    expect((int) $connections[0]->from_step_id)->toBe((int) $steps[0]->id);
    expect((int) $connections[0]->to_step_id)->toBe((int) $steps[1]->id);
});

it('skips a workflow whose slug already exists and leaves it untouched', function (): void {
    $project = WorkflowProject::create([
        'name' => 'Operator edited', 'slug' => 'starter_flow', 'target_entity_type' => 'App\\Models\\Other', 'is_active' => true,
    ]);
    WorkflowStep::create(['project_id' => $project->id, 'step_code' => 'only', 'name' => 'Only', 'step_type' => 'event']);

    $result = swaApplier()->apply([swaEntry()]);

    expect($result['created'])->toBe([]);
    expect($result['skipped'])->toBe([['slug' => 'starter_flow', 'reason' => StarterWorkflowApplier::REASON_EXISTS]]);

    $fresh = $project->fresh();
    expect($fresh->name)->toBe('Operator edited');
    expect((bool) $fresh->is_active)->toBeTrue();
    expect(WorkflowStep::query()->where('project_id', $project->id)->pluck('step_code')->all())->toBe(['only']);
    expect(WorkflowConnection::query()->count())->toBe(0);
});

it('throws on an invalid graph before writing anything, even after a valid row', function (): void {
    $bad = swaEntry('bad_flow', ['connections' => [['from' => 'start', 'to' => 'ghost', 'condition' => null]]]);

    expect(fn () => swaApplier()->apply([swaEntry('good_flow'), $bad]))
        ->toThrow(StarterPackException::class, 'bad_flow');

    expect(WorkflowProject::withTrashed()->count())->toBe(0);
    expect(WorkflowStep::withTrashed()->count())->toBe(0);
});

it('creates the workflow inactive and rejects is_active in the input', function (): void {
    swaApplier()->apply([swaEntry()]);
    expect((bool) WorkflowProject::query()->where('slug', 'starter_flow')->firstOrFail()->is_active)->toBeFalse();

    expect(fn () => swaApplier()->apply([swaEntry('other', ['is_active' => true])]))
        ->toThrow(StarterPackException::class, 'is_active');
    expect(WorkflowProject::query()->where('slug', 'other')->exists())->toBeFalse();
});

it('plans without writing and creates nothing on a second apply', function (): void {
    $plan = swaApplier()->plan([swaEntry()]);
    expect($plan['created'])->toBe(['starter_flow']);
    expect(WorkflowProject::withTrashed()->count())->toBe(0);

    swaApplier()->apply([swaEntry()]);
    $second = swaApplier()->apply([swaEntry()]);

    expect($second['created'])->toBe([]);
    expect($second['skipped'][0]['slug'])->toBe('starter_flow');
    expect(WorkflowProject::query()->count())->toBe(1);
    expect(WorkflowStep::query()->count())->toBe(2);
    expect(WorkflowConnection::query()->count())->toBe(1);
});

it('skips a slug that belongs to a deleted workflow and rejects non-list input', function (): void {
    $project = WorkflowProject::create(['name' => 'Gone', 'slug' => 'starter_flow', 'target_entity_type' => 'X']);
    $project->delete();

    $result = swaApplier()->apply([swaEntry()]);
    expect($result['skipped'])->toBe([['slug' => 'starter_flow', 'reason' => StarterWorkflowApplier::REASON_DELETED]]);
    expect(WorkflowProject::withTrashed()->count())->toBe(1);

    expect(fn () => swaApplier()->apply(['a' => swaEntry()]))->toThrow(StarterPackException::class, 'workflows.json');
});
