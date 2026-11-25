<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Ai\Jev\JevJudge;
use Cms\Core\Services\Ai\Jev\JevRequest;
use Cms\Core\Services\Ai\Jev\JevVerdict;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Auto-publish after JEV calibration (direction C): an approval step reading a stored verdict (`jev.verdict_from`)
 * learns in mode human, decides in mode jev only when sure and clean, never rejects, pauses itself when agreement
 * drops; the calibration endpoint, the not-ready refusal and the inbox fields.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * The judge: the workflow's `jev_review` step gets the verdict the test set ($next); the approval step must never ask
 * again (a stored verdict is reused), which $calls counts.
 */
final class AutoPublishNoJudge implements JevJudge
{
    public static int $calls = 0;

    public static ?JevVerdict $next = null;

    public function judge(JevRequest $request): JevVerdict
    {
        if ($request->lane === 'workflow_jev_review') {
            return self::$next ?? throw new RuntimeException('no verdict set');
        }
        self::$calls++;

        return new JevVerdict('assessed', JevVerdict::PASS, 0.99, ['judged again'], $request->mode);
    }
}

/** Alerter that counts alerts instead of notifying. */
final class AutoPublishAlerts extends WorkflowChangeAlerter
{
    /** @var list<array{event: string, details: array<string, mixed>}> */
    public static array $sent = [];

    public function notify(string $event, ?WorkflowProject $project, array $details = [], ?User $actor = null): void
    {
        self::$sent[] = ['event' => $event, 'details' => $details];
    }
}

function apUser(string $name, array $permissions = []): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => $name, 'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/**
 * setdraft (stores vars.draft) → judge (jev_review, stores vars.jev) → [forge] → ask (approval) → ok / no.
 * $forge: a step that overwrites vars.jev after the judge (review 2026-11-24 finding 6).
 *
 * @param array<string, mixed> $approval
 * @param array<string, mixed>|null $forge
 */
function apProject(array $approval, ?array $forge = null): WorkflowProject
{
    $project = WorkflowProject::query()->create(['name' => 'Auto publish', 'slug' => 'auto-publish-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $steps = [
        'setdraft' => ['set_variable', ['assignments' => [['key' => 'vars.draft', 'valueExpr' => 'vars.trigger_draft']]]],
        'judge' => ['jev_review', ['criteria' => 'Accurate and kind.', 'content' => 'vars.draft', 'storeAs' => 'vars.jev']],
    ] + ($forge === null ? [] : ['forge' => ['set_variable', ['assignments' => [['key' => 'vars.jev', 'valueExpr' => 'vars.forged']]] + $forge]]) + [
        'ask' => ['approval', $approval + ['payload' => "'x'", 'title' => 'Story', 'notify' => false]],
        'ok' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]],
        'no' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'no'"]]]],
    ];
    $made = [];
    $x = 1;
    foreach ($steps as $code => [$type, $config]) {
        $made[$code] = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => $type, 'step_code' => $code, 'step_config' => $config, 'position_x' => $x, 'is_start' => $x === 1]);
        $x++;
    }
    $path = $forge === null
        ? [['setdraft', 'judge', null], ['judge', 'ask', 'default']]
        : [['setdraft', 'judge', null], ['judge', 'forge', 'default'], ['forge', 'ask', null]];
    foreach ([...$path, ['ask', 'ok', 'approved'], ['ask', 'no', 'rejected']] as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    return $project;
}

/** Change the draft config of the approval step (and publish it, as the next run starts on the published version). */
function apConfigure(WorkflowProject $project, array $approval): void
{
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'ask')
        ->update(['step_config' => json_encode($approval + ['payload' => "'x'", 'title' => 'Story', 'notify' => false])]);
}

/**
 * @param array<string, mixed>|null $jev
 * @param array<string, mixed>      $draft
 */
function apRun(WorkflowProject $project, array $jev, array $draft = ['status' => 'draft', 'quality_flags' => []], ?array $forged = null): WorkflowInstance
{
    // What the judge answers the `jev_review` step (`probability` = its confidence).
    AutoPublishNoJudge::$next = new JevVerdict((string) ($jev['status'] ?? 'assessed'), (string) ($jev['verdict'] ?? 'unsure'), isset($jev['probability']) ? (float) $jev['probability'] : null, (array) ($jev['reasons'] ?? []));
    $user = apUser('starter');
    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
        'project_id' => $project->id, 'target_type' => User::class, 'target_id' => $user->id, 'status' => 'pending',
        'delegated_by_user_id' => $user->id,
        'context_snapshot' => ['vars' => ['trigger_draft' => $draft, 'forged' => $forged]],
    ]);
    app(WorkflowGraphExecutor::class)->execute($instance->id);

    return $instance->fresh();
}

function apLatest(): AgentApproval
{
    return AgentApproval::query()->latest('id')->firstOrFail();
}

