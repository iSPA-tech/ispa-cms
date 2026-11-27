<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Http\Middleware\RequireStepUpGrant;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * Workflow guardrails lane 2: alerts to publishers (B5), audit of changes the observers miss (B5), credential
 * step-up, change alert and use record (B6).
 */

require_once __DIR__ . '/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const WG_CRED_URL = 'https://localhost/api/v2/admin/workflow/credentials';

beforeEach(function (): void {
    Queue::fake();
    config(['services.telegram.token' => '', 'services.telegram.chat_id' => '']);
    $this->webmaster = wgUser('webmaster'); // user id 1 is always a Webmaster
});

function wgAudit(string $action): ?AuditLog
{
    return AuditLog::query()->where('action', 'action.' . $action)->latest('id')->first();
}

it('alerts publishers and Webmasters, not plain editors, with names only', function (): void {
    Notification::fake();
    Http::fake();
    $publisher = wgUser('publisher', ['workflow_project.publish']);
    $editor = wgUser('editor', ['workflow_project.update', 'workflow_project.viewAny']);
    $roleWebmaster = wgUser('role-webmaster', [], 0);
    $project = wgProject(['name' => 'Daily Gospel']);

    app(WorkflowChangeAlerter::class)->notify('auto_paused', $project, [
        'reason' => 'runs_per_hour', 'count' => 60, 'step_config' => ['prompt' => 'SECRET PROMPT'], 'token' => 'T0KEN',
    ]);

    foreach ([$this->webmaster, $publisher, $roleWebmaster] as $user) {
        Notification::assertSentTo($user, AdminNotification::class, function (AdminNotification $n): bool {
            $json = (string) json_encode($n->payload);

            return $n->type === 'workflow.change.auto_paused' && $n->isValid()
                && $n->payload['project_name'] === 'Daily Gospel' && $n->payload['reason'] === 'runs_per_hour'
                && !str_contains($json, 'SECRET PROMPT') && !str_contains($json, 'T0KEN');
        });
    }
    Notification::assertNotSentTo($editor, AdminNotification::class);
    // No bot configured: Telegram is skipped silently.
    Http::assertNothingSent();
});

it('also posts to the site Telegram bot when one is configured, without secrets', function (): void {
    Notification::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    config(['services.telegram.token' => '123:BOT', 'services.telegram.chat_id' => '-100']);
    \App\Services\Logger\TelegramService::init();

    app(WorkflowChangeAlerter::class)->notify('credential_changed', wgProject(['name' => 'Spotlight']), ['credential' => 'church_bot', 'token' => 'S3CRET']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
        && str_contains((string) $request['text'], 'Spotlight') && !str_contains((string) $request['text'], 'S3CRET'));
    config(['services.telegram.token' => '', 'services.telegram.chat_id' => '']);
    \App\Services\Logger\TelegramService::init();
});

