<?php

use App\Models\User;
use Carbon\Carbon;
use Cms\Core\Events\WorkflowIntegrityViolated;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Exceptions\WorkflowPublishRefused;
use Cms\Core\Services\Workflow\Triggers\SubWorkflowService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Guardrails B1/B2/B4: runs execute a published, sealed version; the draft never reaches a run; tampering refuses the
 * run and pauses the project; publish enforces the server policy. The test queue is `sync`. Change control (B3) has
 * its own tests (WorkflowChangeControlTest); service publishes here run as a server operator (asOperator()).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
    if (DB::getDriverName() === 'sqlite') {
        // See WorkflowTriggersTest: the SQLite step FK still points at workflow_instances.
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    TriggerChain::reset();
    versioningUser('placeholder'); // user id 1 is always a webmaster
});

function versioningUser(string $name = 'editor', array $permissions = ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.update', 'workflow_project.create', 'workflow_project.publish', 'workflow_project.run']): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);

    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
    foreach ($permissions as $key) {
        [$group, $ability] = explode('.', $key, 2);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
    }
    $user->roles()->attach($role);

    return $user->fresh();
}

/**
 * A project whose graph is the given [code => [type, config]] steps chained in order (the first is the start node).
 *
 * @return array{0: WorkflowProject, 1: array<string, WorkflowStep>}
 */
function versioningProject(array $steps, array $attributes = []): array
{
    $project = WorkflowProject::query()->forceCreate(array_merge([
        'name' => 'V ' . Str::random(4),
        'slug' => 'v-' . Str::lower(Str::random(6)),
        'trigger_event' => 'manual',
        'target_entity_type' => 'none',
        'is_active' => true,
    ], $attributes));

    $models = [];
    $previous = null;
    $i = 0;
    foreach ($steps as $code => [$type, $config]) {
        $models[$code] = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_code' => $code, 'name' => $code, 'step_type' => $type,
            'step_config' => $config, 'position_x' => $i * 100, 'position_y' => 0, 'is_start' => $i === 0,
        ]);
        if ($previous) {
            WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $previous->id, 'to_step_id' => $models[$code]->id]);
        }
        $previous = $models[$code];
        $i++;
    }

    return [$project->fresh(), $models];
}

function setVar(string $value): array
{
    return ['assignments' => [['key' => 'vars.marker', 'valueExpr' => "'" . $value . "'"]]];
}

it('runs the published version, not a draft edited after publishing', function () {
    $editor = versioningUser();
    [$project, $steps] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $version = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor, 'first');

    // Draft edit after publishing: a new value and an extra step.
    $steps['mark']->update(['step_config' => setVar('draft')]);

    $instance = app(WorkflowProjectRunService::class)->start($project->fresh(), $editor)->refresh();

    expect($version->number)->toBe(1)
        ->and($instance->version_id)->toBe($version->id)
        ->and($instance->status)->toBe('completed')
        ->and(data_get($instance->context_snapshot, 'vars.marker'))->toBe('v1')
        // Step executions keep the draft row ids (FK to workflow_steps).
        ->and(WorkflowInstanceStep::query()->where('instance_id', $instance->id)->whereNotNull('step_id')->pluck('step_id')->all())
        ->toContain($steps['mark']->id);

    $v2 = app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $editor);
    $second = app(WorkflowProjectRunService::class)->start($project->fresh(), $editor)->refresh();

    expect($v2->number)->toBe(2)
        ->and($version->fresh()->status)->toBe('superseded')
        ->and($project->fresh()->published_version_id)->toBe($v2->id)
        ->and(data_get($second->context_snapshot, 'vars.marker'))->toBe('draft');
});

it('keeps a paused run on its version when a new version is published', function () {
    $editor = versioningUser();
    [$project, $steps] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $v1 = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);

    // A run that stopped before `mark` (e.g. waiting for an approval) and resumes later.
    $instance = WorkflowInstance::query()->create([
        'project_id' => $project->id, 'version_id' => $v1->id, 'target_type' => WorkflowProject::class,
        'status' => 'waiting', 'resume_step_id' => $steps['mark']->id,
        'context_snapshot' => ['trigger' => [], 'vars' => []],
    ]);

    $steps['mark']->update(['step_config' => setVar('v2')]);
    app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $editor);

    app(WorkflowGraphExecutor::class)->execute($instance->id);

    expect($instance->refresh()->status)->toBe('completed')
        ->and($instance->version_id)->toBe($v1->id)
        ->and(data_get($instance->context_snapshot, 'vars.marker'))->toBe('v1');
});

