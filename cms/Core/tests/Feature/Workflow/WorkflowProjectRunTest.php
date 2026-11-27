<?php

use App\Models\User;
use Carbon\Carbon;
use Cms\Core\Console\Commands\WorkflowRunCommand;
use Cms\Core\Console\Commands\WorkflowRunScheduledCommand;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Run an authored workflow project by hand
 * (admin API, artisan) or on its cron schedule, never twice at the same time.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
    Queue::fake();

    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    makeWorkflowRunUser('placeholder');

    // The commands are registered by CoreServiceProvider; register them here too
    // so this test does not depend on that list.
    $artisan = Artisan::all();
    foreach ([WorkflowRunCommand::class, WorkflowRunScheduledCommand::class] as $class) {
        $command = app($class);
        if (! isset($artisan[$command->getName()])) {
            app(ConsoleKernel::class)->registerCommand($command);
        }
    }
});

function makeWorkflowRunUser(string $name, array $permissions = []): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $permission = Permission::query()->firstOrCreate(
                ['group' => $group, 'name' => $ability],
                ['title' => $key],
            );
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

function makeWorkflowRunProject(array $attributes = []): WorkflowProject
{
    // Runs execute published versions only (guardrails B1).
    return tap(WorkflowProject::query()->forceCreate(array_merge([
        'name' => 'Church stories',
        'slug' => 'church-stories-' . Str::lower(Str::random(5)),
        'trigger_event' => 'manual',
        'target_entity_type' => 'none',
        'is_active' => true,
    ], $attributes)), static fn (WorkflowProject $project) => \Cms\Core\Tests\Support\WorkflowTestVersions::publish($project))->fresh();
}

function workflowEditor(): User
{
    return makeWorkflowRunUser('editor', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.run']);
}

test('manual run creates one instance acting for the caller and dispatches the job', function () {
    $editor = workflowEditor();
    $project = makeWorkflowRunProject();
    $this->actingAs($editor, 'sanctum');

    $response = $this->postJson("/api/v2/admin/workflow/projects/{$project->uuid}/run");

    $response->assertStatus(202)
        ->assertJsonPath('data.instance.status', 'pending')
        ->assertJsonPath('data.instance.source', 'manual');

    $instances = WorkflowInstance::query()->where('project_id', $project->id)->get();
    expect($instances)->toHaveCount(1)
        ->and($instances[0]->delegated_by_user_id)->toBe($editor->id)
        ->and(data_get($instances[0]->context_snapshot, 'trigger.source'))->toBe('manual');

    Queue::assertPushed(RunWorkflowInstanceJob::class, fn (RunWorkflowInstanceJob $job) => $job->workflowInstanceId() === $instances[0]->id);
});

test('an inactive project answers 422 and starts nothing', function () {
    $this->actingAs(workflowEditor(), 'sanctum');
    $project = makeWorkflowRunProject(['is_active' => false]);

    $this->postJson("/api/v2/admin/workflow/projects/{$project->uuid}/run")
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'project_inactive');

    expect(WorkflowInstance::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a project with a run in progress answers 409', function () {
    $this->actingAs(workflowEditor(), 'sanctum');
    $project = makeWorkflowRunProject();
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'running']);

    $this->postJson("/api/v2/admin/workflow/projects/{$project->uuid}/run")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'workflow_already_running');

    expect(WorkflowInstance::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a system-managed schedule wrapper can not be run or scheduled here', function () {
    $this->actingAs(workflowEditor(), 'sanctum');
    $project = makeWorkflowRunProject(['tags' => ['system-managed', 'schedule-wrapper']]);

    $this->postJson("/api/v2/admin/workflow/projects/{$project->uuid}/run")->assertStatus(422);
    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => '0 8 * * *'])->assertStatus(422);
    expect(WorkflowInstance::query()->count())->toBe(0);
});

