<?php

use App\Models\User;
use Carbon\Carbon;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Scheduled runs act only for a scheduler who may still run the project, and a schedule stops when someone else
 * changes what it would run.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['core.workflow_schedule.enabled' => true]);

    // The users.status foreign key points at these `user_status` rows.
    foreach (\App\Enums\UserStatusEnum::cases() as $case) {
        \Illuminate\Support\Facades\DB::table('enumerates')->insertOrIgnore([
            'name' => $case->value,
            'enum_key' => $case->value,
            'type' => 'user_status',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    scheduleOwnershipUser('placeholder');
});

function scheduleOwnershipUser(string $name, array $permissions = [], array $attributes = []): User
{
    $user = User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ], $attributes));

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->firstOrCreate(
                ['group' => $group, 'name' => $ability],
                ['title' => $key],
            ));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

function scheduledProject(User $scheduler, array $attributes = []): WorkflowProject
{
    // Runs execute published versions only (guardrails B1): the schedule is published with the graph.
    return tap(WorkflowProject::query()->forceCreate(array_merge([
        'name' => 'Church stories',
        'slug' => 'church-stories-' . Str::lower(Str::random(5)),
        'trigger_event' => 'manual',
        'target_entity_type' => 'none',
        'is_active' => true,
        'schedule_cron' => '* * * * *',
        'schedule_timezone' => 'UTC',
        'scheduled_by_user_id' => $scheduler->id,
    ], $attributes)), static fn (WorkflowProject $project) => \Cms\Core\Tests\Support\WorkflowTestVersions::publish($project))->fresh();
}

function scheduleStep(WorkflowProject $project, string $code): WorkflowStep
{
    return WorkflowStep::query()->create([
        'project_id' => $project->id,
        'step_code' => $code,
        'name' => $code,
        'step_type' => 'log_message',
        'step_config' => ['message' => 'hi'],
    ]);
}

test('the scheduler runs while active, admin-eligible and holding the update permission', function () {
    $editor = scheduleOwnershipUser('editor', ['workflow_project.update']);
    $project = scheduledProject($editor);

    $result = app(WorkflowProjectRunService::class)->runDue();

    expect($result['started'])->toBe([$project->id]);
});

test('a scheduler who lost the right to run is skipped', function (string $case) {
    $editor = match ($case) {
        'banned' => scheduleOwnershipUser('editor', ['workflow_project.update'], ['status' => 'user_banned']),
        'inactive' => scheduleOwnershipUser('editor', ['workflow_project.update'], ['status' => 'user_inactive']),
        'not admin' => scheduleOwnershipUser('editor', ['workflow_project.update'], ['type' => 3]),
        'client' => scheduleOwnershipUser('editor', ['workflow_project.update'], ['type' => 2]),
        'owner without permission' => scheduleOwnershipUser('editor', ['workflow_project.viewAny']),
        'deleted' => tap(scheduleOwnershipUser('editor', ['workflow_project.update']))->delete(),
    };
    $project = scheduledProject($editor);

    $result = app(WorkflowProjectRunService::class)->runDue();

    expect($result['started'])->toBe([])
        ->and($result['skipped'][$project->id] ?? null)->toBe('scheduler_not_allowed')
        ->and(WorkflowInstance::query()->count())->toBe(0);
})->with(['banned', 'inactive', 'not admin', 'client', 'owner without permission', 'deleted']);

test('another user changing the graph clears the schedule', function (string $change) {
    $editor = scheduleOwnershipUser('editor', ['workflow_project.update']);
    $project = scheduledProject($editor);
    $first = scheduleStep($project, 'a');
    $second = scheduleStep($project, 'b');

    $this->actingAs(scheduleOwnershipUser('other', ['workflow_project.update']));

    match ($change) {
        'step created' => scheduleStep($project, 'c'),
        'step updated' => $first->update(['step_config' => ['message' => 'changed']]),
        'step deleted' => $first->delete(),
        'connection created' => WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $first->id, 'to_step_id' => $second->id,
        ]),
    };

    $fresh = $project->fresh();
    expect($fresh->schedule_cron)->toBeNull()
        ->and($fresh->scheduled_by_user_id)->toBeNull()
        ->and(app(WorkflowProjectRunService::class)->runDue()['started'])->toBe([]);
})->with(['step created', 'step updated', 'step deleted', 'connection created']);

test('the scheduler changing their own graph keeps the schedule', function () {
    $editor = scheduleOwnershipUser('editor', ['workflow_project.update']);
    $project = scheduledProject($editor);
    $this->actingAs($editor);

    scheduleStep($project, 'a')->update(['step_config' => ['message' => 'changed']]);

    expect($project->fresh()->schedule_cron)->toBe('* * * * *')
        ->and($project->fresh()->scheduled_by_user_id)->toBe($editor->id);
});

test('another user turning the project on clears the schedule', function () {
    $editor = scheduleOwnershipUser('editor', ['workflow_project.update']);
    $project = scheduledProject($editor, ['is_active' => false]);

    $this->actingAs(scheduleOwnershipUser('other', ['workflow_project.update']));
    $project->update(['is_active' => true]);

    $fresh = $project->fresh();
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->schedule_cron)->toBeNull()
        ->and($fresh->scheduled_by_user_id)->toBeNull();
});

test('a scheduled run claims its minute inside the project lock', function () {
    $editor = scheduleOwnershipUser('editor', ['workflow_project.update']);
    $project = scheduledProject($editor);
    $this->travelTo(Carbon::parse('2026-11-24 08:00:10', 'UTC'));

    // A second scheduler process already started this minute's run, after this one read the project list.
    $service = new class extends WorkflowProjectRunService {
        public function start(WorkflowProject $project, User $actor, array $trigger = [], string $source = 'manual', ?\Carbon\CarbonInterface $scheduledAt = null): WorkflowInstance
        {
            \Illuminate\Support\Facades\DB::table('workflow_projects')->where('id', $project->id)
                ->update(['last_scheduled_run_at' => $scheduledAt?->format('Y-m-d H:i:s')]);

            return parent::start($project, $actor, $trigger, $source, $scheduledAt);
        }
    };

    $result = $service->runDue();

    expect($result['started'])->toBe([])
        ->and(WorkflowInstance::query()->count())->toBe(0);
});

test('the local environment no longer bypasses the workflow project policy', function () {
    $this->app['env'] = 'local';
    $stranger = scheduleOwnershipUser('stranger');
    $project = scheduledProject(scheduleOwnershipUser('editor', ['workflow_project.update']));

    expect(Gate::forUser($stranger)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', WorkflowProject::class))->toBeFalse();
});
