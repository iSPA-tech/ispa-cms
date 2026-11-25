<?php

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * B1 backfill: the migration gives every existing project a sealed version 1 built from its current rows (the same
 * JSON the publish service writes) and attaches the runs that have not finished.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('adds the version columns', function () {
    expect(Schema::hasTable('workflow_versions'))->toBeTrue()
        ->and(Schema::hasColumns('workflow_projects', ['published_version_id', 'paused_reason', 'paused_at', 'policy_exemptions']))->toBeTrue()
        ->and(Schema::hasColumn('workflow_instances', 'version_id'))->toBeTrue();
});

it('backfills version 1 for existing projects and attaches unfinished runs', function () {
    $project = WorkflowProject::query()->create([
        'name' => 'Legacy', 'slug' => 'legacy', 'is_active' => true, 'trigger_event' => 'manual', 'target_entity_type' => 'none',
        'schedule_cron' => '*/5 * * * *', 'budget_max_cost_per_run' => 1.5,
    ]);
    $a = WorkflowStep::query()->create(['project_id' => $project->id, 'step_code' => 'b_second', 'name' => 'Second', 'step_type' => 'log_message', 'step_config' => ['message' => 'é/ü {{ x }}', 'level' => 'info']]);
    $b = WorkflowStep::query()->create(['project_id' => $project->id, 'step_code' => 'a_first', 'name' => 'First', 'step_type' => 'trigger_manual', 'step_config' => [], 'is_start' => true]);
    WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $b->id, 'to_step_id' => $a->id, 'condition' => ['source_handle' => 'main']]);
    $running = WorkflowInstance::query()->create(['project_id' => $project->id, 'target_type' => WorkflowProject::class, 'status' => 'awaiting_approval']);
    $done = WorkflowInstance::query()->create(['project_id' => $project->id, 'target_type' => WorkflowProject::class, 'status' => 'completed']);

    $migration = require base_path('cms/Core/database/migrations/2026_11_24_000100_create_workflow_versions_table.php');
    $migration->up();

    $project->refresh();
    $version = WorkflowVersion::query()->findOrFail($project->published_version_id);

    expect($version->number)->toBe(1)
        ->and($version->status)->toBe('published')
        ->and($version->getRawOriginal('graph'))->toBe(WorkflowVersionSnapshot::encode(WorkflowVersionSnapshot::fromDraft($project)))
        ->and($version->content_hash)->toBe(WorkflowVersionSnapshot::hash(WorkflowVersionSnapshot::fromDraft($project)))
        ->and(WorkflowVersionSeal::verify($version))->toBeTrue()
        ->and($version->graphData()['steps'][0]['step_code'])->toBe('a_first')
        ->and($version->graphData()['steps'][0]['id'])->toBe($b->id)
        ->and($version->graphData()['settings']['schedule_cron'])->toBe('*/5 * * * *')
        ->and($running->fresh()->version_id)->toBe($version->id)
        ->and($done->fresh()->version_id)->toBeNull();

    // Running it again changes nothing.
    $migration->up();
    expect(WorkflowVersion::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('produces the same JSON however the decimals and booleans come back from the driver', function () {
    $rows = [
        ['id' => 3, 'step_code' => 's', 'name' => 'S', 'step_type' => 'log_message', 'step_config' => '{"b":1,"a":"x"}', 'is_start' => '1', 'position_x' => '10'],
    ];
    $one = WorkflowVersionSnapshot::build(['budget_max_cost_per_run' => '5.0000', 'budget_max_runs_per_day' => '3'], $rows, []);
    $rows[0]['is_start'] = true;
    $rows[0]['position_x'] = 10;
    $two = WorkflowVersionSnapshot::build(['budget_max_cost_per_run' => 5, 'budget_max_runs_per_day' => 3], $rows, []);

    expect(WorkflowVersionSnapshot::encode($one))->toBe(WorkflowVersionSnapshot::encode($two));
});