test('run and schedule need the update permission, the run list needs view', function () {
    $project = makeWorkflowRunProject();
    $viewer = makeWorkflowRunUser('viewer', ['workflow_project.viewAny']);
    $this->actingAs($viewer, 'sanctum');

    $this->postJson("/api/v2/admin/workflow/projects/{$project->uuid}/run")->assertStatus(403);
    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => '0 8 * * *'])->assertStatus(403);
    $this->getJson("/api/v2/admin/workflow/projects/{$project->uuid}/instances")->assertOk();

    $this->actingAs(makeWorkflowRunUser('nobody'), 'sanctum');
    $this->getJson("/api/v2/admin/workflow/projects/{$project->uuid}/instances")->assertStatus(403);

    expect(WorkflowInstance::query()->count())->toBe(0)
        ->and($project->fresh()->schedule_cron)->toBeNull();
});

test('unknown project answers 404', function () {
    $this->actingAs(workflowEditor(), 'sanctum');

    $this->postJson('/api/v2/admin/workflow/projects/999999/run')->assertStatus(404);
});

test('schedule saves a valid cron for the caller, refuses an invalid one and null turns it off', function () {
    $editor = workflowEditor();
    $this->actingAs($editor, 'sanctum');
    $project = makeWorkflowRunProject();

    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => 'every day', 'timezone' => null])
        ->assertStatus(422);
    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => '0 8 * * *', 'timezone' => 'Mars/Base'])
        ->assertStatus(422);
    expect($project->fresh()->schedule_cron)->toBeNull();

    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => '0 8 * * *', 'timezone' => 'Asia/Ho_Chi_Minh'])
        ->assertOk()
        ->assertJsonPath('data.schedule.cron', '0 8 * * *')
        ->assertJsonPath('data.schedule.timezone', 'Asia/Ho_Chi_Minh')
        ->assertJsonPath('data.schedule.scheduled_by.uuid', $editor->uuid);

    expect($project->fresh()->scheduled_by_user_id)->toBe($editor->id);

    $this->putJson("/api/v2/admin/workflow/projects/{$project->uuid}/schedule", ['cron' => null])
        ->assertOk()
        ->assertJsonPath('data.schedule.cron', null);

    expect($project->fresh()->schedule_cron)->toBeNull()
        ->and($project->fresh()->scheduled_by_user_id)->toBeNull();
});

test('the run list is paginated with status, source and duration', function () {
    $this->actingAs(workflowEditor(), 'sanctum');
    $project = makeWorkflowRunProject();
    $other = makeWorkflowRunProject();
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
        'target_type' => WorkflowProject::class,
        'project_id' => $project->id,
        'status' => 'completed',
        'context_snapshot' => ['trigger' => ['source' => 'schedule']],
        'started_at' => '2026-11-24 08:00:00',
        'finished_at' => '2026-11-24 08:00:02',
    ]);
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'awaiting_approval']);
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $other->id, 'status' => 'completed']);

    $response = $this->getJson("/api/v2/admin/workflow/projects/{$project->uuid}/instances?per_page=1");

    $response->assertOk()
        ->assertJsonPath('data.meta.total', 2)
        ->assertJsonCount(1, 'data.data');

    $all = $this->getJson("/api/v2/admin/workflow/projects/{$project->uuid}/instances")->json('data.data');
    $completed = collect($all)->firstWhere('status', 'completed');
    expect($completed['source'])->toBe('schedule')
        ->and($completed['duration_ms'])->toBe(2000);
});

test('artisan cms:workflow-run starts a project by slug for the given user', function () {
    $editor = workflowEditor();
    $project = makeWorkflowRunProject(['slug' => 'church-stories']);

    $this->artisan('cms:workflow-run', ['slug' => 'church-stories', '--user' => $editor->uuid])->assertSuccessful();

    $instance = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($instance->delegated_by_user_id)->toBe($editor->id)
        ->and(data_get($instance->context_snapshot, 'trigger.source'))->toBe('cli');

    $this->artisan('cms:workflow-run', ['slug' => 'church-stories', '--user' => $editor->uuid])->assertFailed();
    $this->artisan('cms:workflow-run', ['slug' => 'missing', '--user' => $editor->uuid])->assertFailed();
});

