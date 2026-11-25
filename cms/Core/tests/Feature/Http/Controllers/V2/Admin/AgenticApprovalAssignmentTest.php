<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Approval assignee and SLA API (2026-11-24): the `assignment` block of the approval resource, the `assigned=me` and
 * `overdue=1` filters, the SLA summary, and the save check of assignee uuids.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function aaaUser(string $name, array $permissions = ['agentic.viewAny', 'agentic.approve']): User
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
 * A workflow-node approval with the given timing columns and assignees.
 *
 * @param array<string, mixed> $columns
 * @param list<User>           $assignees
 */
function aaaApproval(array $columns = [], array $assignees = []): AgentApproval
{
    $run = AgentRun::query()->create([
        'trigger_mode' => 'workflow_node',
        'module_scope' => 'workflow',
        'status' => 'awaiting_approval',
        'input_goal' => 'Check',
        'started_at' => now(),
    ]);
    $artifact = AgentArtifact::query()->create([
        'run_id' => $run->id,
        'artifact_type' => 'workflow.approval',
        'source_snapshot_ref' => 'workflow-step:ask',
        'payload' => ['summary' => 'Check'],
        'storage_driver' => 'database',
        'version' => 1,
        'render_hint' => 'json_view',
        'apply_contract_version' => 'v1',
    ]);
    $approval = AgentApproval::query()->create([
        'run_id' => $run->id,
        'artifact_id' => $artifact->id,
        'status' => 'pending',
        'diff_data' => ['workflow_node' => ['kind' => 'approval', 'mode' => 'human', 'title' => 'Check', 'decided_by' => null]],
    ]);
    $approval->forceFill($columns)->save();
    foreach ($assignees as $user) {
        DB::table(CoreTableConstant::AGENT_APPROVAL_ASSIGNEE)->insert(['approval_id' => $approval->id, 'user_id' => $user->id, 'created_at' => now()]);
    }

    return $approval->fresh();
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    aaaUser('placeholder', []);
});