/** $agreeing person decisions on JEV passes (approved) plus $disagreeing ones (rejected) in learning mode. */
function apLearn(WorkflowProject $project, int $agreeing, int $disagreeing): void
{
    $reviewer = apUser('reviewer');
    $service = app(AgentApprovalService::class);
    for ($i = 0; $i < $agreeing + $disagreeing; $i++) {
        apRun($project, ['verdict' => 'pass', 'status' => 'assessed', 'probability' => $i % 2 === 0 ? 0.95 : 0.7]);
        $i < $agreeing ? $service->approveAndApply(apLatest(), $reviewer) : $service->reject(apLatest(), $reviewer, 'No');
    }
}

function apFixInstanceStepKey(): void
{
    if (DB::getDriverName() !== 'sqlite') {
        return;
    }
    $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'workflow_instance_steps')->value('sql');
    $fixed = preg_replace('/,\s*foreign key\("step_id"\) references "workflow_instances"\("id"\)[^,)]*/i', '', $sql);
    if ($fixed === null || $fixed === $sql) {
        return;
    }
    DB::statement((string) preg_replace('/^CREATE TABLE "?workflow_instance_steps"?/i', 'CREATE TABLE "workflow_instance_steps_fixed"', $fixed));
    DB::statement('INSERT INTO workflow_instance_steps_fixed SELECT * FROM workflow_instance_steps');
    DB::statement('DROP TABLE workflow_instance_steps');
    DB::statement('ALTER TABLE workflow_instance_steps_fixed RENAME TO workflow_instance_steps');
}

const AP_PASS = ['verdict' => 'pass', 'status' => 'assessed', 'probability' => 0.92, 'reasons' => ['ok'], 'receipt_id' => 'r1'];

beforeEach(function (): void {
    apFixInstanceStepKey();
    AutoPublishNoJudge::$calls = 0;
    AutoPublishNoJudge::$next = null;
    AutoPublishAlerts::$sent = [];
    app()->bind(JevJudge::class, AutoPublishNoJudge::class);
    app()->bind(WorkflowChangeAlerter::class, AutoPublishAlerts::class);
    config([
        'core.workflow_nodes.human.jev.calibration_min_decisions' => 4,
        'core.workflow_nodes.human.jev.calibration_window' => 4,
    ]);
    apUser('placeholder'); // user id 1 is a webmaster
});

$auto = ['mode' => 'jev', 'jev' => ['verdict_from' => 'vars.jev', 'min_confidence' => 0.8, 'hold_if' => "vars.draft.status != 'draft' or len(vars.draft.quality_flags) > 0"]];

it('records the stored verdict while learning and lets a person decide', function (): void {
    $project = apProject(['mode' => 'human', 'jev' => ['verdict_from' => 'vars.jev', 'min_confidence' => 0.8]]);
    $instance = apRun($project, AP_PASS);
    $approval = apLatest();

    expect($instance->status)->toBe('awaiting_approval')
        ->and($approval->status)->toBe('pending')
        ->and(data_get($approval->diff_data, 'workflow_node.held_reason'))->toBe('learning')
        ->and(data_get($approval->diff_data, 'workflow_node.jev'))->toBe(['verdict' => 'pass', 'probability' => 0.92, 'min_confidence' => 0.8])
        ->and(AutoPublishNoJudge::$calls)->toBe(0);
});

it('approves on its own at or above the level, with no second judge call', function () use ($auto): void {
    $project = apProject($auto);

    $instance = apRun($project, ['probability' => 0.8] + AP_PASS);
    $approval = apLatest();

    expect(data_get($instance->context_snapshot, 'vars.went'))->toBe('ok')
        ->and($approval->status)->toBe('approved')
        ->and(data_get($approval->diff_data, 'workflow_node.decided_by'))->toBe('jev')
        ->and(data_get($approval->diff_data, 'workflow_node.held_reason'))->toBeNull()
        ->and(data_get($approval->diff_data, 'workflow_node.jev.probability'))->toBe(0.8)
        ->and(data_get($instance->context_snapshot, 'vars.approval.decided_by'))->toBe('jev')
        ->and(AutoPublishNoJudge::$calls)->toBe(0);
});

