<?php

use App\Models\User;
use Cms\Core\Agents\Contracts\ArtifactApplierInterface;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Services\Agentic\Exceptions\ApprovalStaleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Approve and apply an agent proposal in one call.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
    Queue::fake();
    ApproveApplyTestApplier::$calls = 0;
    ApproveApplyTestApplier::$stale = false;
    config(['core.agentic.artifact_appliers.test_approve_apply_patch' => ApproveApplyTestApplier::class]);

    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    makeApproveApplyUser('placeholder');
});

class ApproveApplyTestApplier implements ArtifactApplierInterface
{
    public static int $calls = 0;

    public static bool $stale = false;

    public function apply(AgentArtifact $artifact, AgentApproval $approval): array
    {
        if (self::$stale) {
            throw new ApprovalStaleException('The record changed since the proposal.');
        }

        self::$calls++;

        return ['message' => 'applied'];
    }
}

function makeApproveApplyUser(string $name, array $permissions = []): User
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
            $role->permissions()->attach(Permission::query()->firstOrCreate(
                ['group' => $group, 'name' => $ability],
                ['title' => $key],
            ));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/**
 * @return array{approval: AgentApproval, instance: WorkflowInstance, run: AgentRun}
 */
function seedApproveApplyGraph(): array
{
    $profile = AgentProfile::query()->create([
        'name' => 'Story editor',
        'slug' => 'story-editor-' . Str::lower(Str::random(4)),
        'capability_class' => 'class_3_autonomous_maintenance',
        'status' => 'active',
    ]);

    $instance = WorkflowInstance::query()->create([
        'target_type' => 'test_subject',
        'status' => 'awaiting_approval',
        'resume_step_id' => 42,
    ]);

    $run = AgentRun::query()->create([
        'agent_profile_id' => $profile->id,
        'workflow_instance_id' => $instance->id,
        'module_scope' => 'test',
        'target_type' => 'test_subject',
        'target_id' => '7',
        'status' => 'awaiting_approval',
        'input_goal' => 'Write a story',
        'started_at' => now(),
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'test_approve_apply_patch',
        'subject_type' => 'test_subject',
        'subject_id' => '7',
        'source_snapshot_ref' => 'test_subject:7',
        'original_data_snapshot' => ['body' => 'old'],
        'payload' => ['proposed_change' => ['body' => 'new']],
        'storage_driver' => 'database',
        'version' => 1,
        'render_hint' => 'text_diff',
        'apply_contract_version' => 'v1',
    ]);

    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'pending',
        'diff_data' => ['before' => 'old', 'after' => 'new'],
    ]);

    return compact('approval', 'instance', 'run');
}

test('a pending approval is approved and applied in one call and the workflow resumes', function () {
    $approver = makeApproveApplyUser('approver', ['agentic.viewAny', 'agentic.approve']);
    $graph = seedApproveApplyGraph();
    $this->actingAs($approver, 'sanctum');

    $response = $this->postJson("/api/v2/admin/agentic/approvals/{$graph['approval']->id}/approve-apply", [
        'reviewer_note' => 'Good story.',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.outcome', 'applied')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.apply_outcome', 'applied')
        ->assertJsonPath('data.reviewer_note', 'Good story.')
        ->assertJsonPath('data.reviewer.id', $approver->id)
        ->assertJsonPath('data.applied_by.id', $approver->id);

    expect(ApproveApplyTestApplier::$calls)->toBe(1);
    Queue::assertPushed(RunWorkflowInstanceJob::class, fn (RunWorkflowInstanceJob $job) => $job->workflowInstanceId() === $graph['instance']->id);
});

test('a stale record leaves the approval approved but not applied, with the reason', function () {
    ApproveApplyTestApplier::$stale = true;
    $approver = makeApproveApplyUser('approver', ['agentic.viewAny', 'agentic.approve']);
    $graph = seedApproveApplyGraph();
    $this->actingAs($approver, 'sanctum');

    $response = $this->postJson("/api/v2/admin/agentic/approvals/{$graph['approval']->id}/approve-apply");

    $response->assertOk()
        ->assertJsonPath('data.outcome', 'approval_stale')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.apply_outcome', 'approval_stale')
        ->assertJsonPath('data.apply_error', 'The record changed since the proposal.');

    $approval = $graph['approval']->fresh();
    expect($approval->status)->toBe('approved')
        ->and($approval->apply_outcome)->toBe('approval_stale')
        ->and(ApproveApplyTestApplier::$calls)->toBe(0);
    Queue::assertNotPushed(RunWorkflowInstanceJob::class);
});

test('a user without the approve permission gets 403 and nothing changes', function () {
    $viewer = makeApproveApplyUser('viewer', ['agentic.viewAny']);
    $graph = seedApproveApplyGraph();
    $this->actingAs($viewer, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$graph['approval']->id}/approve-apply")
        ->assertStatus(403);

    expect($graph['approval']->fresh()->status)->toBe('pending')
        ->and(ApproveApplyTestApplier::$calls)->toBe(0);
});

test('an approval that is no longer pending is refused', function () {
    $approver = makeApproveApplyUser('approver', ['agentic.viewAny', 'agentic.approve']);
    $graph = seedApproveApplyGraph();
    $graph['approval']->update(['status' => 'rejected', 'apply_outcome' => 'approval_rejected']);
    $this->actingAs($approver, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$graph['approval']->id}/approve-apply")
        ->assertStatus(400);

    expect(ApproveApplyTestApplier::$calls)->toBe(0);
});

test('an unknown approval answers 404', function () {
    $this->actingAs(makeApproveApplyUser('approver', ['agentic.viewAny', 'agentic.approve']), 'sanctum');

    $this->postJson('/api/v2/admin/agentic/approvals/999999/approve-apply')->assertStatus(404);
});