test('scheduled command starts a due project once per minute and records the time', function () {
    config(['core.workflow_schedule.enabled' => true]);
    $editor = workflowEditor();
    $project = makeWorkflowRunProject([
        'schedule_cron' => '0 8 * * *',
        'schedule_timezone' => 'Asia/Ho_Chi_Minh',
        'scheduled_by_user_id' => $editor->id,
    ]);
    $notDue = makeWorkflowRunProject([
        'schedule_cron' => '30 9 * * *',
        'schedule_timezone' => 'Asia/Ho_Chi_Minh',
        'scheduled_by_user_id' => $editor->id,
    ]);

    // 08:00:20 in Ho Chi Minh City.
    $this->travelTo(Carbon::parse('2026-11-24 01:00:20', 'UTC'));
    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    $instance = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($instance->delegated_by_user_id)->toBe($editor->id)
        ->and(data_get($instance->context_snapshot, 'trigger.source'))->toBe('schedule')
        ->and(\Illuminate\Support\Facades\DB::table('workflow_projects')->where('id', $project->id)->value('last_scheduled_run_at'))
            ->toBe(Carbon::parse('2026-11-24 01:00:20', 'UTC')->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s'))
        ->and(WorkflowInstance::query()->where('project_id', $notDue->id)->count())->toBe(0);

    // Same minute again, with the first run already finished: still no second run.
    $instance->update(['status' => 'completed']);
    $this->travelTo(Carbon::parse('2026-11-24 01:00:50', 'UTC'));
    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();
    expect(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1);

    Queue::assertPushed(RunWorkflowInstanceJob::class, 1);
});

test('scheduled command skips a project whose previous run is still running', function () {
    config(['core.workflow_schedule.enabled' => true]);
    $editor = workflowEditor();
    $project = makeWorkflowRunProject([
        'schedule_cron' => '0 8 * * *',
        'schedule_timezone' => 'UTC',
        'scheduled_by_user_id' => $editor->id,
    ]);
    // A live run (recent activity): stale runs are failed by the same command, see WorkflowStaleRunningTest.
    $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'running']);

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and($project->fresh()->last_scheduled_run_at)->toBeNull();
    Queue::assertNothingPushed();
});

test('scheduled command is not blocked by a run awaiting approval', function () {
    config(['core.workflow_schedule.enabled' => true]);
    $editor = workflowEditor();
    $project = makeWorkflowRunProject([
        'schedule_cron' => '0 8 * * *',
        'schedule_timezone' => 'UTC',
        'scheduled_by_user_id' => $editor->id,
    ]);
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'awaiting_approval']);

    $this->travelTo(Carbon::parse('2026-11-25 08:00:00', 'UTC'));
    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(2);
    Queue::assertPushed(RunWorkflowInstanceJob::class, 1);
});

test('scheduled command does nothing while the scheduler is disabled', function () {
    config(['core.workflow_schedule.enabled' => false]);
    $editor = workflowEditor();
    makeWorkflowRunProject([
        'schedule_cron' => '* * * * *',
        'scheduled_by_user_id' => $editor->id,
    ]);

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect(WorkflowInstance::query()->count())->toBe(0);
});

test('scheduled command skips inactive projects and schedulers who lost the update permission', function () {
    config(['core.workflow_schedule.enabled' => true]);
    makeWorkflowRunProject([
        'is_active' => false,
        'schedule_cron' => '* * * * *',
        'scheduled_by_user_id' => workflowEditor()->id,
    ]);
    makeWorkflowRunProject([
        'schedule_cron' => '* * * * *',
        'scheduled_by_user_id' => makeWorkflowRunUser('former-editor', ['workflow_project.viewAny'])->id,
    ]);

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect(WorkflowInstance::query()->count())->toBe(0);
});

test('the run list keeps the source after the executor rewrote the trigger as {event, at, meta}', function () {
    $this->actingAs(workflowEditor(), 'sanctum');
    $project = makeWorkflowRunProject();
    \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
        'target_type' => WorkflowProject::class,
        'project_id' => $project->id,
        'status' => 'completed',
        'context_snapshot' => ['trigger' => ['event' => 'workflow.project.run', 'at' => '2026-11-24T08:00:00+00:00', 'meta' => ['source' => 'webhook', 'chain' => [$project->id]]]],
    ]);

    $runs = $this->getJson("/api/v2/admin/workflow/projects/{$project->uuid}/instances")->assertOk()->json('data.data');
    expect($runs[0]['source'])->toBe('webhook');
});