it('refuses credential changes without a step-up grant and accepts them with one', function (): void {
    $user = wgUser('cred-admin', ['workflow_credential.manage']);
    $new = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ADMIN, 'dev', ['*']);
    $token = ApiAccessToken::query()->findOrFail($new->accessToken->getKey());
    Sanctum::actingAs($user, ['*']);
    $this->app['auth']->guard('sanctum')->setUser($user->withAccessToken($token));
    $credential = app(WorkflowCredentialStore::class)->create(['name' => 'bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'T0KEN-VALUE']], null);

    $this->postJson(WG_CRED_URL, ['name' => 'x', 'type' => 'telegram_bot', 'payload' => ['token' => 'abcd']])
        ->assertStatus(403)->assertJsonPath('error_code', 'step_up_required');
    $this->putJson(WG_CRED_URL . '/' . $credential->uuid, ['name' => 'y'])->assertStatus(403);
    $this->deleteJson(WG_CRED_URL . '/' . $credential->uuid)->assertStatus(403);
    // A grant for another purpose does not open it.
    $other = app(StepUpConfirmation::class)->start($user->fresh(), $token, 'secret123')['grant'];
    $this->deleteJson(WG_CRED_URL . '/' . $credential->uuid, [], [StepUpConfirmation::HEADER => $other])->assertStatus(403);
    // Reading and testing need no grant.
    $this->getJson(WG_CRED_URL)->assertOk();

    $grant = app(StepUpConfirmation::class)->start($user->fresh(), $token, 'secret123', StepUpConfirmation::PURPOSE_WORKFLOW_CREDENTIAL)['grant'];
    $this->postJson(WG_CRED_URL, ['name' => 'x', 'type' => 'telegram_bot', 'payload' => ['token' => 'abcd']], [StepUpConfirmation::HEADER => $grant])
        ->assertCreated();
});

it('audits credential changes without values and alerts when a live workflow binds the credential', function (): void {
    Notification::fake();
    $this->withoutMiddleware(RequireStepUpGrant::class);
    $admin = wgUser('cred-admin', ['workflow_credential.manage']);
    $this->actingAs($admin, 'sanctum');

    $created = $this->postJson(WG_CRED_URL, ['name' => 'church_bot', 'type' => 'telegram_bot', 'payload' => ['token' => '999:SUPER-SECRET']])->assertCreated();
    $uuid = $created->json('data.uuid');
    expect(json_encode(wgAudit(WorkflowAudit::CREDENTIAL_CREATED)?->changed))->toContain('church_bot')->not->toContain('SUPER-SECRET');

    // Nobody binds it yet: no alert.
    $this->putJson(WG_CRED_URL . '/' . $uuid, ['payload' => ['token' => '999:OTHER-SECRET']])->assertOk();
    Notification::assertNothingSent();

    $live = wgProject(['name' => 'Spotlight']);
    WorkflowStep::query()->forceCreate(['project_id' => $live->id, 'step_code' => 'send', 'name' => 'Send', 'step_type' => 'telegram_send',
        'step_config' => ['credential' => '$bind:church_bot', 'text' => 'hi']]);
    $off = wgProject(['name' => 'Switched off', 'is_active' => false]);
    WorkflowStep::query()->forceCreate(['project_id' => $off->id, 'step_code' => 'send', 'name' => 'Send', 'step_type' => 'telegram_send',
        'step_config' => ['credential' => '$bind:church_bot']]);

    $this->putJson(WG_CRED_URL . '/' . $uuid, ['payload' => ['token' => '999:THIRD-SECRET']])->assertOk();
    $updated = json_encode(wgAudit(WorkflowAudit::CREDENTIAL_UPDATED)?->changed);
    expect($updated)->toContain('token')->not->toContain('THIRD-SECRET')->not->toContain('OTHER-SECRET');
    Notification::assertSentTo($this->webmaster, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.credential_changed'
        && $n->payload['project_name'] === 'Spotlight' && $n->payload['credential'] === 'church_bot');
    Notification::assertSentTimes(AdminNotification::class, 1);

    $this->postJson(WG_CRED_URL . '/' . $uuid . '/test');
    expect(wgAudit(WorkflowAudit::CREDENTIAL_TESTED))->not->toBeNull();

    $this->deleteJson(WG_CRED_URL . '/' . $uuid)->assertOk();
    expect(wgAudit(WorkflowAudit::CREDENTIAL_DELETED)?->changed['name'] ?? null)->toBe('church_bot');
    Notification::assertSentTimes(AdminNotification::class, 2);
});

it('reads credentials as descriptions only and records each use by name on the run step', function (): void {
    $store = app(WorkflowCredentialStore::class);
    $credential = $store->create(['name' => 'church_bot', 'type' => 'telegram_bot', 'payload' => ['token' => '999:SUPER-SECRET']], null);
    expect(json_encode($store->describe($credential)))->not->toContain('SUPER-SECRET')
        ->and($store->describe($credential))->not->toHaveKey('payload');

    $project = wgProject();
    $step = WorkflowStep::query()->forceCreate(['project_id' => $project->id, 'step_code' => 'send', 'name' => 'Send', 'step_type' => 'telegram_send', 'step_config' => []]);
    $instance = WorkflowInstance::query()->create(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'running']);
    $row = WorkflowInstanceStep::query()->create(['instance_id' => $instance->id, 'step_id' => $step->id, 'status' => 'running', 'started_at' => now()]);

    $store->recordUse($instance->id, 'send', 'church_bot');
    $store->recordUse($instance->id, 'send', 'church_bot');
    $store->recordUse(0, 'send', 'ignored');

    expect($row->fresh()->input_data)->toBe(['credentials_used' => ['church_bot']]);
});

