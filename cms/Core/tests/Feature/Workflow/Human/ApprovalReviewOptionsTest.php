<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Approval review options (2026-11-24): a reviewer may send a pending approval back for a rewrite
 * (`changes_requested`); the approval node routes it back to the drafting step and the next visit opens a new round.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function aroUser(string $name, array $permissions = []): User
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
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/**
 * draft (set_variable) → ask (approval); ask: approved → ok, rejected → no, changes_requested → draft (when $loop).
 */
function aroRun(array $askConfig, User $user, bool $loop = true): WorkflowInstance
{
    $project = WorkflowProject::query()->create(['name' => 'Review options', 'slug' => 'review-options-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $steps = [
        'draft' => ['set_variable', ['assignments' => [['key' => 'vars.drafted', 'valueExpr' => "'yes'"]]]],
        'ask' => ['approval', $askConfig + ['title' => 'Check', 'payload' => "{'headline': 'Old'}", 'notify' => false]],
        'ok' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]],
        'no' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'no'"]]]],
    ];
    $made = [];
    $x = 1;
    foreach ($steps as $code => [$type, $config]) {
        $made[$code] = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_type' => $type, 'step_code' => $code,
            'step_config' => $config, 'position_x' => $x, 'is_start' => $x === 1,
        ]);
        $x++;
    }
    $edges = [['draft', 'ask', null], ['ask', 'ok', 'approved'], ['ask', 'no', 'rejected']];
    if ($loop) {
        $edges[] = ['ask', 'draft', 'changes_requested'];
    }
    foreach ($edges as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => $user->id, 'status' => 'pending', 'delegated_by_user_id' => $user->id]);
    app(WorkflowGraphExecutor::class)->execute($instance->id);

    return $instance->fresh();
}

function aroRuns(WorkflowInstance $instance, string $code): int
{
    $step = WorkflowStep::query()->where('project_id', $instance->project_id)->where('step_code', $code)->first();

    return WorkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $step?->id)->where('status', 'success')->count();
}

function aroOptions(): array
{
    return [[
        'key' => 'length',
        'label_key' => 'text.test.length',
        'choices' => [['value' => 'shorter', 'label_key' => 'text.test.shorter'], ['value' => 'longer', 'label_key' => 'text.test.longer']],
    ]];
}

beforeEach(function (): void {
    // Same SQLite fix as WorkflowHumanNodesTest: the instance-step table must not keep its stale foreign key.
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
    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    aroUser('placeholder');
});

it('requests changes and resumes the workflow at the producing step', function (): void {
    $user = aroUser('editor');
    $instance = aroRun(['revision_options' => aroOptions(), 'max_rounds' => 3], $user);
    $first = AgentApproval::query()->latest('id')->firstOrFail();

    expect(data_get($first->diff_data, 'workflow_node.revisable'))->toBeTrue()
        ->and(data_get($first->diff_data, 'workflow_node.round'))->toBe(1)
        ->and(data_get($first->diff_data, 'workflow_node.max_rounds'))->toBe(3)
        ->and(data_get($first->diff_data, 'workflow_node.revision_options.0.key'))->toBe('length');

    $result = app(AgentApprovalService::class)->requestChanges($first, $user, 'Make it shorter', 'Built in 1880', ['length' => 'shorter']);
    $first->refresh();
    $instance->refresh();
    $second = AgentApproval::query()->latest('id')->firstOrFail();

    expect($result['resume_workflow_instance_id'])->toBe($instance->id)
        ->and($first->status)->toBe('changes_requested')
        ->and($first->apply_outcome)->toBe('changes_requested')
        ->and($first->reviewer_note)->toBe('Make it shorter')
        ->and($first->reviewed_by_user_id)->toBe($user->id)
        ->and(data_get($first->diff_data, 'workflow_node.decided_by'))->toBe('human')
        ->and(data_get($first->diff_data, 'workflow_node.revision_request.options'))->toBe(['length' => 'shorter'])
        ->and(data_get($first->diff_data, 'workflow_node.revision_request.added_info'))->toBe('Built in 1880')
        ->and(data_get($first->diff_data, 'workflow_node.next_approval_id'))->toBe($second->id)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe('pending')
        ->and(data_get($second->diff_data, 'workflow_node.round'))->toBe(2)
        ->and(data_get($second->diff_data, 'workflow_node.previous_approval_id'))->toBe($first->id)
        ->and($instance->status)->toBe('awaiting_approval')
        ->and(aroRuns($instance, 'draft'))->toBe(2)
        ->and(data_get($instance->context_snapshot, 'vars.approval.decision'))->toBe('changes_requested')
        ->and(data_get($instance->context_snapshot, 'vars.approval.feedback'))->toBe('Make it shorter')
        ->and(data_get($instance->context_snapshot, 'vars.approval.added_info'))->toBe('Built in 1880')
        ->and(data_get($instance->context_snapshot, 'vars.approval.options'))->toBe(['length' => 'shorter'])
        ->and(data_get($instance->context_snapshot, 'vars.approval.round'))->toBe(1)
        ->and(data_get($instance->context_snapshot, 'vars.approval.previous_payload.data.headline'))->toBe('Old');
});

