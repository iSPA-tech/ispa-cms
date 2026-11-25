<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Workflow\AutoApproval\StepApprovalCalibration;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Review fix 6, part 2 (2026-11-25): JEV calibration counts only decisions of people who did not write the workflow
 * (its publishers and draft editors), so one author cannot produce the agreeing decisions that unlock auto-publish.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function calUser(string $name): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => $name, 'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
}

function calProject(): WorkflowProject
{
    $project = WorkflowProject::query()->create(['name' => 'Calibration', 'slug' => 'calibration-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $ask = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => 'approval', 'step_code' => 'ask', 'position_x' => 1, 'is_start' => true,
        'step_config' => ['title' => 'Story', 'payload' => "'x'", 'notify' => false]]);
    $ok = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => 'set_variable', 'step_code' => 'ok', 'position_x' => 2,
        'step_config' => ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]]);
    WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $ask->id, 'to_step_id' => $ok->id,
        'condition' => ['source_handle' => 'approved', 'condition_type' => 'equals', 'condition_value' => 'approved']]);

    return $project;
}

/** One run whose approval $decider approves, recorded with an agreeing JEV pass. */
function calDecide(WorkflowProject $project, User $decider): void
{
    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => null, 'status' => 'pending']);
    app(WorkflowGraphExecutor::class)->execute($instance->id);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $diff = (array) $approval->diff_data;
    $diff['workflow_node']['jev'] = ['verdict' => 'pass', 'status' => 'assessed', 'probability' => 0.95];
    $approval->forceFill(['diff_data' => $diff])->save();
    app(AgentApprovalService::class)->approve($approval, $decider);
}

beforeEach(function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'workflow_instance_steps')->value('sql');
        $fixed = preg_replace('/,\s*foreign key\("step_id"\) references "workflow_instances"\("id"\)[^,)]*/i', '', $sql);
        if ($fixed !== null && $fixed !== $sql) {
            DB::statement((string) preg_replace('/^CREATE TABLE "?workflow_instance_steps"?/i', 'CREATE TABLE "workflow_instance_steps_fixed"', $fixed));
            DB::statement('INSERT INTO workflow_instance_steps_fixed SELECT * FROM workflow_instance_steps');
            DB::statement('DROP TABLE workflow_instance_steps');
            DB::statement('ALTER TABLE workflow_instance_steps_fixed RENAME TO workflow_instance_steps');
        }
    }
    config(['core.workflow_nodes.human.jev.calibration_min_decisions' => 3]);
    calUser('placeholder'); // user id 1 is a webmaster
});

it('counts only decisions of people who did not publish or edit the workflow', function (): void {
    $publisher = calUser('publisher');
    $editor = calUser('editor');
    $reviewer = calUser('reviewer');
    $project = calProject();

    foreach ([$publisher, $publisher, $editor, $editor, $reviewer] as $decider) {
        calDecide($project, $decider);
    }
    // The publisher pressed Publish; the editor's draft edits went into the version.
    WorkflowVersion::query()->where('project_id', $project->id)->update(['created_by' => $publisher->id, 'editor_ids' => json_encode([$editor->id])]);

    $calibration = app(StepApprovalCalibration::class)->calibration($project->fresh(), 'ask');
    $recent = app(StepApprovalCalibration::class)->recentDecisions($project->fresh(), 'ask', 10);

    expect($calibration['decided'])->toBe(1)
        ->and($calibration['agreed'])->toBe(1)
        ->and($calibration['ready'])->toBeFalse()
        ->and($recent)->toHaveCount(1);
});

it('also leaves out the current draft editors', function (): void {
    $drafter = calUser('drafter');
    $reviewer = calUser('reviewer');
    $project = calProject();
    calDecide($project, $drafter);
    calDecide($project, $reviewer);
    DB::table('workflow_projects')->where('id', $project->id)->update(['draft_editor_ids' => json_encode([$drafter->id])]);

    expect(app(StepApprovalCalibration::class)->calibration($project->fresh(), 'ask')['decided'])->toBe(1);
});