it('index exposes assignment', function (): void {
    $me = aaaUser('me');
    $other = aaaUser('other');
    $due = now()->addHours(2);
    $assigned = aaaApproval(['sla_seconds' => 4 * 3600, 'due_at' => $due], [$me]);
    $decided = aaaApproval(['status' => 'rejected', 'sla_seconds' => 3600, 'due_at' => now()->subHour(), 'decided_at' => now(),
        'response_seconds' => 1800, 'sla_met' => true, 'decided_by_user_id' => $other->id], [$me]);
    $plain = aaaApproval();
    $this->actingAs($me, 'sanctum');

    $rows = collect($this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data'))->keyBy('id');

    expect($rows[$assigned->id]['assignment'])->toBe([
        'assignees' => [['uuid' => $me->uuid, 'name' => 'me']],
        'assigned_to_me' => true,
        'due_at' => $due->copy()->startOfSecond()->toJSON(),
        'sla_seconds' => 14400,
        'decided_at' => null,
        'response_seconds' => null,
        'sla_met' => null,
        'overdue' => false,
        'breached_at' => null,
        'decided_by' => null,
        'fallback' => false,
    ])
        ->and($rows[$decided->id]['assignment']['decided_by'])->toBe(['uuid' => $other->uuid, 'name' => 'other'])
        ->and($rows[$decided->id]['assignment']['sla_met'])->toBeTrue()
        ->and($rows[$decided->id]['assignment']['response_seconds'])->toBe(1800)
        ->and($rows[$decided->id]['assignment']['overdue'])->toBeFalse()
        // Approvals without assignment (and rows from before the migration) carry no block.
        ->and($rows[$plain->id]['assignment'])->toBeNull();

    $this->getJson("/api/v2/admin/agentic/approvals/{$assigned->id}")->assertOk()
        ->assertJsonPath('data.assignment.assigned_to_me', true)
        ->assertJsonPath('data.assignment.sla_seconds', 14400);
});

it('assigned=me and overdue=1 filter', function (): void {
    $me = aaaUser('me');
    $other = aaaUser('other');
    $mine = aaaApproval(['sla_seconds' => 3600, 'due_at' => now()->addHour()], [$me]);
    $mineLate = aaaApproval(['sla_seconds' => 3600, 'due_at' => now()->subMinute()], [$me]);
    $theirsLate = aaaApproval(['sla_seconds' => 3600, 'due_at' => now()->subHour()], [$other]);
    aaaApproval(['status' => 'rejected', 'sla_seconds' => 3600, 'due_at' => now()->subHour(), 'decided_at' => now()], [$me]);
    aaaApproval();
    $this->actingAs($me, 'sanctum');

    $ids = fn (string $query): array => collect($this->getJson('/api/v2/admin/agentic/approvals?' . $query)->assertOk()->json('data.data'))
        ->pluck('id')->sort()->values()->all();

    expect($ids('assigned=me&status=pending'))->toBe([$mine->id, $mineLate->id])
        ->and($ids('overdue=1'))->toBe([$mineLate->id, $theirsLate->id])
        ->and($ids('assigned=me&overdue=1'))->toBe([$mineLate->id]);
});

it('sla-summary counts within, late and overdue per reviewer', function (): void {
    $ann = aaaUser('ann');
    $bob = aaaUser('bob');
    // Ann: decided two herself (600 s within, 7200 s late), one decided by Bob within, one overdue now, one expired.
    aaaApproval(['status' => 'approved', 'sla_seconds' => 3600, 'due_at' => now(), 'decided_at' => now(), 'response_seconds' => 600, 'sla_met' => true, 'decided_by_user_id' => $ann->id], [$ann]);
    aaaApproval(['status' => 'rejected', 'sla_seconds' => 3600, 'due_at' => now(), 'decided_at' => now(), 'response_seconds' => 7200, 'sla_met' => false, 'decided_by_user_id' => $ann->id], [$ann]);
    aaaApproval(['status' => 'approved', 'sla_seconds' => 3600, 'due_at' => now(), 'decided_at' => now(), 'response_seconds' => 300, 'sla_met' => true, 'decided_by_user_id' => $bob->id], [$ann, $bob]);
    aaaApproval(['sla_seconds' => 3600, 'due_at' => now()->subMinutes(5)], [$ann]);
    aaaApproval(['status' => 'expired', 'sla_seconds' => 3600, 'due_at' => now()->subHours(3), 'sla_met' => false], [$ann]);
    // Outside the range and an unassigned approval: not counted.
    aaaApproval(['sla_seconds' => 3600, 'due_at' => now()], [$ann])->forceFill(['created_at' => now()->subDays(40)])->save();
    aaaApproval();
    $this->actingAs($bob, 'sanctum');

    $data = $this->getJson('/api/v2/admin/agentic/approvals/sla-summary')->assertOk()->json('data');

    expect($data['totals'])->toBe([
        'assigned' => 5, 'decided' => 3, 'within_sla' => 2, 'late' => 2, 'overdue_now' => 1, 'median_response_seconds' => 600,
    ])
        ->and($data['truncated'])->toBeFalse()
        ->and($data['reviewers'][0])->toBe([
            'uuid' => $ann->uuid, 'name' => 'ann', 'assigned' => 5, 'decided_by_them' => 2, 'decided_by_others' => 1,
            'within_sla' => 2, 'late' => 2, 'overdue_now' => 1, 'median_response_seconds' => 3900, 'avg_response_seconds' => 3900,
        ])
        ->and($data['reviewers'][1])->toBe([
            'uuid' => $bob->uuid, 'name' => 'bob', 'assigned' => 1, 'decided_by_them' => 1, 'decided_by_others' => 0,
            'within_sla' => 1, 'late' => 0, 'overdue_now' => 0, 'median_response_seconds' => 300, 'avg_response_seconds' => 300,
        ]);

    $this->getJson('/api/v2/admin/agentic/approvals/sla-summary?from=2026-01-01&to=2027-06-01')->assertStatus(422);
    $this->getJson('/api/v2/admin/agentic/approvals/sla-summary?from=2026-02-01&to=2026-01-01')->assertStatus(422);
});

it('sla-summary never exposes emails', function (): void {
    $ann = aaaUser('ann');
    aaaApproval(['status' => 'approved', 'sla_seconds' => 3600, 'due_at' => now(), 'decided_at' => now(), 'response_seconds' => 60, 'sla_met' => true, 'decided_by_user_id' => $ann->id], [$ann]);
    $this->actingAs($ann, 'sanctum');

    $summary = $this->getJson('/api/v2/admin/agentic/approvals/sla-summary')->assertOk()->getContent();
    $list = $this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->getContent();

    expect($summary)->not->toContain('@example.com')->not->toContain('"email"')
        ->and($list)->not->toContain('@example.com');
});

it('unknown assignee uuid is refused on save', function (): void {
    $editor = new class extends User {
        public function permissionKeys(): array
        {
            return ['workflow_project.viewAny', 'workflow_project.view', 'workflow_project.create', 'workflow_project.update'];
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $editor->forceFill(['id' => 99999, 'name' => 'Editor', 'email' => 'editor-sla@example.com', 'password' => 'x']);
    $editor->exists = true;
    Sanctum::actingAs($editor);
    $known = aaaUser('known');
    $project = WorkflowProject::query()->create([
        'name' => 'Assign', 'slug' => 'assign-' . uniqid(), 'trigger_event' => 'manual',
        'target_entity_type' => User::class, 'is_active' => false, 'tags' => ['business'],
    ]);
    $graph = static fn (array $config): array => ['nodes' => [[
        'id' => 'ask', 'type' => 'custom', 'position' => ['x' => 0, 'y' => 0],
        'data' => ['step_code' => 'ask', 'name' => 'ask', 'step_type' => 'approval', 'step_config' => ['mode' => 'human'] + $config],
    ]], 'edges' => []];
    $unknown = (string) Str::uuid();

    $this->putJson("/api/v2/admin/workflows/{$project->id}", $graph(['assignees' => [$known->uuid, $unknown], 'sla' => '4h']))
        ->assertStatus(422)
        ->assertJsonPath('data.errors.0.code', 'assignee_unknown')
        ->assertJsonPath('data.errors.0.step_code', 'ask');
    $this->putJson("/api/v2/admin/workflows/{$project->id}", $graph(['sla' => 'soon']))
        ->assertStatus(422)
        ->assertJsonPath('data.errors.0.code', 'config_invalid');
    expect(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(0);

    $this->putJson("/api/v2/admin/workflows/{$project->id}", $graph(['assignees' => [$known->uuid], 'sla' => '4h']))->assertOk();
    expect(WorkflowStep::query()->where('project_id', $project->id)->value('step_config')['assignees'] ?? null)->toBe([$known->uuid]);
});

/** A workflow editor without user-management rights. */
function aaaEditor(): User
{
    $editor = new class extends User {
        public function permissionKeys(): array
        {
            return ['workflow_project.viewAny'];
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $editor->forceFill(['id' => 99998, 'name' => 'Editor', 'email' => 'editor-picker@example.com', 'password' => 'x']);
    $editor->exists = true;

    return $editor;
}

it('reviewers lists only permission holders', function (): void {
    $anna = aaaUser('anna reviewer');
    $andy = aaaUser('andy reviewer');
    aaaUser('anne viewer', ['agentic.viewAny']);
    aaaUser('zed reviewer');
    Sanctum::actingAs(aaaEditor());

    $rows = $this->getJson('/api/v2/admin/workflows/reviewers?q=an')->assertOk()->json('data');

    expect($rows)->toBe([
        ['uuid' => $andy->uuid, 'name' => 'andy reviewer', 'can_review' => true],
        ['uuid' => $anna->uuid, 'name' => 'anna reviewer', 'can_review' => true],
    ])
        ->and($this->getJson('/api/v2/admin/workflows/reviewers')->assertOk()->json('data'))->toHaveCount(3);

    $this->actingAs(aaaUser('nobody', []), 'sanctum');
    $this->getJson('/api/v2/admin/workflows/reviewers')->assertForbidden();
});

it('reviewers never exposes emails', function (): void {
    $anna = aaaUser('anna');
    Sanctum::actingAs(aaaEditor());

    $list = $this->getJson('/api/v2/admin/workflows/reviewers?q=anna')->assertOk()->getContent();
    $named = $this->getJson('/api/v2/admin/workflows/reviewers?' . http_build_query(['uuids' => [$anna->uuid]]))->assertOk()->getContent();

    expect($list)->toContain($anna->uuid)->not->toContain('@example.com')->not->toContain('"email"')
        ->and($named)->toContain($anna->uuid)->not->toContain('@example.com')->not->toContain('"email"');
});

it('uuids resolves names and flags non-reviewers', function (): void {
    $anna = aaaUser('anna');
    $viewer = aaaUser('vic', ['agentic.viewAny']);
    Sanctum::actingAs(aaaEditor());

    $rows = $this->getJson('/api/v2/admin/workflows/reviewers?' . http_build_query(['uuids' => [$anna->uuid, $viewer->uuid, (string) Str::uuid()]]))
        ->assertOk()->json('data');

    expect($rows)->toBe([
        ['uuid' => $anna->uuid, 'name' => 'anna', 'can_review' => true],
        ['uuid' => $viewer->uuid, 'name' => 'vic', 'can_review' => false],
    ]);
});
