<?php

use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Services\Agentic\AgentArtifactApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * users who may only view agent approvals must not receive record data
 * (artifact payload, original data snapshot, diff), and every apply must record
 * who applied it plus exactly one audit log entry.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();

    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    makeAgenticExposureUser('placeholder');
});

/**
 * Create a plain (non-webmaster) admin user.
 */
function makeAgenticExposureUser(string $name): User
{
    return User::query()->forceCreate([
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
}

/**
 * Grant the given "group.name" permission keys to a user through a fresh role.
 *
 * @param array<int, string> $keys
 */
function grantAgenticExposurePermissions(User $user, array $keys): void
{
    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);

    foreach ($keys as $key) {
        [$group, $name] = explode('.', $key, 2);
        $permission = Permission::query()->firstOrCreate(
            ['group' => $group, 'name' => $name],
            ['title' => $key],
        );
        $role->permissions()->attach($permission);
    }

    $user->roles()->attach($role);
}

/**
 * Seed a pending approval with record data in payload, snapshot and diff.
 */
function seedAgenticExposureApproval(): AgentApproval
{
    $profile = AgentProfile::query()->create([
        'name' => 'Content Proposer',
        'slug' => 'content-proposer-' . Str::lower(Str::random(4)),
        'capability_class' => 'class_2_staged_mutation',
        'status' => 'active',
    ]);

    $run = AgentRun::query()->create([
        'agent_profile_id' => $profile->id,
        'module_scope' => 'content',
        'target_type' => 'post',
        'target_id' => '77',
        'status' => 'awaiting_approval',
        'input_goal' => 'Refresh post description',
        'started_at' => now(),
    ]);

    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'content_post_description_patch',
        'subject_type' => 'post',
        'subject_id' => '77',
        'source_snapshot_ref' => 'content.post:77',
        'original_data_snapshot' => ['description' => 'SECRET original description.'],
        'payload' => ['proposed_change' => ['description' => 'SECRET new description.']],
        'storage_driver' => 'database',
        'version' => 1,
        'render_hint' => 'text_diff',
        'apply_contract_version' => 'v1',
    ]);

    return AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'pending',
        'diff_data' => ['before' => 'SECRET original description.', 'after' => 'SECRET new description.'],
    ]);
}

test('view-only user gets approval list and detail without record data', function () {
    $viewer = makeAgenticExposureUser('viewer');
    grantAgenticExposurePermissions($viewer, ['agentic.viewAny']);
    $approval = seedAgenticExposureApproval();
    $this->actingAs($viewer, 'sanctum');

    $list = $this->getJson('/api/v2/admin/agentic/approvals')->assertOk();
    $list->assertJsonPath('data.data.0.id', $approval->id)
        ->assertJsonPath('data.data.0.status', 'pending')
        ->assertJsonPath('data.data.0.artifact.artifact_type', 'content_post_description_patch')
        ->assertJsonPath('data.data.0.artifact.payload', null)
        ->assertJsonPath('data.data.0.artifact.original_data_snapshot', null)
        ->assertJsonPath('data.data.0.diff_data', null);
    expect($list->getContent())->not->toContain('SECRET');

    $detail = $this->getJson("/api/v2/admin/agentic/approvals/{$approval->id}")->assertOk();
    $detail->assertJsonPath('data.id', $approval->id)
        ->assertJsonPath('data.artifact.payload', null)
        ->assertJsonPath('data.artifact.original_data_snapshot', null)
        ->assertJsonPath('data.diff_data', null);
    expect($detail->getContent())->not->toContain('SECRET');
});

test('approver gets approval list and detail with record data', function () {
    $approver = makeAgenticExposureUser('approver');
    grantAgenticExposurePermissions($approver, ['agentic.viewAny', 'agentic.approve']);
    $approval = seedAgenticExposureApproval();
    $this->actingAs($approver, 'sanctum');

    $this->getJson('/api/v2/admin/agentic/approvals')
        ->assertOk()
        ->assertJsonPath('data.data.0.artifact.payload.proposed_change.description', 'SECRET new description.')
        ->assertJsonPath('data.data.0.artifact.original_data_snapshot.description', 'SECRET original description.')
        ->assertJsonPath('data.data.0.diff_data.after', 'SECRET new description.');

    $this->getJson("/api/v2/admin/agentic/approvals/{$approval->id}")
        ->assertOk()
        ->assertJsonPath('data.artifact.payload.proposed_change.description', 'SECRET new description.')
        ->assertJsonPath('data.diff_data.before', 'SECRET original description.');
});

test('apply by an approver stores the applier and writes exactly one audit entry', function () {
    $approver = makeAgenticExposureUser('applier');
    grantAgenticExposurePermissions($approver, ['agentic.viewAny', 'agentic.approve']);
    $approval = seedAgenticExposureApproval();
    $approval->forceFill(['status' => 'approved', 'apply_outcome' => 'awaiting_apply'])->save();

    $applyService = Mockery::mock(AgentArtifactApplyService::class);
    $applyService->shouldReceive('apply')->once()->andReturn(['message' => 'ok']);
    $this->app->instance(AgentArtifactApplyService::class, $applyService);

    $this->actingAs($approver, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.apply_outcome', 'applied')
        ->assertJsonPath('data.applied_by.id', $approver->id);

    expect($approval->fresh()->applied_by_user_id)->toBe($approver->id);

    $entries = AuditLog::query()
        ->where('target_type', AgentApproval::class)
        ->where('target_id', $approval->id)
        ->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->user_id)->toBe($approver->id)
        ->and($entries->first()->action)->toBe('action.agentic-approval.apply')
        ->and(data_get($entries->first()->changed, 'apply_outcome'))->toBe('applied');
});
