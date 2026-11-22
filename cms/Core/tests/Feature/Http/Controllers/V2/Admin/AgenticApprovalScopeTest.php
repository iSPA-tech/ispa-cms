<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\Agentic\AgentArtifactApplyService;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

/*
 * an approver sees, decides and applies only approvals whose record is inside their data scope; apply
 * re-checks the delegating user's scope; the approval response says who the agent acted for, and with which scope.
 * The fixture resource `fixture/records` (the Core fixture tree) is the artifact subject.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class, ScopeTreeFixture::class);

const AGENTIC_SCOPE_VIEW = 'fixture.records.view';
const AGENTIC_SCOPE_RESOURCE = 'fixture/records';

/**
 * Fake module repository declaring the fixture permission and its scope levels.
 */
function agenticScopeFakeModules(): RepositoryInterface
{
    $manifest = ['permissions' => [['key' => AGENTIC_SCOPE_VIEW, 'scopes' => ['all', 'own', 'fixture']]]];
    $module = Mockery::mock(Module::class);
    $module->shouldReceive('getName')->andReturn('Fixture');
    $module->shouldReceive('isEnabled')->andReturn(true);
    $module->shouldReceive('get')->andReturnUsing(static fn (string $key, $default = null) => $manifest[$key] ?? $default);

    $repo = Mockery::mock(RepositoryInterface::class);
    $repo->shouldReceive('allEnabled')->andReturn(['Fixture' => $module]);
    $repo->shouldReceive('find')->andReturnUsing(static fn (string $name) => $name === 'Fixture' ? $module : null);

    return $repo;
}

/**
 * Create a plain (non-webmaster) admin user.
 */
function agenticScopeUser(string $name): User
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
 * Give the user one role holding the permission keys; returns the role id.
 *
 * @param array<int, string> $keys
 */
function agenticScopeRole(User $user, array $keys): int
{
    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);

    foreach ($keys as $key) {
        $dot = strrpos($key, '.');
        $permission = Permission::query()->firstOrCreate(
            ['group' => substr($key, 0, $dot), 'name' => substr($key, $dot + 1)],
            ['title' => $key],
        );
        $role->permissions()->attach($permission);
    }

    $user->roles()->attach($role);

    return (int) $role->id;
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    $this->createScopeTreeFixture();

    // User id 1 is always a webmaster; keep it for the "unscoped viewer" case.
    $this->webmaster = agenticScopeUser('webmaster');

    $modules = agenticScopeFakeModules();
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');

    $resources = new ScopedResourceRegistry();
    $resources->register(
        ScopedResource::make(AGENTIC_SCOPE_RESOURCE, ScopeFixtureRecord::class, AGENTIC_SCOPE_VIEW)
            ->own(static fn (Builder $q, User $actor) => $q->where('owner_user_id', $actor->id))
            ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds))
    );

    app()->instance(DimensionRegistry::class, $dimensions);
    app()->instance(ScopedResourceRegistry::class, $resources);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();

    $this->profile = AgentProfile::query()->create([
        'name' => 'Scope Proposer',
        'slug' => 'scope-proposer',
        'capability_class' => 'class_2_staged_mutation',
        'status' => 'active',
    ]);

    /** A user with agentic view/approve and the fixture permission scoped to the node subtree; returns [user, role id]. */
    $this->scopedUser = function (string $name, string $nodeCode): array {
        $user = agenticScopeUser($name);
        $roleId = agenticScopeRole($user, ['agentic.viewAny', 'agentic.approve', AGENTIC_SCOPE_VIEW]);
        DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $roleId, 'permission_key' => AGENTIC_SCOPE_VIEW, 'level' => 'fixture']);
        DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
            'uuid' => (string) Str::uuid(),
            'subject_type' => 'role',
            'subject_id' => $roleId,
            'dimension' => 'fixture',
            'node_id' => $this->nodeId($nodeCode),
            'expansion' => 'descendants',
        ]);

        return [$user, $roleId];
    };

    /** Seed a run, artifact and approval; the artifact subject (or, when null, the run target) names the record. */
    $this->approval = function (
        ?string $subjectType,
        ?string $subjectId,
        ?User $delegator,
        string $status = 'pending',
        ?string $targetType = null,
        ?string $targetId = null,
    ): AgentApproval {
        $run = AgentRun::query()->create([
            'agent_profile_id' => $this->profile->id,
            'module_scope' => 'fixture',
            'target_type' => $targetType ?? $subjectType,
            'target_id' => $targetId ?? $subjectId,
            'status' => $status === 'pending' ? 'awaiting_approval' : 'awaiting_apply',
            'input_goal' => 'Change the record',
            'started_at' => now(),
            'delegated_by_user_id' => $delegator?->id,
            'scope_snapshot' => $delegator ? app(ScopeSummary::class)->for($delegator, AGENTIC_SCOPE_VIEW) : null,
        ]);

        $artifact = AgentArtifact::query()->create([
            'run_id' => $run->id,
            'artifact_type' => 'fixture_record_patch',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'source_snapshot_ref' => 'fixture:' . $subjectId,
            'original_data_snapshot' => ['node' => 'before'],
            'payload' => ['proposed_change' => ['node' => 'after']],
            'storage_driver' => 'database',
            'version' => 1,
            'render_hint' => 'text_diff',
            'apply_contract_version' => 'v1',
        ]);

        return AgentApproval::query()->create([
            'run_id' => $run->id,
            'artifact_id' => $artifact->id,
            'status' => $status,
            'apply_outcome' => $status === 'approved' ? 'awaiting_apply' : null,
            'diff_data' => ['before' => 'before', 'after' => 'after'],
        ]);
    };

    /** Expect the artifact applier to run exactly $times times. */
    $this->expectApplies = function (int $times): void {
        $applyService = Mockery::mock(AgentArtifactApplyService::class);
        $applyService->shouldReceive('apply')->times($times)->andReturn(['message' => 'ok']);
        $this->app->instance(AgentArtifactApplyService::class, $applyService);
    };

    $this->record = fn (string $code): string => (string) $this->scopeRecordIds[$code];

    [$this->approver] = ($this->scopedUser)('approver', 'MKT');
    [$this->delegator, $this->delegatorRoleId] = ($this->scopedUser)('delegator', 'MKT');
});

