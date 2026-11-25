<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Workflow\Exceptions\WorkflowBreakerTripped;
use Cms\Core\Services\Workflow\Exceptions\WorkflowGloballyPaused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowStepUpRequired;
use Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker;
use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Versioning\RunGraph;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Services\Workflow\Versioning\WorkflowSensitiveFingerprint;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Tests\Support\WorkflowTestVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Guardrails B3: change control on sensitive behaviour changes (step-up, second person or cooling-off), the pending
 * versions' seal, and the lane-2 wiring at run start (global pause, breakers) and after runs.
 */

require_once __DIR__ . '/../Guard/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const CC_URL = 'https://localhost/api/v2/admin/workflows/';

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    config([
        'services.telegram.token' => '', 'services.telegram.chat_id' => '',
        'core.workflow_security.http_hosts' => ['api.example.org', 'api2.example.org'],
    ]);
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    TriggerChain::reset();
    // User id 1 is always a Webmaster: the "author" of single-publisher scenarios.
    $this->owner = wgUser('owner');
});

/** Sign $user in with a personal access token (step-up grants are bound to it). */
function ccLogin(User $user): ApiAccessToken
{
    $new = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ADMIN, 'dev', ['*']);
    $token = ApiAccessToken::query()->findOrFail($new->accessToken->getKey());
    Sanctum::actingAs($user, ['*']);
    app('auth')->guard('sanctum')->setUser($user->withAccessToken($token));

    return $token;
}

function ccGrant(User $user, ApiAccessToken $token): string
{
    return app(StepUpConfirmation::class)->start($user->fresh(), $token, 'secret123', StepUpConfirmation::PURPOSE_WORKFLOW_PUBLISH)['grant'];
}

/**
 * start → ok (approval) → write → ai → call: the base workflow of these tests, published as v1.
 */
function ccProject(array $attributes = []): WorkflowProject
{
    $project = WorkflowProject::query()->forceCreate(array_merge([
        'name' => 'CC ' . Str::random(4), 'slug' => 'cc-' . Str::lower(Str::random(6)),
        'trigger_event' => 'manual', 'target_entity_type' => 'none', 'is_active' => true,
        'budget_max_cost_per_run' => 1,
    ], $attributes));

    $steps = [
        'start' => ['trigger_manual', []],
        'ok' => ['approval', ['mode' => 'human']],
        'write' => ['change', ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => "'Fixed'"]]]],
        'ai' => ['ai_prompt', ['instructions' => 'Write a short greeting', 'storeAs' => 'vars.ai']],
        'call' => ['http_request', ['method' => 'GET', 'url' => 'https://api.example.org/items', 'storeAs' => 'vars.r']],
    ];
    $previous = null;
    $i = 0;
    foreach ($steps as $code => [$type, $config]) {
        $step = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_code' => $code, 'name' => $code, 'step_type' => $type,
            'step_config' => $config, 'position_x' => $i * 100, 'position_y' => 0, 'is_start' => $i === 0,
        ]);
        if ($previous) {
            WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $previous->id, 'to_step_id' => $step->id]);
        }
        $previous = $step;
        $i++;
    }

    WorkflowTestVersions::publish($project->fresh());

    return $project->fresh();
}

function ccStep(WorkflowProject $project, string $code): WorkflowStep
{
    return WorkflowStep::query()->where('project_id', $project->id)->where('step_code', $code)->firstOrFail();
}

function ccSetConfig(WorkflowProject $project, string $code, array $merge): void
{
    $step = ccStep($project, $code);
    $step->update(['step_config' => array_merge((array) $step->step_config, $merge)]);
}

/** Publisher B (holds `workflow_project.publish`): makes a site with two publishers. */
function ccSecondPublisher(): User
{
    return wgUser('publisher-b', ['workflow_project.publish', 'workflow_project.view', 'workflow_project.viewAny', 'workflow_project.update']);
}