it('refuses a run without a published version and refuses turning the project on', function () {
    $editor = versioningUser();
    [$project] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);

    expect(fn () => app(WorkflowProjectRunService::class)->start($project, $editor))
        ->toThrow(WorkflowProjectNotPublished::class);

    $project->forceFill(['is_active' => false])->save();
    $this->actingAs($editor)
        ->putJson("/api/v2/admin/workflows/{$project->id}", ['name' => $project->name, 'is_active' => true])
        ->assertJsonPath('error_code', WorkflowPublishService::NOT_PUBLISHED_CODE);
    expect($project->fresh()->is_active)->toBeFalse();

    // An instance without a version is refused by the executor (fail closed).
    $orphan = WorkflowInstance::query()->create(['project_id' => $project->id, 'target_type' => WorkflowProject::class, 'status' => 'pending']);
    $project->forceFill(['is_active' => true])->save();
    app(WorkflowGraphExecutor::class)->execute($orphan->id);
    expect($orphan->refresh()->status)->toBe('failed')
        ->and(WorkflowInstanceStep::query()->where('instance_id', $orphan->id)->value('error_message'))->toBe('version_missing');
});

it('refuses a run and pauses the project when the stored graph or seal was changed in the database', function (string $column) {
    Event::fake([WorkflowIntegrityViolated::class]);
    $editor = versioningUser();
    [$project] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $version = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);

    $tampered = $column === 'graph'
        ? str_replace("'v1'", "'evil'", $version->getRawOriginal('graph'))
        : str_repeat('0', 64);
    DB::table('workflow_versions')->where('id', $version->id)->update([$column => $tampered]);

    expect(fn () => app(WorkflowProjectRunService::class)->start($project->fresh(), $editor))
        ->toThrow(WorkflowIntegrityException::class);

    $project->refresh();
    expect($project->is_active)->toBeFalse()
        ->and($project->paused_reason)->toBe('integrity')
        ->and($project->paused_at)->not->toBeNull()
        ->and(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(0);
    Event::assertDispatched(WorkflowIntegrityViolated::class, fn ($e) => $e->projectId === $project->id
        && $e->versionId === $version->id && $e->reason === ($column === 'graph' ? 'hash_mismatch' : 'seal_mismatch'));
})->with(['graph', 'seal']);

it('fails a resumed run whose version was tampered with', function () {
    Event::fake([WorkflowIntegrityViolated::class]);
    $editor = versioningUser();
    [$project, $steps] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $version = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);
    $instance = WorkflowInstance::query()->create([
        'project_id' => $project->id, 'version_id' => $version->id, 'target_type' => WorkflowProject::class,
        'status' => 'waiting', 'resume_step_id' => $steps['mark']->id, 'context_snapshot' => ['trigger' => [], 'vars' => []],
    ]);

    // Content and hash changed together: the seal still catches it.
    $graph = str_replace("'v1'", "'evil'", $version->getRawOriginal('graph'));
    DB::table('workflow_versions')->where('id', $version->id)->update(['graph' => $graph, 'content_hash' => hash('sha256', $graph)]);

    app(WorkflowGraphExecutor::class)->execute($instance->id);

    expect($instance->refresh()->status)->toBe('failed')
        ->and(data_get($instance->context_snapshot, 'vars.marker'))->toBeNull()
        ->and(WorkflowInstanceStep::query()->where('instance_id', $instance->id)->value('error_message'))->toBe('integrity_mismatch')
        ->and($project->fresh()->paused_reason)->toBe('integrity');
    Event::assertDispatched(WorkflowIntegrityViolated::class, fn ($e) => $e->instanceId === $instance->id && $e->reason === 'seal_mismatch');
});