it('audits webhook rotate and disable, error workflow, schedule and manual run changes', function (): void {
    $this->withoutMiddleware(RequireStepUpGrant::class);
    $editor = wgUser('editor', ['workflow_project.update', 'workflow_project.viewAny', 'workflow_project.run', 'workflow_project.publish']);
    $this->actingAs($editor, 'sanctum');
    $project = wgProject();
    $base = "https://localhost/api/v2/admin/workflow/projects/{$project->uuid}";

    $rotated = $this->postJson("{$base}/webhook")->assertOk();
    $disabled = $this->deleteJson("{$base}/webhook")->assertOk();
    $rotateAudit = wgAudit(WorkflowAudit::WEBHOOK_ROTATED);
    expect($rotateAudit?->target_id)->toBe($project->id)
        ->and(json_encode($rotateAudit?->changed))->not->toContain((string) $rotated->json('data.webhook.secret'))
        ->and(wgAudit(WorkflowAudit::WEBHOOK_DISABLED))->not->toBeNull();

    $this->putJson("{$base}/error-workflow", ['error_workflow_uuid' => null])->assertOk();
    expect(wgAudit(WorkflowAudit::ERROR_WORKFLOW_CHANGED))->toBeNull(); // nothing changed, nothing recorded

    $this->putJson("{$base}/schedule", ['cron' => '0 8 * * *'])->assertOk();
    expect(wgAudit(WorkflowAudit::SCHEDULE_CHANGED)?->changed)->toMatchArray(['from' => ['cron' => null, 'timezone' => null], 'to' => ['cron' => '0 8 * * *', 'timezone' => null]]);

    $this->mock(WorkflowProjectRunService::class, function ($mock) use ($project): void {
        $mock->shouldReceive('start')->once()->andReturn(WorkflowInstance::query()->create([
            'target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'pending',
        ]));
    });
    $this->postJson("{$base}/run")->assertStatus(202);
    expect(wgAudit(WorkflowAudit::RUN_STARTED)?->user_id)->toBe($editor->id);
});

it('turns an integrity violation event into an integrity_violated alert', function (): void {
    Notification::fake();
    $project = wgProject(['name' => 'Tampered']);
    $event = new class($project) {
        public function __construct(public WorkflowProject $project)
        {
        }
    };

    app(\Cms\Core\Listeners\AlertWorkflowIntegrityViolation::class)->handle($event);

    Notification::assertSentTo($this->webmaster, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.integrity_violated'
        && $n->payload['project_name'] === 'Tampered');
    expect(app('events')->hasListeners('Cms\Core\Events\WorkflowIntegrityViolated'))->toBeTrue();
});

it('alerts on the integrity event lane 1 dispatches', function (): void {
    Notification::fake();
    $project = wgProject(['name' => 'Sealed']);

    event(new \Cms\Core\Events\WorkflowIntegrityViolated($project->id, null, null, 'seal_mismatch', 'integrity_check'));

    Notification::assertSentTo($this->webmaster, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.integrity_violated'
        && $n->payload['project_name'] === 'Sealed' && $n->payload['reason'] === 'seal_mismatch');
});

it('issues a webhook secret only to a publisher with a fresh step-up, and alerts when the workflow is live', function (): void {
    $project = wgProject(['is_active' => true]);
    $url = "https://localhost/api/v2/admin/workflow/projects/{$project->uuid}/webhook";

    $this->actingAs(wgUser('drafter', ['workflow_project.update', 'workflow_project.viewAny']), 'sanctum');
    $this->withoutMiddleware(RequireStepUpGrant::class)->postJson($url)->assertForbidden();
    expect($project->fresh()->webhook_token)->toBeNull();

    $this->withMiddleware(RequireStepUpGrant::class);
    $publisher = wgUser('publisher', ['workflow_project.update', 'workflow_project.viewAny', 'workflow_project.publish']);
    $this->actingAs($publisher, 'sanctum');
    expect($this->postJson($url)->status())->toBeGreaterThanOrEqual(400);
    expect($project->fresh()->webhook_token)->toBeNull();

    $this->withoutMiddleware(RequireStepUpGrant::class)->postJson($url)->assertOk();
    expect($project->fresh()->webhook_token)->not->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('notifications')->where('type', 'like', '%AdminNotification%')->where('data', 'like', '%credential_changed%')->exists())->toBeTrue();
});