it('publishes a prompt-only change at once, without step-up', function (): void {
    ccSecondPublisher();
    $project = ccProject();
    ccLogin($this->owner);
    ccSetConfig($project, 'ai', ['instructions' => 'Write a long greeting']);
    ccStep($project, 'write')->update(['name' => 'Renamed', 'position_x' => 999]);

    $this->postJson(CC_URL . $project->id . '/versions/publish', ['note' => 'wording'])
        ->assertStatus(201)->assertJsonPath('data.path', 'published')->assertJsonPath('data.number', 2);
    expect($project->fresh()->publishedVersion->number)->toBe(2);
    Notification::assertSentTo($this->owner, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.version_published');
});

it('reports an unchanged draft without adding a version', function (): void {
    $project = ccProject();
    ccLogin($this->owner);

    $this->getJson(CC_URL . $project->id . '/versions/preview-publish')
        ->assertOk()->assertJsonPath('data.unchanged', true)->assertJsonPath('data.sensitive', false);
    $this->postJson(CC_URL . $project->id . '/versions/publish')
        ->assertStatus(200)->assertJsonPath('data.path', 'unchanged')->assertJsonPath('data.number', 1);
    expect(WorkflowVersion::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('sends each kind of sensitive change to a second publisher', function (Closure $mutate, string $kind): void {
    $publisherB = ccSecondPublisher();
    $project = ccProject();
    $token = ccLogin($this->owner);
    $mutate($project);

    $this->getJson(CC_URL . $project->id . '/versions/preview-publish')
        ->assertOk()->assertJsonPath('data.sensitive', true)->assertJsonPath('data.path', 'pending_review')
        ->assertJsonPath('data.step_up_required', true);
    expect(collect($this->getJson(CC_URL . $project->id . '/versions/preview-publish')->json('data.changes'))->pluck('kind')->all())->toContain($kind);

    $response = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $token)])
        ->assertStatus(202)->assertJsonPath('data.path', 'pending_review')->assertJsonPath('data.status', 'pending_review');
    expect(collect($response->json('data.changes'))->pluck('kind')->all())->toContain($kind)
        ->and($project->fresh()->publishedVersion->number)->toBe(1);
    Notification::assertSentTo($publisherB, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.version_pending');
})->with([
    'a new external call' => [function (WorkflowProject $p): void {
        $call = ccStep($p, 'call');
        $extra = WorkflowStep::query()->create(['project_id' => $p->id, 'step_code' => 'call2', 'name' => 'call2', 'step_type' => 'http_request',
            'step_config' => ['method' => 'GET', 'url' => 'https://api.example.org/more', 'storeAs' => 'vars.r2']]);
        WorkflowConnection::query()->create(['project_id' => $p->id, 'from_step_id' => $call->id, 'to_step_id' => $extra->id]);
    }, 'sensitive_step_added'],
    'a new host' => [fn (WorkflowProject $p) => ccSetConfig($p, 'call', ['url' => 'https://api2.example.org/items']), 'host_added'],
    'a credential' => [fn (WorkflowProject $p) => ccSetConfig($p, 'call', ['auth' => 'header', 'credential' => '$bind:api_token']), 'credential_changed'],
    'an approval removed' => [function (WorkflowProject $p): void {
        $ok = ccStep($p, 'ok');
        WorkflowConnection::query()->where('project_id', $p->id)->where(fn ($q) => $q->where('from_step_id', $ok->id)->orWhere('to_step_id', $ok->id))->delete();
        $ok->delete();
        WorkflowConnection::query()->create(['project_id' => $p->id, 'from_step_id' => ccStep($p, 'start')->id, 'to_step_id' => ccStep($p, 'write')->id]);
    }, 'approval_removed'],
    'an approval switched to auto' => [fn (WorkflowProject $p) => ccSetConfig($p, 'ok', ['mode' => 'auto']), 'approval_mode_changed'],
    'a new write field' => [fn (WorkflowProject $p) => ccSetConfig($p, 'write', ['changes' => [['field' => 'status', 'valueExpr' => "'x'"]]]), 'write_target_changed'],
    'a budget raised' => [fn (WorkflowProject $p) => $p->forceFill(['budget_max_cost_per_run' => 5])->save(), 'budget_raised'],
    'a schedule' => [fn (WorkflowProject $p) => $p->forceFill(['schedule_cron' => '0 * * * *'])->save(), 'trigger_changed'],
]);

it('does not count a lowered budget as sensitive', function (): void {
    ccSecondPublisher();
    $project = ccProject();
    ccLogin($this->owner);
    $project->forceFill(['budget_max_cost_per_run' => 0.5])->save();

    $this->postJson(CC_URL . $project->id . '/versions/publish')->assertStatus(201)->assertJsonPath('data.path', 'published');
});

it('refuses a sensitive publish without step-up (409) and accepts it with a grant', function (): void {
    $project = ccProject();
    $token = ccLogin($this->owner);
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/items']);

    $this->postJson(CC_URL . $project->id . '/versions/publish')
        ->assertStatus(409)->assertJsonPath('error_code', 'step_up_required')->assertJsonPath('data.purpose', 'workflow_publish');
    // A grant for another purpose does not count.
    $other = app(StepUpConfirmation::class)->start($this->owner->fresh(), $token, 'secret123')['grant'];
    $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => $other])->assertStatus(409);
    expect(WorkflowVersion::query()->where('project_id', $project->id)->count())->toBe(1);

    $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $token)])
        ->assertStatus(202)->assertJsonPath('data.path', 'cooling_off');
});

