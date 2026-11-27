<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishService;
use Cms\Core\Services\Workflow\Versioning\WorkflowSensitiveFingerprint;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSeal;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Tests\Support\WorkflowTestVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Review fixes 14 and 15 (2026-11-25): draft editors are authors of the version they publish into (a second publisher
 * never launders a first publisher's edits), and new runs start only on the version the project's sealed live pointer
 * names, with the sensitive summary recomputed from the sealed graph.
 */

require_once __DIR__ . '/../Guard/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const LC_URL = 'https://localhost/api/v2/admin/workflows/';

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
    $this->owner = wgUser('owner'); // user id 1, a Webmaster
});

function lcLogin(User $user): ApiAccessToken
{
    $new = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ADMIN, 'dev', ['*']);
    $token = ApiAccessToken::query()->findOrFail($new->accessToken->getKey());
    Sanctum::actingAs($user, ['*']);
    app('auth')->guard('sanctum')->setUser($user->withAccessToken($token));
    app('auth')->shouldUse('sanctum');

    return $token;
}

function lcGrant(User $user, ApiAccessToken $token): string
{
    return app(StepUpConfirmation::class)->start($user->fresh(), $token, 'secret123', StepUpConfirmation::PURPOSE_WORKFLOW_PUBLISH)['grant'];
}

function lcPublisher(string $name): User
{
    return wgUser($name, ['workflow_project.publish', 'workflow_project.view', 'workflow_project.viewAny', 'workflow_project.update']);
}

/** start → ok (approval) → call (http): published as v1 by the system. */
function lcProject(): WorkflowProject
{
    $project = WorkflowProject::query()->forceCreate([
        'name' => 'LC ' . Str::random(4), 'slug' => 'lc-' . Str::lower(Str::random(6)),
        'trigger_event' => 'manual', 'target_entity_type' => 'none', 'is_active' => true,
    ]);
    $previous = null;
    $i = 0;
    foreach ([
        'start' => ['trigger_manual', []],
        'ok' => ['approval', ['mode' => 'human']],
        'call' => ['http_request', ['method' => 'GET', 'url' => 'https://api.example.org/items', 'storeAs' => 'vars.r']],
    ] as $code => [$type, $config]) {
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

function lcSet(WorkflowProject $project, string $code, array $merge): void
{
    $step = WorkflowStep::query()->where('project_id', $project->id)->where('step_code', $code)->firstOrFail();
    $step->update(['step_config' => array_merge((array) $step->step_config, $merge)]);
}

// ── Fix 14 ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('never lets a draft editor approve the version another publisher made of their edits', function (): void {
    $a = lcPublisher('publisher-a');
    $b = lcPublisher('publisher-b');
    $c = lcPublisher('publisher-c');
    $project = lcProject();

    // A edits sensitive behaviour into the draft; B adds an unrelated tweak and publishes.
    lcLogin($a);
    lcSet($project, 'call', ['url' => 'https://api2.example.org/items']);
    $tokenB = lcLogin($b);
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'ok')->firstOrFail()->update(['name' => 'Check it']);
    $uuid = $this->postJson(LC_URL . $project->uuid . '/versions/publish', [], [StepUpConfirmation::HEADER => lcGrant($b, $tokenB)])
        ->assertStatus(202)->assertJsonPath('data.path', 'pending_review')->json('data.uuid');

    // A may not approve it: their edits are in it.
    $tokenA = lcLogin($a);
    $this->postJson(LC_URL . "{$project->uuid}/versions/{$uuid}/approve", [], [StepUpConfirmation::HEADER => lcGrant($a, $tokenA)])
        ->assertStatus(403)->assertJsonPath('error_code', 'author_cannot_approve');
    $pending = $this->getJson('https://localhost/api/v2/admin/workflow/versions/pending')->assertOk()->json('data');
    expect($pending[0]['can_approve'])->toBeFalse();

    // C, who touched nothing, approves; the draft now equals the live version, so its editor list is cleared.
    $tokenC = lcLogin($c);
    $this->postJson(LC_URL . "{$project->uuid}/versions/{$uuid}/approve", [], [StepUpConfirmation::HEADER => lcGrant($c, $tokenC)])->assertOk();
    expect(WorkflowVersion::query()->where('uuid', $uuid)->value('status'))->toBe('published')
        ->and(DB::table('workflow_projects')->where('id', $project->id)->value('draft_editor_ids'))->toBeNull();
});