it('hands to a person below the level, when flagged, unsure or missing, and never rejects', function (?array $jev, array $draft, string $reason) use ($auto): void {
    $project = apProject($auto);

    $instance = apRun($project, $jev, $draft);
    $approval = apLatest();

    expect($instance->status)->toBe('awaiting_approval')
        ->and($approval->status)->toBe('pending')
        ->and(data_get($approval->diff_data, 'workflow_node.held_reason'))->toBe($reason)
        ->and(data_get($approval->diff_data, 'workflow_node.handed_to'))->toBe('human')
        ->and(data_get($approval->diff_data, 'workflow_node.decided_by'))->toBeNull()
        ->and(AutoPublishNoJudge::$calls)->toBe(0);
})->with([
    'below the level' => [['probability' => 0.79] + AP_PASS, ['status' => 'draft', 'quality_flags' => []], 'below_threshold'],
    // A `fail` stored by jev_review carries no pass probability: a person decides, it is never rejected.
    'fail is never rejected' => [['verdict' => 'fail', 'status' => 'assessed', 'probability' => 0.95], ['status' => 'draft', 'quality_flags' => []], 'jev_unsure'],
    'quality flag' => [AP_PASS, ['status' => 'draft', 'quality_flags' => ['missing_source']], 'flagged'],
    'needs review' => [AP_PASS, ['status' => 'needs_review', 'quality_flags' => []], 'flagged'],
    'jev unsure' => [['verdict' => 'unsure', 'status' => 'disabled', 'probability' => null], ['status' => 'draft', 'quality_flags' => []], 'jev_unsure'],
]);

it('never approves on a verdict another step wrote over the judge\'s (finding 6)', function () use ($auto): void {
    // The judge fails the item; a later step writes a perfect pass into vars.jev.
    $project = apProject($auto, []);

    $instance = apRun($project, ['verdict' => 'fail', 'status' => 'assessed', 'probability' => 0.9], ['status' => 'draft', 'quality_flags' => []],
        ['verdict' => 'pass', 'status' => 'assessed', 'probability' => 1, 'confidence' => 1]);
    $approval = apLatest();

    expect($instance->status)->toBe('awaiting_approval')
        ->and($approval->status)->toBe('pending')
        ->and(data_get($approval->diff_data, 'workflow_node.held_reason'))->toBe('jev_missing')
        ->and(data_get($approval->diff_data, 'workflow_node.decided_by'))->toBeNull();
});

it('pauses itself when agreement drops, alerting once, and resumes when it recovers', function () use ($auto): void {
    $project = apProject(['mode' => 'human', 'jev' => $auto['jev']]);
    apLearn($project, 2, 2);
    apConfigure($project, $auto);

    apRun($project, AP_PASS);
    expect(data_get(apLatest()->diff_data, 'workflow_node.held_reason'))->toBe('paused');
    apRun($project, AP_PASS);
    expect(data_get(apLatest()->diff_data, 'workflow_node.held_reason'))->toBe('paused')
        ->and(AutoPublishAlerts::$sent)->toHaveCount(1)
        ->and(AutoPublishAlerts::$sent[0]['event'])->toBe('auto_approval_paused')
        ->and(AutoPublishAlerts::$sent[0]['details']['step_code'])->toBe('ask');

    // People approve the two held passes (latest four: 2 agree, 2 disagree), then two more held ones: 4 of 4 agree.
    $reviewer = apUser('reviewer2');
    foreach (AgentApproval::query()->where('status', 'pending')->get() as $held) {
        app(AgentApprovalService::class)->approveAndApply($held, $reviewer);
    }
    apRun($project, AP_PASS);
    app(AgentApprovalService::class)->approveAndApply(apLatest(), $reviewer);
    apRun($project, AP_PASS);
    app(AgentApprovalService::class)->approveAndApply(apLatest(), $reviewer);

    apRun($project, AP_PASS);
    expect(apLatest()->status)->toBe('approved')
        ->and(data_get(apLatest()->diff_data, 'workflow_node.decided_by'))->toBe('jev')
        ->and(AutoPublishAlerts::$sent)->toHaveCount(1);
});

it('never counts its own approvals as agreement: calibration and the pause window see only people', function () use ($auto): void {
    $project = apProject(['mode' => 'human', 'jev' => $auto['jev']]);
    apLearn($project, 3, 1);
    apConfigure($project, $auto);
    $service = app(\Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval::class);

    // 3 of 4 agree (0.75 < 0.9): paused, a person decides.
    apRun($project, AP_PASS);
    expect(data_get(apLatest()->diff_data, 'workflow_node.held_reason'))->toBe('paused');

    // Four agreeing person decisions recover the window; then JEV approves on its own.
    apConfigure($project, ['mode' => 'human', 'jev' => $auto['jev']]);
    app(AgentApprovalService::class)->approveAndApply(apLatest(), apUser('reviewer3'));
    apLearn($project, 3, 0);
    apConfigure($project, $auto);
    $before = $service->describe($project, 'ask');
    apRun($project, AP_PASS);
    apRun($project, AP_PASS);
    $after = $service->describe($project, 'ask');

    expect(apLatest()->status)->toBe('approved')
        ->and(data_get(apLatest()->diff_data, 'workflow_node.decided_by'))->toBe('jev')
        ->and($after['decided'])->toBe($before['decided'])
        ->and($after['agreed'])->toBe($before['agreed'])
        ->and($after['estimate']['of'])->toBe($before['estimate']['of'])
        ->and($after['last_30_days']['auto'])->toBe(2)
        ->and($after['last_30_days']['human'])->toBe($before['last_30_days']['human']);
});