it('finds tampered versions in the nightly integrity check', function () {
    Event::fake([WorkflowIntegrityViolated::class]);
    $editor = versioningUser();
    [$good] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('ok')]]);
    [$bad] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    app(WorkflowPublishService::class)->asOperator()->publish($good, $editor);
    $version = app(WorkflowPublishService::class)->asOperator()->publish($bad, $editor);
    $waiting = WorkflowInstance::query()->create([
        'project_id' => $bad->id, 'version_id' => $version->id, 'target_type' => WorkflowProject::class, 'status' => 'awaiting_approval',
    ]);

    expect(Artisan::call('cms:workflow-integrity-check'))->toBe(0);

    DB::table('workflow_versions')->where('id', $version->id)->update(['seal' => str_repeat('a', 64)]);

    expect(Artisan::call('cms:workflow-integrity-check'))->toBe(1)
        ->and($bad->fresh()->is_active)->toBeFalse()
        ->and($bad->fresh()->paused_reason)->toBe('integrity')
        ->and($good->fresh()->is_active)->toBeTrue()
        ->and($waiting->fresh()->status)->toBe('failed');
    Event::assertDispatched(WorkflowIntegrityViolated::class, fn ($e) => $e->projectId === $bad->id && $e->detected === 'integrity_check');
});

it('re-seals versions after an APP_KEY rotation only when the old key made the seal', function () {
    $editor = versioningUser();
    [$project] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $version = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);
    $oldKey = (string) config('app.key');
    config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

    expect(WorkflowVersionSeal::verify($version->fresh()))->toBeFalse()
        ->and(Artisan::call('cms:workflow-reseal'))->toBe(1)       // refused without --force
        ->and(Artisan::call('cms:workflow-reseal', ['--force' => true]))->toBe(1) // old key unknown: skipped
        ->and(WorkflowVersionSeal::verify($version->fresh()))->toBeFalse()
        ->and(Artisan::call('cms:workflow-reseal', ['--force' => true, '--previous-key' => [$oldKey]]))->toBe(0)
        ->and(WorkflowVersionSeal::verify($version->fresh()))->toBeTrue();
});

it('rolls back by publishing a new version equal to the target', function () {
    $editor = versioningUser();
    [$project, $steps] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $v1 = app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);
    $steps['mark']->update(['step_config' => setVar('v2')]);
    $v2 = app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $editor);

    $v3 = app(WorkflowPublishService::class)->asOperator()->rollback($project->fresh(), $v1, $editor);

    expect($v3->number)->toBe(3)
        ->and($v3->id)->not->toBe($v1->id)
        ->and($v3->getRawOriginal('graph'))->toBe($v1->getRawOriginal('graph'))
        ->and($v3->content_hash)->toBe($v1->content_hash)
        ->and(WorkflowVersionSeal::verify($v3))->toBeTrue()
        ->and($v1->fresh()->status)->toBe('superseded')
        ->and($v2->fresh()->status)->toBe('superseded')
        ->and($project->fresh()->published_version_id)->toBe($v3->id);

    $run = app(WorkflowProjectRunService::class)->start($project->fresh(), $editor)->refresh();
    expect(data_get($run->context_snapshot, 'vars.marker'))->toBe('v1');
});

it('refuses to publish what the server policy blocks', function (array $steps, string $code, array $edges = null) {
    $editor = versioningUser();
    [$project] = versioningProject($steps);

    try {
        app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);
        $this->fail('Publishing should have been refused.');
    } catch (WorkflowPublishRefused $e) {
        expect(array_column($e->problems, 'code'))->toContain($code);
    }
    expect(WorkflowVersion::query()->where('project_id', $project->id)->count())->toBe(0);
})->with([
    'tainted write' => [[
        'start' => ['trigger_manual', []],
        'ai' => ['ai_prompt', ['instructions' => 'Write a name', 'storeAs' => 'vars.ai']],
        'write' => ['change', ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'vars.ai.text']]]],
    ], 'taint_needs_approval'],
    'host not allowlisted' => [[
        'start' => ['trigger_manual', []],
        'call' => ['http_request', ['method' => 'GET', 'url' => 'https://evil.example.net/collect', 'storeAs' => 'vars.r']],
    ], 'host_not_allowlisted'],
    'publish without approval' => [[
        'start' => ['trigger_manual', []],
        'post' => ['facebook_page_post', ['credential' => '$bind:fb', 'kind' => 'text', 'message' => "'Hello'"]],
    ], 'publish_needs_approval'],
]);