it('cools off instead of waiting for a review only a draft editor could give', function (): void {
    // The only other publisher (the Webmaster) edited the draft, so nobody is left to review B's publish.
    $b = lcPublisher('publisher-b');
    $project = lcProject();

    lcLogin($this->owner);
    lcSet($project, 'call', ['url' => 'https://api2.example.org/items']);
    $tokenB = lcLogin($b);
    $this->postJson(LC_URL . $project->uuid . '/versions/publish', [], [StepUpConfirmation::HEADER => lcGrant($b, $tokenB)])
        ->assertStatus(202)->assertJsonPath('data.path', 'cooling_off');
});

// ── Fix 15 ───────────────────────────────────────────────────────────────────────────────────────────────────────────

it('refuses a run on a live pointer a database write moved back to an older version', function (): void {
    $project = lcProject();
    $v1 = $project->publishedVersion;
    lcSet($project, 'call', ['url' => 'https://api.example.org/other']);
    $v2 = app(WorkflowPublishService::class)->publishSystem($project->fresh());
    expect($v2->id)->not->toBe($v1->id);

    // Silent rollback: the pointer goes back to the superseded v1.
    DB::table('workflow_projects')->where('id', $project->id)->update(['published_version_id' => $v1->id]);
    try {
        app(WorkflowProjectRunService::class)->start($project->fresh(), $this->owner);
        $this->fail('The run should be refused.');
    } catch (WorkflowIntegrityException $e) {
        expect($e->reason)->toBe('version_not_published');
    }

    // ... and the statuses flipped too: the pointer seal still names v2.
    DB::table('workflow_projects')->where('id', $project->id)->update(['is_active' => true, 'paused_reason' => null]);
    WorkflowVersion::query()->whereKey($v2->id)->update(['status' => 'superseded']);
    WorkflowVersion::query()->whereKey($v1->id)->update(['status' => 'published']);
    try {
        app(WorkflowProjectRunService::class)->start($project->fresh(), $this->owner);
        $this->fail('The run should be refused.');
    } catch (WorkflowIntegrityException $e) {
        expect($e->reason)->toBe('pointer_seal_mismatch');
    }
    expect($project->fresh()->is_active)->toBeFalse()->and($project->fresh()->paused_reason)->toBe('integrity');
});

it('seals the live pointer at publish and backfills it in the migration', function (): void {
    $project = lcProject();
    expect(WorkflowVersionSeal::verifyPointer($project->fresh()))->toBeTrue();

    DB::table('workflow_projects')->where('id', $project->id)->update(['published_version_seal' => null]);
    expect(WorkflowVersionSeal::verifyPointer($project->fresh()))->toBeFalse();
    (require base_path('cms/Core/database/migrations/2026_11_25_000300_add_editors_and_live_pointer_seal_to_workflows.php'))->up();
    expect(WorkflowVersionSeal::verifyPointer($project->fresh()))->toBeTrue();
});

it('compares a new version with the live graph, not an inflated stored summary', function (): void {
    $project = lcProject();
    lcSet($project, 'call', ['url' => 'https://api2.example.org/items']);
    // A database writer adds the new host to the live version's stored summary beforehand.
    $inflated = WorkflowSensitiveFingerprint::of(WorkflowVersionSnapshot::fromDraft($project->fresh()))['summary'];
    WorkflowVersion::query()->whereKey($project->published_version_id)->update(['sensitive_summary' => json_encode($inflated)]);

    $version = app(WorkflowPublishService::class)->trustingStepUp()->publish($project->fresh(), $this->owner);

    expect($version->status)->toBe('cooling_off')
        ->and(collect($version->publishChanges)->pluck('kind')->all())->toContain('host_added');
});