it('describes the calibration and estimate of a step through the admin API', function (): void {
    $project = apProject(['mode' => 'human', 'jev' => ['verdict_from' => 'vars.jev', 'min_confidence' => 0.9]]);
    apLearn($project, 3, 1);
    $url = "/api/v2/admin/workflows/{$project->id}/steps/ask/auto-approval";

    $this->withoutMiddleware();
    $this->actingAs(apUser('outsider'), 'sanctum')->getJson($url)->assertStatus(403);
    $this->actingAs(apUser('viewer', ['workflow_project.view']), 'sanctum');
    $this->getJson("/api/v2/admin/workflows/{$project->id}/steps/nope/auto-approval")->assertNotFound();

    $data = $this->getJson($url)->assertOk()->json('data');
    expect(array_keys($data))->toBe(['available', 'mode', 'min_confidence', 'source', 'decided', 'required', 'agreed', 'disagreed', 'agreement_rate', 'minimum_agreement', 'approved_after_edit', 'excluded', 'ready', 'paused', 'estimate', 'last_30_days'])
        ->and($data['available'])->toBeTrue()
        ->and($data['mode'])->toBe('human')
        ->and($data['min_confidence'])->toEqual(0.9)
        ->and($data['source'])->toBe('core.step_approvals')
        ->and($data['decided'])->toBe(4)
        ->and($data['required'])->toBe(4)
        ->and($data['agreed'])->toBe(3)
        ->and($data['disagreed'])->toBe(1)
        ->and($data['agreement_rate'])->toEqual(0.75)
        ->and($data['ready'])->toBeFalse()
        ->and($data['paused'])->toBeFalse()
        // Probabilities alternate 0.95 / 0.7: two of four reach 0.8 and 0.9, all four reach 0.6.
        ->and($data['estimate'])->toBe(['0.6' => 4, '0.8' => 2, '0.9' => 2, 'of' => 4])
        ->and($data['last_30_days'])->toBe(['auto' => 0, 'human' => 4, 'withdrawn' => 0]);

    $plain = apProject(['mode' => 'human']);
    expect($this->getJson("/api/v2/admin/workflows/{$plain->id}/steps/ask/auto-approval")->json('data.available'))->toBeFalse();
});

it('refuses switching auto-publish on before the calibration is ready', function () use ($auto): void {
    $project = apProject(['mode' => 'human', 'jev' => $auto['jev']]);
    apLearn($project, 3, 1);
    $policy = app(WorkflowPublishPolicy::class);
    $graph = fn (array $config): array => ['steps' => [
        ['step_code' => 'setjev', 'step_type' => 'set_variable', 'step_config' => ['assignments' => []], 'is_start' => true],
        ['step_code' => 'ask', 'step_type' => 'approval', 'step_config' => $config + ['payload' => "'x'"]],
    ], 'connections' => [['from' => 'setjev', 'to' => 'ask']]];
    $codes = fn (array $problems): array => array_column($problems, 'code');

    $problems = $policy->draftProblems($graph($auto), null, $project);
    expect($codes($problems))->toContain('auto_approval_not_ready')
        ->and(collect($problems)->firstWhere('code', 'auto_approval_not_ready')['message'])->toContain('4 decisions')
        // A new workflow has no decisions of its own.
        ->and($codes($policy->draftProblems($graph($auto), null, null)))->toContain('auto_approval_not_ready')
        // Learning is always allowed.
        ->and($codes($policy->draftProblems($graph(['mode' => 'human', 'jev' => $auto['jev']]), null, $project)))->not->toContain('auto_approval_not_ready');

    apLearn($project, 4, 0); // latest... overall 7 of 8 agree = 0.875, still below 0.9
    expect($codes($policy->draftProblems($graph($auto), null, $project)))->toContain('auto_approval_not_ready');
    apLearn($project, 4, 0); // 11 of 12 = 0.9167
    expect($codes($policy->draftProblems($graph($auto), null, $project)))->not->toContain('auto_approval_not_ready');
});

it('exposes the held reason and the JEV verdict in the approvals inbox', function () use ($auto): void {
    $project = apProject(['mode' => 'human', 'jev' => $auto['jev']]);
    apRun($project, AP_PASS);

    $this->withoutMiddleware();
    $this->actingAs(apUser('approver', ['agentic.viewAny', 'agentic.approve']), 'sanctum');
    $item = $this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data.0');

    expect($item['held_reason'])->toBe('learning')
        ->and($item['jev'])->toEqual(['verdict' => 'pass', 'probability' => 0.92, 'min_confidence' => 0.8])
        ->and($item['decided_by'])->toBeNull();
});