it('lets another publisher approve, never the author', function (): void {
    $publisherB = ccSecondPublisher();
    $project = ccProject();
    $ownerToken = ccLogin($this->owner);
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/items']);
    $uuid = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $ownerToken)])
        ->assertStatus(202)->json('data.uuid');

    // The author can not approve their own change, even with a grant.
    $this->postJson(CC_URL . "{$project->id}/versions/{$uuid}/approve", [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $ownerToken)])
        ->assertStatus(403)->assertJsonPath('error_code', 'author_cannot_approve');

    $tokenB = ccLogin($publisherB);
    $pending = $this->getJson('https://localhost/api/v2/admin/workflow/versions/pending')->assertOk()->json('data');
    expect($pending)->toHaveCount(1)
        ->and($pending[0]['uuid'])->toBe($uuid)->and($pending[0]['can_approve'])->toBeTrue()->and($pending[0]['can_cancel'])->toBeFalse()
        ->and(collect($pending[0]['changes'])->pluck('kind')->all())->toContain('host_added');

    $this->postJson(CC_URL . "{$project->id}/versions/{$uuid}/approve")->assertStatus(409)->assertJsonPath('error_code', 'step_up_required');
    $this->postJson(CC_URL . "{$project->id}/versions/{$uuid}/approve", [], [StepUpConfirmation::HEADER => ccGrant($publisherB, $tokenB)])
        ->assertOk()->assertJsonPath('data.path', 'published')->assertJsonPath('data.status', 'published');

    $version = WorkflowVersion::query()->where('uuid', $uuid)->firstOrFail();
    expect($project->fresh()->published_version_id)->toBe($version->id)
        ->and($version->approved_by)->toBe($publisherB->id)
        ->and(WorkflowVersionSeal::verify($version))->toBeTrue()
        ->and(WorkflowVersion::query()->where('project_id', $project->id)->where('status', 'published')->count())->toBe(1);
    // The approved version runs.
    $instance = app(WorkflowProjectRunService::class)->start($project->fresh(), $this->owner);
    expect($instance->version_id)->toBe($version->id);
});

it('gives a single-publisher site a cooling-off the minute tick promotes', function (): void {
    $project = ccProject();
    $token = ccLogin($this->owner);
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/items']);

    $response = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $token)])
        ->assertStatus(202)->assertJsonPath('data.path', 'cooling_off');
    expect($response->json('data.effective_at'))->not->toBeNull();
    $version = WorkflowVersion::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

    Artisan::call('cms:workflow-run-scheduled');
    expect($version->fresh()->status)->toBe('cooling_off')->and($project->fresh()->publishedVersion->number)->toBe(1);

    $this->travel(61)->minutes();
    Artisan::call('cms:workflow-run-scheduled');
    expect($version->fresh()->status)->toBe('published')
        ->and($project->fresh()->published_version_id)->toBe($version->id)
        ->and(WorkflowVersionSeal::verify($version->fresh()))->toBeTrue();
});

