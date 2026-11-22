<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentRunStep;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\Agentic\WorkflowContextToolService;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

/*
 * cancel, stop and re-run of an agent run apply the caller's data scope like the run detail does. A run
 * whose target record is outside the scope answers 404 and nothing changes (status, steps, no new run); a run
 * inside the scope behaves as before, and a re-run records the re-running user as the delegating user.
 * The fixture resource `fixture/records` (the Core fixture tree) is the run target.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class, ScopeTreeFixture::class);

const RUN_SCOPE_VIEW = 'fixture.records.view';

/**
 * Fake module repository declaring the fixture permission and its scope levels.
 */
function runScopeFakeModules(): RepositoryInterface
{
    $manifest = ['permissions' => [['key' => RUN_SCOPE_VIEW, 'scopes' => ['all', 'own', 'fixture']]]];
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
 * Create a plain admin user holding the given permission keys through one role, scoped to a fixture node subtree.
 *
 * @param array<int, string> $keys
 */
function runScopeUser(string $name, array $keys, int $nodeId): User
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

    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $role->id, 'permission_key' => RUN_SCOPE_VIEW, 'level' => 'fixture']);
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
        'uuid' => (string) Str::uuid(),
        'subject_type' => 'role',
        'subject_id' => $role->id,
        'dimension' => 'fixture',
        'node_id' => $nodeId,
        'expansion' => 'descendants',
    ]);

    return $user;
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    $this->createScopeTreeFixture();

    // User id 1 is always a webmaster; take it so the operator below is a plain scoped user.
    User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'webmaster', 'username' => 'webmaster', 'email' => 'wm@example.com',
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0,
    ]);

    $modules = runScopeFakeModules();
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');

    $resources = new ScopedResourceRegistry();
    $resources->register(
        ScopedResource::make('fixture/records', ScopeFixtureRecord::class, RUN_SCOPE_VIEW)
            ->own(static fn (Builder $q, User $actor) => $q->where('owner_user_id', $actor->id))
            ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds))
    );

    app()->instance(DimensionRegistry::class, $dimensions);
    app()->instance(ScopedResourceRegistry::class, $resources);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();

    $this->profile = AgentProfile::query()->create([
        'name' => 'Scope Inspector',
        'slug' => 'scope-inspector',
        'capability_class' => 'class_0_read_only',
        'status' => 'active',
        'budget_config' => ['max_steps' => 5],
    ]);
    app(AgentToolRegistry::class)->syncDiscoveredService(WorkflowContextToolService::class, 'core', 'testing');
    $this->profile->toolDefinitions()->syncWithoutDetaching([
        AgentToolDefinition::query()->where('tool_name', 'core.inspect_workflow_context')->firstOrFail()->id => [
            'enabled' => true,
            'safety_override' => null,
        ],
    ]);

    $this->operator = runScopeUser('operator', ['agentic.update', 'agentic.viewAny', RUN_SCOPE_VIEW], $this->nodeId('MKT'));

    /** Seed a run in the status on the fixture record with the given code, with one step. */
    $this->run = function (string $status, string $code): AgentRun {
        $run = AgentRun::query()->create([
            'agent_profile_id' => $this->profile->id,
            'environment' => 'testing',
            'module_scope' => 'fixture',
            'target_type' => ScopeFixtureRecord::class,
            'target_id' => (string) $this->scopeRecordIds[$code],
            'status' => $status,
            'input_goal' => 'Inspect the record',
            'budget_usage' => ['steps' => 1, 'tool_calls' => 0, 'tokens' => 0],
            'started_at' => now()->subMinute(),
        ]);
        AgentRunStep::query()->create([
            'run_id' => $run->id,
            'step_index' => 1,
            'state' => 'ACTION',
            'action_type' => 'tool_call',
            'action_name' => 'core.inspect_workflow_context',
            'status' => 'completed',
        ]);

        return $run;
    };

    $this->actingAs($this->operator, 'sanctum');
});

afterEach(function (): void {
    foreach (['scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Schema::dropIfExists($table);
    }
});

dataset('run actions', [
    'cancel' => ['cancel', 'awaiting_approval'],
    'stop' => ['stop', 'running'],
    'rerun' => ['rerun', 'failed'],
]);

test('a run outside the data scope answers 404 and nothing changes', function (string $action, string $status): void {
    $out = ($this->run)($status, 'TRADE_X');
    $runs = AgentRun::query()->count();
    $steps = AgentRunStep::query()->count();

    $this->postJson("/api/v2/admin/agentic/runs/{$out->id}/{$action}", ['note' => 'try'])->assertNotFound();

    $fresh = $out->fresh();
    expect($fresh->status)->toBe($status)
        ->and($fresh->operator_request_state)->toBeNull()
        ->and($fresh->stop_requested_by_user_id)->toBeNull()
        ->and(AgentRun::query()->count())->toBe($runs)
        ->and(AgentRun::query()->where('source_run_id', $out->id)->count())->toBe(0)
        ->and(AgentRunStep::query()->count())->toBe($steps);
})->with('run actions');

test('an unknown run id still answers 404', function (string $action): void {
    $this->postJson("/api/v2/admin/agentic/runs/999999/{$action}")->assertNotFound();
})->with(['cancel', 'stop', 'rerun']);

test('cancel and stop work on a run inside the data scope', function (): void {
    $cancel = ($this->run)('awaiting_approval', 'HOTTOY_A');
    $stop = ($this->run)('running', 'HOBBY_A');

    $this->postJson("/api/v2/admin/agentic/runs/{$cancel->id}/cancel", ['note' => 'no longer needed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v2/admin/agentic/runs/{$stop->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.operator_request_state', 'stop_requested');

    expect($cancel->fresh()->status)->toBe('cancelled')
        ->and($stop->fresh()->stop_requested_by_user_id)->toBe($this->operator->id);
});

test('a re-run inside the data scope creates a linked run that acts for the re-running user', function (): void {
    $source = ($this->run)('failed', 'HOTTOY_A');
    $before = AgentRun::query()->count();

    $this->postJson("/api/v2/admin/agentic/runs/{$source->id}/rerun")
        ->assertOk()
        ->assertJsonPath('data.source_run_id', $source->id)
        ->assertJsonPath('data.trigger_mode', 'rerun');

    $rerun = AgentRun::query()->where('source_run_id', $source->id)->firstOrFail();
    expect(AgentRun::query()->count())->toBe($before + 1)
        ->and($rerun->delegated_by_user_id)->toBe($this->operator->id)
        ->and($source->fresh()->status)->toBe('failed');
});