it('lets a publish step through when an approval guards it or a Webmaster exempted it', function () {
    $editor = versioningUser();
    $post = ['facebook_page_post', ['credential' => '$bind:fb', 'kind' => 'text', 'message' => "'Hello'"]];
    $codes = function (WorkflowProject $project) use ($editor): array {
        try {
            app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $editor);

            return [];
        } catch (WorkflowPublishRefused $e) {
            return array_column($e->problems, 'code');
        }
    };

    [$guarded] = versioningProject(['start' => ['trigger_manual', []], 'ok' => ['approval', ['mode' => 'human']], 'post' => $post]);
    expect($codes($guarded))->not->toContain('publish_needs_approval');

    [$exempt] = versioningProject(['start' => ['trigger_manual', []], 'post' => $post]);
    expect($codes($exempt))->toContain('publish_needs_approval');
    $exempt->forceFill(['policy_exemptions' => [['step_code' => 'post', 'by' => 1, 'at' => now()->toISOString(), 'reason' => 'Own page']]])->save();
    expect($codes($exempt))->not->toContain('publish_needs_approval');
});

it('allows a host on the allowlist', function () {
    config(['core.workflow_security.http_hosts' => ['api.example.org']]);
    $editor = versioningUser();
    [$project] = versioningProject([
        'start' => ['trigger_manual', []],
        'call' => ['http_request', ['method' => 'GET', 'url' => 'https://api.example.org/items', 'storeAs' => 'vars.r']],
    ]);

    $codes = [];
    try {
        app(WorkflowPublishService::class)->asOperator()->publish($project, $editor);
    } catch (WorkflowPublishRefused $e) {
        $codes = array_column($e->problems, 'code');
    }
    expect($codes)->not->toContain('host_not_allowlisted');
});

it('starts webhook, schedule and sub-workflow runs on the published version', function () {
    $editor = versioningUser();
    $service = app(WorkflowProjectRunService::class);

    // Schedule: the cron comes from the published version, not from a later draft change.
    [$scheduled] = versioningProject(['start' => ['trigger_schedule', ['cron' => '* * * * *']], 'mark' => ['set_variable', setVar('s')]]);
    $scheduled->forceFill(['scheduled_by_user_id' => $editor->id])->save();
    $sv = app(WorkflowPublishService::class)->asOperator()->publish($scheduled->fresh(), $editor);
    expect($sv->graphData()['settings']['schedule_cron'])->toBe('* * * * *');
    $scheduled->forceFill(['schedule_cron' => null])->save(); // draft change only
    $result = $service->runDue(Carbon::parse('2026-11-24 10:00:00'));
    expect($result['started'])->toBe([$scheduled->id])
        ->and(WorkflowInstance::query()->where('project_id', $scheduled->id)->value('version_id'))->toBe($sv->id);

    // Webhook.
    [$hooked] = versioningProject(['start' => ['trigger_webhook', ['auth' => 'token']], 'mark' => ['set_variable', setVar('w')]]);
    $hv = app(WorkflowPublishService::class)->asOperator()->publish($hooked, $editor);
    $hooked->forceFill(['scheduled_by_user_id' => $editor->id, 'webhook_token' => $token = Str::random(48), 'webhook_secret' => $secret = Str::random(48)])->save();
    $this->call("POST", "/api/v2/workflow/webhooks/{$token}", [], [], [], ["CONTENT_TYPE" => "application/json", "HTTP_X_WORKFLOW_TOKEN" => $secret], "{\"a\":1}")->assertStatus(202);
    expect(WorkflowInstance::query()->where('project_id', $hooked->id)->value('version_id'))->toBe($hv->id);

    // Sub-workflow.
    [$child] = versioningProject(['start' => ['trigger_sub_workflow', []], 'mark' => ['set_variable', setVar('c')]]);
    $cv = app(WorkflowPublishService::class)->asOperator()->publish($child, $editor);
    [$parent] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('p')]]);
    app(WorkflowPublishService::class)->asOperator()->publish($parent, $editor);
    $parentRun = $service->start($parent->fresh(), $editor)->refresh();
    $childRun = app(SubWorkflowService::class)->start($parentRun, $child->fresh(), [], 'fire', 'vars.child');
    expect($childRun->version_id)->toBe($cv->id);
});