it('runs a draft, approval, changes_requested, draft, approval loop to the end', function (): void {
    $user = aroUser('editor');
    $instance = aroRun([], $user);
    $service = app(AgentApprovalService::class);

    $service->requestChanges(AgentApproval::query()->latest('id')->firstOrFail(), $user, 'Try again', null, []);
    $service->requestChanges(AgentApproval::query()->latest('id')->firstOrFail(), $user, 'Once more', null, []);
    $third = AgentApproval::query()->latest('id')->firstOrFail();
    expect(data_get($third->diff_data, 'workflow_node.round'))->toBe(3)
        // Default cap is 3 drafts: the third offers no further rewrite.
        ->and(data_get($third->diff_data, 'workflow_node.revisable'))->toBeFalse();

    $service->approveAndApply($third, $user);
    $instance->refresh();

    expect($instance->status)->toBe('completed')
        ->and(data_get($instance->context_snapshot, 'vars.went'))->toBe('ok')
        ->and(aroRuns($instance, 'draft'))->toBe(3)
        ->and(AgentApproval::query()->count())->toBe(3);
});

it('refuses when not revisable', function (): void {
    $user = aroUser('editor');
    aroRun([], $user, loop: false);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    expect(data_get($approval->diff_data, 'workflow_node.revisable'))->toBeFalse()
        ->and(fn () => app(AgentApprovalService::class)->requestChanges($approval, $user, 'Shorter', null, []))
        ->toThrow(InvalidArgumentException::class, 'cannot be sent back');
    expect($approval->fresh()->status)->toBe('pending');
});