afterEach(function (): void {
    foreach (['scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Illuminate\Support\Facades\Schema::dropIfExists($table);
    }
});

test('an approval on an in-scope record is listed, shown and applicable', function (): void {
    $in = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('HOTTOY_A'), $this->delegator, 'approved');
    ($this->expectApplies)(1);
    $this->actingAs($this->approver, 'sanctum');

    $ids = collect($this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data'))->pluck('id')->all();
    expect($ids)->toBe([$in->id]);

    $this->getJson("/api/v2/admin/agentic/approvals/{$in->id}")->assertOk()->assertJsonPath('data.id', $in->id);

    $this->postJson("/api/v2/admin/agentic/approvals/{$in->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.apply_outcome', 'applied');
});

test('an out-of-scope approval is absent from the list and answers 404 to show, approve, reject and apply', function (): void {
    $in = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('HOBBY_A'), $this->delegator);
    $out = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('TRADE_X'), $this->delegator);
    $outByRunTarget = ($this->approval)(null, null, $this->delegator, 'pending', ScopeFixtureRecord::class, ($this->record)('TRADE_X'));
    $outApproved = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('TRADE'), $this->delegator, 'approved');
    ($this->expectApplies)(0);
    $this->actingAs($this->approver, 'sanctum');

    $list = $this->getJson('/api/v2/admin/agentic/approvals')->assertOk();
    expect(collect($list->json('data.data'))->pluck('id')->all())->toBe([$in->id])
        ->and($list->json('data.meta.total'))->toBe(1);

    foreach ([$out, $outByRunTarget] as $approval) {
        $this->getJson("/api/v2/admin/agentic/approvals/{$approval->id}")->assertNotFound();
        $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")->assertNotFound();
        $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject")->assertNotFound();
        expect($approval->fresh()->status)->toBe('pending');
    }

    // An applier out of scope gets 404 even when the delegating user is in scope.
    $this->postJson("/api/v2/admin/agentic/approvals/{$outApproved->id}/apply")->assertNotFound();
    expect($outApproved->fresh()->apply_outcome)->toBe('awaiting_apply');
});