it('serves the version API: publish, list, diff, draft status and rollback', function () {
    $editor = versioningUser();
    [$project, $steps] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('v1')]]);
    $this->actingAs($editor);

    $this->getJson("/api/v2/admin/workflows/{$project->id}/versions/draft-status")->assertJsonPath('data.differs', true);

    $v1 = $this->postJson("/api/v2/admin/workflows/{$project->id}/versions/publish", ['note' => 'First'])
        ->assertStatus(201)->assertJsonPath('data.number', 1)->json('data.uuid');
    $this->getJson("/api/v2/admin/workflows/{$project->id}/versions/draft-status")->assertJsonPath('data.differs', false);

    $steps['mark']->update(['name' => 'Mark it', 'step_config' => setVar('v2')]);
    $diff = $this->getJson("/api/v2/admin/workflows/{$project->id}/versions/{$v1}/diff?against=draft")->json('data.diff');
    expect($diff['steps']['changed'][0]['step_code'])->toBe('mark')
        ->and($diff['steps']['changed'][0]['fields'])->toBe(['name'])
        ->and($diff['steps']['changed'][0]['config_keys'])->toBe(['assignments'])
        ->and(json_encode($diff))->not->toContain('v2');

    $this->postJson("/api/v2/admin/workflows/{$project->id}/versions/publish")->assertStatus(201)->assertJsonPath('data.number', 2);
    $this->getJson("/api/v2/admin/workflows/{$project->id}/versions")
        ->assertJsonPath('data.versions.0.number', 2)->assertJsonPath('data.versions.0.is_published', true)
        ->assertJsonPath('data.versions.1.status', 'superseded');
    $this->getJson("/api/v2/admin/workflows/{$project->id}/versions/{$v1}")->assertJsonPath('data.graph.steps.1.step_code', 'start')
        ->assertJsonMissingPath('data.seal');

    $this->postJson("/api/v2/admin/workflows/{$project->id}/versions/{$v1}/rollback")->assertStatus(201)->assertJsonPath('data.number', 3);

    // Refused publish carries the problems.
    WorkflowStep::query()->create(['project_id' => $project->id, 'step_code' => 'call', 'name' => 'call', 'step_type' => 'http_request',
        'step_config' => ['method' => 'GET', 'url' => 'https://evil.example.net/x', 'storeAs' => 'vars.r']]);
    $this->postJson("/api/v2/admin/workflows/{$project->id}/versions/publish")->assertStatus(422)
        ->assertJsonPath('error_code', WorkflowPublishRefused::CODE)
        ->assertJsonPath('data.problems.0.code', 'host_not_allowlisted');

    // Someone without publish may read but not publish.
    $viewer = versioningUser('viewer', ['workflow_project.viewAny', 'workflow_project.view']);
    $this->actingAs($viewer)->postJson("/api/v2/admin/workflows/{$project->id}/versions/publish")->assertStatus(403);
});

it('keeps the snapshot JSON identical to what the migration backfill writes', function () {
    [$project] = versioningProject(['start' => ['trigger_manual', []], 'mark' => ['set_variable', setVar('é')]], ['budget_max_cost_per_run' => 2.5]);

    expect(WorkflowVersionSnapshot::encode(WorkflowVersionSnapshot::fromDraft($project)))
        ->toBe(WorkflowVersionSnapshot::encode(WorkflowVersionSnapshot::fromDatabase($project->id)))
        ->and(RunGraph::hydrate(WorkflowVersionSnapshot::fromDraft($project), $project->id)['steps']->pluck('step_code')->all())
        ->toBe(['mark', 'start']);
});

it('clears the schedule when someone other than its scheduler puts a new version live', function () {
    $scheduler = versioningUser('scheduler');
    $other = versioningUser('other');
    [$project] = versioningProject(['start' => ['trigger_schedule', ['cron' => '* * * * *']], 'mark' => ['set_variable', setVar('s')]]);
    $project->forceFill(['scheduled_by_user_id' => $scheduler->id, 'schedule_cron' => '* * * * *'])->save();

    app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $scheduler);
    expect($project->fresh()->scheduled_by_user_id)->toBe($scheduler->id);

    // An unchanged draft adds no version (B3), so the other user changes something first.
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'mark')->update(['name' => 'Mark it']);
    app(WorkflowPublishService::class)->asOperator()->publish($project->fresh(), $other);
    expect($project->fresh()->scheduled_by_user_id)->toBeNull()
        ->and(app(WorkflowProjectRunService::class)->runDue()['started'])->toBe([]);
});