it('refuses when the workflow no longer waits for the draft', function (): void {
    $user = aroUser('editor');
    $instance = aroRun([], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $instance->forceFill(['status' => 'cancelled'])->save();

    expect(fn () => app(AgentApprovalService::class)->requestChanges($approval, $user, 'Shorter', null, []))
        ->toThrow(InvalidArgumentException::class, 'no longer waiting');
    expect($approval->fresh()->status)->toBe('pending');
});

it('refuses at the round cap', function (): void {
    $user = aroUser('editor');
    aroRun(['max_rounds' => 2], $user);
    $service = app(AgentApprovalService::class);
    $service->requestChanges(AgentApproval::query()->latest('id')->firstOrFail(), $user, 'Shorter', null, []);
    $second = AgentApproval::query()->latest('id')->firstOrFail();

    expect(data_get($second->diff_data, 'workflow_node.revisable'))->toBeFalse();

    // Even a row whose flag was tampered with stays capped by its round.
    $diff = $second->diff_data;
    $diff['workflow_node']['revisable'] = true;
    $second->forceFill(['diff_data' => $diff])->save();

    expect(fn () => $service->requestChanges($second, $user, 'Shorter still', null, []))
        ->toThrow(InvalidArgumentException::class, 'last allowed draft');
    expect($second->fresh()->status)->toBe('pending');
});

it('refuses an undeclared option', function (): void {
    $user = aroUser('editor');
    aroRun(['revision_options' => aroOptions()], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $service = app(AgentApprovalService::class);

    expect(fn () => $service->requestChanges($approval, $user, 'Shorter', null, ['tone' => 'warm']))
        ->toThrow(InvalidArgumentException::class, 'not offered')
        ->and(fn () => $service->requestChanges($approval, $user, 'Shorter', null, ['length' => 'huge']))
        ->toThrow(InvalidArgumentException::class, 'no such choice');
    expect($approval->fresh()->status)->toBe('pending');
});

it("refuses outside the reviewer's data scope", function (): void {
    $user = aroUser('editor');
    $outsider = aroUser('outsider');
    aroRun([], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    // A service whose data scope lets only the delegating user reach the subject.
    $service = new class(app(\Cms\Core\Services\Agentic\AgentArtifactApplyService::class), app(\Cms\Core\Services\Agentic\AgentFeedbackNormalizer::class), app(\Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService::class)) extends AgentApprovalService {
        public ?int $allowed = null;

        public function allowsSubject(AgentApproval $approval, ?User $user, bool $write = false): bool
        {
            return $user !== null && $user->id === $this->allowed;
        }
    };
    $service->allowed = $user->id;

    expect(fn () => $service->requestChanges($approval, $outsider, 'Shorter', null, []))
        ->toThrow(InvalidArgumentException::class, AgentApprovalService::SCOPE_DENIED);
    expect($approval->fresh()->status)->toBe('pending');
});

it('refuses a non-pending approval', function (): void {
    $user = aroUser('editor');
    aroRun([], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $service = app(AgentApprovalService::class);
    $service->reject($approval, $user, 'No');

    expect(fn () => $service->requestChanges($approval, $user, 'Shorter', null, []))
        ->toThrow(InvalidArgumentException::class, 'not pending');
    expect($approval->fresh()->status)->toBe('rejected');
});

it('leaves changes_requested rows alone on expiry', function (): void {
    $user = aroUser('editor');
    aroRun(['expires_after' => '1h'], $user);
    $first = AgentApproval::query()->latest('id')->firstOrFail();
    $service = app(AgentApprovalService::class);
    $service->requestChanges($first, $user, 'Shorter', null, []);

    $this->travel(2)->hours();

    expect($service->expireIfDue($first->fresh()))->toBeFalse()
        ->and($first->fresh()->status)->toBe('changes_requested');
    $service->expireDueWorkflowApprovals();
    expect($first->fresh()->status)->toBe('changes_requested');
});

it('show lists the revision history oldest first', function (): void {
    $user = aroUser('editor', ['agentic.viewAny', 'agentic.approve']);
    aroRun(['revision_options' => aroOptions()], aroUser('starter')); // the run's own user never decides it (review fix 7)
    $first = AgentApproval::query()->latest('id')->firstOrFail();
    $this->withoutMiddleware();
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$first->id}/request-changes", ['feedback' => 'x'])
        ->assertStatus(422);
    $this->postJson("/api/v2/admin/agentic/approvals/{$first->id}/request-changes", ['feedback' => 'Shorter', 'options' => ['length' => 'huge']])
        ->assertStatus(422);

    $response = $this->postJson("/api/v2/admin/agentic/approvals/{$first->id}/request-changes", [
        'feedback' => 'Make it shorter',
        'added_info' => 'Built in 1880',
        'options' => ['length' => 'shorter'],
    ])->assertOk();
    expect($response->json('data.status'))->toBe('changes_requested');

    $second = AgentApproval::query()->latest('id')->firstOrFail();
    $history = $this->getJson("/api/v2/admin/agentic/approvals/{$second->id}")->assertOk()->json('data.revision_history');

    expect(array_column($history, 'approval_id'))->toBe([$first->id, $second->id])
        ->and($history[0]['round'])->toBe(1)
        ->and($history[0]['status'])->toBe('changes_requested')
        ->and($history[0]['feedback'])->toBe('Make it shorter')
        ->and($history[0]['added_info'])->toBe('Built in 1880')
        ->and($history[0]['options'])->toBe(['length' => 'shorter'])
        ->and($history[0]['reviewer_name'])->toBe($user->name)
        ->and($history[1]['round'])->toBe(2)
        ->and($history[1]['status'])->toBe('pending');
});

it('revision_model_choice adds a model option with selectable choices', function (): void {
    foreach (array_keys(\Cms\Core\Services\Ai\AiRuntimeSettingsService::DRIVERS) as $driver) {
        config()->set("core.ai_structured_extraction.providers.{$driver}.api_key", '');
    }
    config()->set('core.ai_structured_extraction.providers.anthropic.api_key', 'sk-test');
    $user = aroUser('editor');
    aroRun(['revision_options' => aroOptions(), 'revision_model_choice' => true], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $options = data_get($approval->diff_data, 'workflow_node.revision_options');

    expect(array_column($options, 'key'))->toBe(['length', 'model'])
        ->and($options[1]['type'])->toBe('model')
        ->and($options[1]['label_key'])->toBe('text.agentic.rewrite.model_option')
        ->and(array_column($options[1]['choices'], 'value'))->toContain('anthropic:claude-haiku-5-5')
        ->and(collect($options[1]['choices'])->firstWhere('value', 'anthropic:claude-haiku-5-5')['label'])->toBe('Anthropic (Claude) · claude-haiku-5-5')
        ->and(array_filter(array_column($options[1]['choices'], 'value'), static fn (string $v): bool => !str_starts_with($v, 'anthropic:')))->toBe([]);

    app(AgentApprovalService::class)->requestChanges($approval, $user, 'Use another model', null, ['model' => 'anthropic:claude-haiku-5-5']);
    $instance = WorkflowInstance::query()->latest('id')->firstOrFail();
    expect(data_get($instance->context_snapshot, 'vars.approval.options'))->toBe(['model' => 'anthropic:claude-haiku-5-5']);
});

function aroReasons(): array
{
    return [['key' => 'wrong_info', 'label_key' => 'text.test.wrong'], ['key' => 'off_topic', 'label' => 'Off topic'], ['key' => 'Bad Key!']];
}

it('reject requires a declared reason when reasons exist', function (): void {
    $user = aroUser('editor', ['agentic.viewAny', 'agentic.approve']);
    aroRun(['reject_reasons' => aroReasons()], aroUser('starter')); // the run's own user never decides it (review fix 7)
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $this->withoutMiddleware();
    $this->actingAs($user, 'sanctum');

    // Malformed keys are dropped; plain labels from the builder are kept.
    expect(data_get($approval->diff_data, 'workflow_node.reject_reasons'))->toBe([
        ['key' => 'wrong_info', 'label_key' => 'text.test.wrong', 'label' => null],
        ['key' => 'off_topic', 'label_key' => null, 'label' => 'Off topic'],
    ]);

    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject", ['reviewer_note' => 'No'])->assertStatus(422);
    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject", ['reason' => 'made_up'])->assertStatus(422);
    expect($approval->fresh()->status)->toBe('pending');

    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject", ['reason' => 'off_topic', 'reviewer_note' => 'Not ours'])->assertOk();
    $approval->refresh();
    expect($approval->status)->toBe('rejected')
        ->and($approval->reviewer_note)->toBe('Not ours')
        ->and(data_get($approval->diff_data, 'workflow_node.reject_reason'))->toBe('off_topic')
        ->and(\Cms\Core\Models\Agents\AgentMemory::query()->latest('id')->value('content'))->toBe('Not ours (reject reason: off_topic)');

    $history = $this->getJson("/api/v2/admin/agentic/approvals/{$approval->id}")->assertOk()->json('data.revision_history');
    expect($history[0]['reject_reason'])->toBe('off_topic');
});

it('reject refuses a reason when none are declared', function (): void {
    $user = aroUser('editor', ['agentic.viewAny', 'agentic.approve']);
    aroRun([], aroUser('starter')); // the run's own user never decides it (review fix 7)
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $this->withoutMiddleware();
    $this->actingAs($user, 'sanctum');

    expect(data_get($approval->diff_data, 'workflow_node.reject_reasons'))->toBe([]);
    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject", ['reason' => 'wrong_info'])->assertStatus(422);
    expect($approval->fresh()->status)->toBe('pending');

    // The plain reject keeps working.
    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject", ['reviewer_note' => 'No'])->assertOk();
    expect($approval->fresh()->status)->toBe('rejected')
        ->and(data_get($approval->fresh()->diff_data, 'workflow_node.reject_reason'))->toBeNull();
});

it('old callers of reject() still work', function (): void {
    $user = aroUser('editor');
    $instance = aroRun(['reject_reasons' => aroReasons()], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    app(AgentApprovalService::class)->reject($approval, $user, 'Closed from the queue');
    $instance->refresh();

    expect($approval->fresh()->status)->toBe('rejected')
        ->and(data_get($instance->context_snapshot, 'vars.went'))->toBe('no')
        ->and(data_get($instance->context_snapshot, 'vars.approval.reason'))->toBeNull();
});

it('approval node exposes vars.approval.reason', function (): void {
    $user = aroUser('editor');
    $instance = aroRun(['reject_reasons' => aroReasons()], $user);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    expect(fn () => app(AgentApprovalService::class)->reject($approval, $user, null, 'made_up'))
        ->toThrow(InvalidArgumentException::class, 'not offered');
    app(AgentApprovalService::class)->reject($approval, $user, 'Wrong facts', 'wrong_info');
    $instance->refresh();

    expect($instance->status)->toBe('completed')
        ->and(data_get($instance->context_snapshot, 'vars.went'))->toBe('no')
        ->and(data_get($instance->context_snapshot, 'vars.approval.decision'))->toBe('rejected')
        ->and(data_get($instance->context_snapshot, 'vars.approval.reason'))->toBe('wrong_info')
        ->and(data_get($instance->context_snapshot, 'vars.approval.note'))->toBe('Wrong facts');
});