it('cancels and rejects pending versions, with audit rows', function (): void {
    $publisherB = ccSecondPublisher();
    $project = ccProject();
    $ownerToken = ccLogin($this->owner);
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/items']);
    $first = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $ownerToken)])->json('data.uuid');

    // A newer publish of the same project cancels the older pending one.
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/other', 'auth' => 'header', 'credential' => '$bind:k']);
    $second = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $ownerToken)])->json('data.uuid');
    expect(WorkflowVersion::query()->where('uuid', $first)->value('status'))->toBe('cancelled');

    // Publisher B may not cancel the owner's change, but may reject it.
    ccLogin($publisherB);
    $this->postJson(CC_URL . "{$project->id}/versions/{$second}/cancel")->assertStatus(403);
    $this->postJson(CC_URL . "{$project->id}/versions/{$second}/reject", ['reason' => 'Unknown host'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(\Cms\Core\Models\AuditLog::query()->where('action', 'action.workflow.version_rejected')->exists())->toBeTrue();

    // The author cancels their own pending change.
    $ownerToken = ccLogin($this->owner);
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/third']);
    $third = $this->postJson(CC_URL . $project->id . '/versions/publish', [], [StepUpConfirmation::HEADER => ccGrant($this->owner, $ownerToken)])->json('data.uuid');
    $this->postJson(CC_URL . "{$project->id}/versions/{$third}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(\Cms\Core\Models\AuditLog::query()->where('action', 'action.workflow.version_cancelled')->exists())->toBeTrue()
        ->and($project->fresh()->publishedVersion->number)->toBe(1);
});

it('never lets a pending version run (no run seal, status checked)', function (): void {
    $project = ccProject();
    ccSetConfig($project, 'call', ['url' => 'https://api2.example.org/items']);
    $pending = app(WorkflowPublishService::class)->trustingStepUp()->publish($project->fresh(), $this->owner);
    expect($pending->status)->toBe('cooling_off')
        // Its seal is not a run seal.
        ->and(WorkflowVersionSeal::verify($pending->fresh()))->toBeFalse();

    // A database writer points a run at it: refused on status.
    $instance = WorkflowInstance::query()->create([
        'project_id' => $project->id, 'version_id' => $pending->id, 'target_type' => WorkflowProject::class, 'status' => 'pending',
    ]);
    expect(fn () => RunGraph::forInstance($instance))->toThrow(WorkflowIntegrityException::class);

    // ... and also flips its status: refused on the seal.
    WorkflowVersion::query()->whereKey($pending->id)->update(['status' => 'published']);
    try {
        RunGraph::forInstance($instance->fresh());
        $this->fail('The run should be refused.');
    } catch (WorkflowIntegrityException $e) {
        expect($e->reason)->toBe('seal_mismatch');
    }
});

it('refuses run starts while workflows are paused globally or a breaker trips', function (): void {
    $project = ccProject();
    $runs = app(WorkflowProjectRunService::class);

    app(WorkflowGlobalPause::class)->set(true, $this->owner);
    expect(fn () => $runs->start($project->fresh(), $this->owner))->toThrow(WorkflowGloballyPaused::class);
    app(WorkflowGlobalPause::class)->set(false, $this->owner);

    config(['core.workflow_guard.breaker.runs_per_hour' => 1]);
    $runs->start($project->fresh(), $this->owner);
    WorkflowInstance::query()->where('project_id', $project->id)->update(['status' => 'completed']);
    expect(fn () => $runs->start($project->fresh(), $this->owner))->toThrow(WorkflowBreakerTripped::class);
    expect($project->fresh()->is_active)->toBeFalse()->and($project->fresh()->paused_reason)->toBe('breaker:runs_per_hour');
});

it('trips the error-rate breaker after failed runs', function (): void {
    config(['core.workflow_guard.breaker.error_window' => 2, 'core.workflow_guard.breaker.error_rate' => 0.5]);
    $project = ccProject();
    foreach ([1, 2] as $i) {
        // A run without a version fails closed; the executor evaluates the breaker after the terminal status.
        $instance = WorkflowInstance::query()->create([
            'project_id' => $project->id, 'version_id' => null, 'target_type' => WorkflowProject::class, 'status' => 'pending',
        ]);
        app(\Cms\Core\Workflows\Runtime\WorkflowGraphExecutor::class)->execute($instance->id);
        expect($instance->fresh()->status)->toBe('failed');
    }

    expect($project->fresh()->paused_reason)->toBe('breaker:error_rate')->and($project->fresh()->is_active)->toBeFalse();
});

it('refuses to resume a breaker-paused project without a published version', function (): void {
    $project = wgProject(['is_active' => false, 'paused_reason' => 'breaker:runs_per_hour', 'paused_at' => now()]);
    ccLogin($this->owner);

    $this->postJson("https://localhost/api/v2/admin/workflow/projects/{$project->id}/resume")
        ->assertStatus(422)->assertJsonPath('error_code', 'not_published');
});

it('lists the guard fields on project rows and refuses activation without a published version', function (): void {
    $publisherB = ccSecondPublisher();
    $project = ccProject();
    $draftOnly = wgProject(['is_active' => false]);
    // The project resource routes also need the admin app key header.
    $this->withoutMiddleware(\App\Http\Middleware\ApiAdminVerify::class);
    ccLogin($publisherB);

    $row = collect($this->getJson('https://localhost/api/v2/admin/workflows')->assertOk()->json('data'))->firstWhere('id', $project->id);
    expect($row['published_version_number'])->toBe(1)
        ->and($row['published_version_id'])->toBe($project->published_version_id)
        ->and($row['draft_differs'])->toBeFalse()
        ->and($row['pending_versions'])->toBe(0)
        ->and($row['can_publish'])->toBeTrue()
        ->and($row['can_run'])->toBeFalse()
        ->and(array_key_exists('paused_reason', $row))->toBeTrue()
        ->and(array_key_exists('paused_at', $row))->toBeTrue();

    $this->putJson('https://localhost/api/v2/admin/workflows/' . $draftOnly->id, ['name' => $draftOnly->name, 'is_active' => true])
        ->assertStatus(422)->assertJsonPath('error_code', 'not_published');
    expect($draftOnly->fresh()->is_active)->toBeFalse();
});

it('keeps the fingerprint blind to wording, positions and assignees', function (): void {
    $project = ccProject();
    $before = WorkflowSensitiveFingerprint::of(WorkflowVersionSnapshot::fromDraft($project));
    ccSetConfig($project, 'ai', ['instructions' => 'Something else entirely']);
    ccSetConfig($project, 'ok', ['assignees' => [(string) Str::uuid()], 'sla' => '2h', 'allow_revision' => true]);
    ccStep($project, 'call')->update(['name' => 'Fetch', 'position_y' => 300]);
    $after = WorkflowSensitiveFingerprint::of(WorkflowVersionSnapshot::fromDraft($project));

    expect($after['hash'])->toBe($before['hash'])
        ->and(WorkflowSensitiveFingerprint::changes($before['summary'], $after['summary']))->toBe([]);
});

it('fills the fingerprint of a backfilled version when it is compared', function (): void {
    $project = ccProject();
    $v1 = $project->publishedVersion;
    WorkflowVersion::query()->whereKey($v1->id)->update(['sensitive_fingerprint' => null, 'sensitive_summary' => null]);

    WorkflowSensitiveFingerprint::forVersion($v1->fresh());
    expect($v1->fresh()->sensitive_fingerprint)->not->toBeNull();
});