test('apply fails with scope_denied when the delegating user lost the scope after the run started', function (): void {
    $code = 'HOTTOY_B';
    $approval = ($this->approval)(ScopeFixtureRecord::class, ($this->record)($code), $this->delegator, 'approved');
    $before = (array) DB::table('scope_fixture_records')->where('id', $this->scopeRecordIds[$code])->first();

    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)
        ->where('subject_type', 'role')
        ->where('subject_id', $this->delegatorRoleId)
        ->delete();
    app()->forgetScopedInstances();

    ($this->expectApplies)(0);
    $this->actingAs($this->approver, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.apply_outcome', 'apply_failed')
        ->assertJsonPath('data.apply_error', 'scope_denied');

    expect($approval->fresh()->apply_outcome)->toBe('apply_failed')
        ->and($approval->fresh()->apply_error)->toBe('scope_denied')
        ->and((array) DB::table('scope_fixture_records')->where('id', $this->scopeRecordIds[$code])->first())->toBe($before);
});

test('apply fails with scope_denied for a scoped record when the run has no delegating user', function (): void {
    $approval = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('HOTTOY_A'), null, 'approved');
    ($this->expectApplies)(0);
    $this->actingAs($this->approver, 'sanctum');

    $this->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.apply_outcome', 'apply_failed')
        ->assertJsonPath('data.apply_error', 'scope_denied');
});

test('runs are hidden the same way', function (): void {
    $in = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('MKT_HOTTOY'), $this->delegator);
    $out = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('TRADE_X'), $this->delegator);
    $post = ($this->approval)('post', '77', null);
    $this->actingAs($this->approver, 'sanctum');

    $list = $this->getJson('/api/v2/admin/agentic/runs')->assertOk();
    expect(collect($list->json('data.data'))->pluck('id')->sort()->values()->all())->toBe([$in->run_id, $post->run_id])
        ->and($list->json('data.meta.total'))->toBe(2);

    $this->getJson("/api/v2/admin/agentic/runs/{$in->run_id}")->assertOk();
    $this->getJson("/api/v2/admin/agentic/runs/{$out->run_id}")->assertNotFound();
});

test('unscoped subjects are unchanged and an all-scope viewer sees every approval', function (): void {
    $post = ($this->approval)('post', '77', null, 'approved');
    $out = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('TRADE_X'), $this->delegator);
    ($this->expectApplies)(1);

    $this->actingAs($this->approver, 'sanctum');
    expect(collect($this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data'))->pluck('id')->all())
        ->toBe([$post->id]);
    $this->getJson("/api/v2/admin/agentic/approvals/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.run.delegated_by', null)
        ->assertJsonPath('data.scope.at_start', null)
        ->assertJsonPath('data.scope.now', null);
    $this->postJson("/api/v2/admin/agentic/approvals/{$post->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.apply_outcome', 'applied');

    // ScopeResolver checks the permission before the Webmaster rule, so the Webmaster holds the key (as the
    // module permission sync gives it on real sites); with no grant row the level is `all`.
    agenticScopeRole($this->webmaster, [AGENTIC_SCOPE_VIEW]);
    $this->actingAs($this->webmaster, 'sanctum');
    expect(collect($this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all())
        ->toBe([$post->id, $out->id]);
});

test('the response names the delegating user and both scope summaries without node or user ids', function (): void {
    $approval = ($this->approval)(ScopeFixtureRecord::class, ($this->record)('HOTTOY_A'), $this->delegator);
    $this->actingAs($this->approver, 'sanctum');

    $data = $this->getJson("/api/v2/admin/agentic/approvals/{$approval->id}")->assertOk()->json('data');

    expect($data['run']['delegated_by'])->toBe(['uuid' => $this->delegator->uuid, 'name' => 'delegator'])
        ->and($data['scope']['at_start'])->toBe($approval->run->scope_snapshot)
        ->and($data['scope']['at_start']['level'])->toBe('nodes')
        ->and($data['scope']['now']['level'])->toBe('nodes')
        ->and($data['scope']['now']['dimensions'][0]['nodes'][0]['key'])->toBe('MKT');

    $keys = [];
    $walk = static function (array $node, callable $walk) use (&$keys): void {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value, $walk);
            }
        }
    };
    $walk($data['scope'], $walk);
    $walk($data['run']['delegated_by'], $walk);

    expect(array_filter($keys, static fn (string $key): bool => $key === 'id' || str_ends_with($key, '_id') || $key === 'node_id'))->toBe([]);

    // The list carries the same fields.
    $row = $this->getJson('/api/v2/admin/agentic/approvals')->assertOk()->json('data.data.0');
    expect($row['run']['delegated_by']['uuid'])->toBe($this->delegator->uuid)
        ->and($row['scope']['now']['level'])->toBe('nodes');
});
