<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopeResolver;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

/*
 * data-scope writes (role grants + assignments, user assignments) with the anti-escalation guard and audit.
 *
 * Fixture tree: MKT > MKT_HOTTOY > {HOTTOY_A, HOTTOY_B}, MKT > MKT_HOBBY > HOBBY_A; TRADE > TRADE_X.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class, ScopeTreeFixture::class);

const DS_WRITE_KEY = 'fixture.records.view';
const DS_WRITE_BASE = '/api/v2/admin/data-scope';

/**
 * Fake module repository declaring the scoped fixture permission and the fixture dimension.
 */
function dsWriteModules(): RepositoryInterface
{
    $manifest = [
        'permissions' => [['key' => DS_WRITE_KEY, 'scopes' => ['all', 'own', 'fixture']]],
        'scope_dimensions' => [['name' => 'fixture', 'hierarchy' => 'closure']],
    ];
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
 * Create an admin user.
 */
function dsWriteUser(string $name): User
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
 * Create a role (level 50, not Webmaster) holding the permission keys and attach it to the user when given.
 *
 * @param array<int, string> $keys
 */
function dsWriteRole(?User $user, array $keys): Role
{
    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);

    foreach ($keys as $key) {
        $dot = strrpos($key, '.');
        $permission = Permission::query()->firstOrCreate(
            ['group' => substr($key, 0, $dot), 'name' => substr($key, $dot + 1)],
            ['title' => 'Title ' . $key],
        );
        $role->permissions()->attach($permission);
    }

    $user?->roles()->attach($role);

    return $role;
}

/**
 * Insert an assignment row directly (test setup only).
 */
function dsWriteAssign(object $test, string $type, int $subjectId, string $code, string $expansion): void
{
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
        'uuid' => (string) Str::uuid(),
        'subject_type' => $type,
        'subject_id' => $subjectId,
        'dimension' => 'fixture',
        'node_id' => $test->nodeId($code),
        'expansion' => $expansion,
    ]);
}

/**
 * Visible fixture codes of the user for the fixture permission, resolved fresh from the container.
 *
 * @return array<int, string>|string
 */
function dsWriteVisible(object $test, User $user): array|string
{
    $scope = app(ScopeResolver::class)->for($user->fresh(), DS_WRITE_KEY);

    if ($scope->all) {
        return 'all';
    }

    $plan = $scope->plans['fixture'] ?? null;

    return $plan === null ? [] : $test->scopeCodes($plan->visibleIds($test->scopeClosureHierarchy()));
}

/**
 * An assignment payload entry.
 *
 * @return array<string, string>
 */
function dsA(string $code, string $expansion): array
{
    return ['dimension' => 'fixture', 'node_key' => $code, 'expansion' => $expansion];
}

beforeEach(function (): void {
    $this->withoutMiddleware([\App\Http\Middleware\HttpsProtocol::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    $this->createScopeTreeFixture();

    $modules = dsWriteModules();
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');
    app()->instance(DimensionRegistry::class, $dimensions);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();

    // The first user gets id 1, which User::isWebmaster() treats as Webmaster.
    $this->webmaster = dsWriteUser('webmaster');
    // Route middleware still needs the key; the Core seeder grants it to Webmaster on real sites.
    dsWriteRole($this->webmaster, ['data_scope.manage']);
    expect($this->webmaster->isWebmaster())->toBeTrue();

    // Manager: data_scope.manage plus the fixture permission scoped to MKT_HOTTOY/descendants.
    $this->manager = dsWriteUser('manager');
    $this->managerRole = dsWriteRole($this->manager, ['data_scope.manage', DS_WRITE_KEY]);
    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $this->managerRole->id, 'permission_key' => DS_WRITE_KEY, 'level' => 'fixture']);
    dsWriteAssign($this, 'role', $this->managerRole->id, 'MKT_HOTTOY', 'descendants');
    expect($this->manager->isWebmaster())->toBeFalse();

    // Subject: a role at level fixture with HOTTOY_B/self.
    $this->subject = dsWriteUser('subject');
    $this->subjectRole = dsWriteRole($this->subject, [DS_WRITE_KEY]);
    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $this->subjectRole->id, 'permission_key' => DS_WRITE_KEY, 'level' => 'fixture']);
    dsWriteAssign($this, 'role', $this->subjectRole->id, 'HOTTOY_B', 'self');

    $this->outsider = dsWriteUser('outsider');

    $this->put = function (string $path, array $body, ?User $as = null) {
        Laravel\Sanctum\Sanctum::actingAs($as ?? $this->manager, ['*']);

        return $this->putJson(DS_WRITE_BASE . $path, $body);
    };

    $this->putRole = fn (array $assignments, string $level = 'fixture', ?User $as = null) => ($this->put)(
        '/roles/' . $this->subjectRole->id,
        ['grants' => [['permission_key' => DS_WRITE_KEY, 'level' => $level]], 'assignments' => $assignments],
        $as,
    );
});

test('writes answer 403 without data_scope.manage', function (): void {
    ($this->putRole)([dsA('HOTTOY_A', 'self')], 'fixture', $this->outsider)->assertForbidden();
    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => []], $this->outsider)->assertForbidden();
    expect(AuditLog::query()->count())->toBe(0);
});

test('role save replaces grants and assignments and answers with the read shape', function (): void {
    $data = ($this->putRole)([dsA('TRADE_X', 'self'), dsA('HOTTOY_A', 'self')], 'fixture', $this->webmaster)->assertOk()->json('data');

    expect($data['grants'])->toBe([[
        'permission_key' => DS_WRITE_KEY,
        'title' => 'Title ' . DS_WRITE_KEY,
        'label_key' => null,
        'allowed_levels' => ['all', 'own', 'fixture'],
        'level' => 'fixture',
    ]]);
    expect(collect($data['assignments'])->pluck('node.key')->sort()->values()->all())->toBe(['HOTTOY_A', 'TRADE_X']);
    expect(DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'role')->where('subject_id', $this->subjectRole->id)->count())->toBe(2);

    // Level own, no assignments: assignments are cleared and the grant row says own.
    ($this->putRole)([], 'own', $this->webmaster)->assertOk()->assertJsonPath('data.grants.0.level', 'own')->assertJsonPath('data.assignments', []);

    // Level all removes the grant row (a missing row means all).
    ($this->putRole)([], 'all', $this->webmaster)->assertOk()->assertJsonPath('data.grants.0.level', 'all');
    expect(DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->where('role_id', $this->subjectRole->id)->count())->toBe(0);
});

test('user save replaces the user assignments', function (): void {
    dsWriteAssign($this, 'user', $this->subject->id, 'TRADE', 'self');

    $data = ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('HOTTOY_A', 'self')]], $this->webmaster)->assertOk()->json('data');

    expect(collect($data['assignments'])->pluck('node.key')->all())->toBe(['HOTTOY_A']);
    expect($data['effective'][0]['permission_key'])->toBe(DS_WRITE_KEY);
    expect(DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'user')->where('subject_id', $this->subject->id)->count())->toBe(1);
});

test('validation: disallowed level, unknown node, unknown dimension, bad expansion and unheld permission answer 422', function (): void {
    ($this->putRole)([], 'nope', $this->webmaster)->assertStatus(422)->assertJsonValidationErrors(['grants.0.level']);
    ($this->putRole)([dsA('NOPE', 'self')], 'fixture', $this->webmaster)->assertStatus(422)->assertJsonValidationErrors(['assignments.0.node_key']);
    ($this->putRole)([['dimension' => 'other', 'node_key' => 'HOTTOY_A', 'expansion' => 'self']], 'fixture', $this->webmaster)
        ->assertStatus(422)->assertJsonValidationErrors(['assignments.0.dimension']);
    ($this->putRole)([dsA('HOTTOY_A', 'everything')], 'fixture', $this->webmaster)->assertStatus(422)->assertJsonValidationErrors(['assignments.0.expansion']);
    ($this->put)('/roles/' . $this->subjectRole->id, ['grants' => [['permission_key' => 'other.thing.view', 'level' => 'all']], 'assignments' => []], $this->webmaster)
        ->assertStatus(422)->assertJsonValidationErrors(['grants.0.permission_key']);
    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('NOPE', 'self')]], $this->webmaster)
        ->assertStatus(422)->assertJsonValidationErrors(['assignments.0.node_key']);

    expect(AuditLog::query()->count())->toBe(0);
    expect(DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'role')->where('subject_id', $this->subjectRole->id)->count())->toBe(1);
});

test('a soft-deleted node is unknown', function (): void {
    DB::table('scope_fixture_nodes')->where('code', 'HOBBY_A')->update(['deleted_at' => now()]);

    ($this->putRole)([dsA('HOBBY_A', 'self')], 'fixture', $this->webmaster)->assertStatus(422)->assertJsonValidationErrors(['assignments.0.node_key']);
});

test('Webmaster can grant anything, including roots and level all', function (): void {
    ($this->putRole)([dsA('MKT', 'descendants'), dsA('TRADE', 'descendants')], 'fixture', $this->webmaster)->assertOk();
    ($this->putRole)([], 'all', $this->webmaster)->assertOk();
    ($this->put)('/roles/' . $this->managerRole->id, ['grants' => [['permission_key' => DS_WRITE_KEY, 'level' => 'all']], 'assignments' => []], $this->webmaster)->assertOk();
});

test('a manager holding MKT_HOTTOY/descendants may grant inside their set', function (): void {
    ($this->putRole)([dsA('HOTTOY_A', 'self'), dsA('HOTTOY_B', 'self')])->assertOk();
    ($this->putRole)([dsA('MKT_HOTTOY', 'descendants')])->assertOk();
    // HOTTOY_A/branch anchors MKT_HOTTOY: exactly the manager's own set.
    ($this->putRole)([dsA('HOTTOY_A', 'branch')])->assertOk();
    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('HOTTOY_A', 'self')]])->assertOk();
});

test('a manager cannot grant beyond their set', function (): void {
    ($this->putRole)([dsA('MKT', 'descendants')])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
    ($this->putRole)([dsA('MKT', 'self')])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
    ($this->putRole)([dsA('HOBBY_A', 'branch')])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
    ($this->putRole)([dsA('TRADE_X', 'self')])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('MKT_HOBBY', 'self')]])
        ->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');

    expect(AuditLog::query()->count())->toBe(0);
    expect(dsWriteVisible($this, $this->subject))->toBe(['HOTTOY_B']);
});

test('a branch that reaches beyond a narrower manager set is refused', function (): void {
    // Narrow the manager to HOTTOY_A/descendants: HOTTOY_A/branch would anchor MKT_HOTTOY and reach HOTTOY_B.
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'role')->where('subject_id', $this->managerRole->id)->delete();
    dsWriteAssign($this, 'role', $this->managerRole->id, 'HOTTOY_A', 'descendants');
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'role')->where('subject_id', $this->subjectRole->id)->delete();

    ($this->putRole)([dsA('HOTTOY_A', 'branch')])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
    ($this->putRole)([dsA('HOTTOY_A', 'self')])->assertOk();
});

test('a manager without all cannot set or fall back to level all', function (): void {
    ($this->putRole)([], 'all')->assertForbidden()->assertJsonPath('error_code', 'level_all_requires_all');
    // Omitting the grant means "no row", which is all.
    ($this->put)('/roles/' . $this->subjectRole->id, ['grants' => [], 'assignments' => []])
        ->assertForbidden()->assertJsonPath('error_code', 'level_all_requires_all');
    expect(DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->where('role_id', $this->subjectRole->id)->value('level'))->toBe('fixture');
});

test('a manager cannot switch a role to a level that activates assignments beyond their set', function (): void {
    // Role at own with an inert MKT/descendants assignment; switching it to fixture would expose MKT.
    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->where('role_id', $this->subjectRole->id)->update(['level' => 'own']);
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->where('subject_type', 'role')->where('subject_id', $this->subjectRole->id)->delete();
    dsWriteAssign($this, 'user', $this->subject->id, 'MKT', 'descendants');

    ($this->putRole)([])->assertForbidden()->assertJsonPath('error_code', 'scope_escalation');
});

test('a manager cannot edit their own user or a role they hold', function (): void {
    ($this->put)('/users/' . $this->manager->uuid, ['assignments' => [dsA('HOTTOY_A', 'self')]])
        ->assertForbidden()->assertJsonPath('error_code', 'self_edit_forbidden');
    ($this->put)('/roles/' . $this->managerRole->id, ['grants' => [['permission_key' => DS_WRITE_KEY, 'level' => 'fixture']], 'assignments' => [dsA('HOTTOY_A', 'self')]])
        ->assertForbidden()->assertJsonPath('error_code', 'held_role_forbidden');

    expect(AuditLog::query()->count())->toBe(0);
});

test('every save writes one audit row with the target and before/after', function (): void {
    ($this->putRole)([dsA('HOTTOY_A', 'self')])->assertOk();

    $rows = AuditLog::query()->get();
    expect($rows)->toHaveCount(1);
    $log = $rows->first();
    expect($log->action)->toBe('data_scope.role_updated');
    expect($log->target_type)->toBe(Role::class);
    expect((int) $log->target_id)->toBe((int) $this->subjectRole->id);
    expect((int) $log->user_id)->toBe((int) $this->manager->id);
    expect($log->changed['before'])->toBe([
        'grants' => [['permission_key' => DS_WRITE_KEY, 'level' => 'fixture']],
        'assignments' => [['dimension' => 'fixture', 'node_key' => 'HOTTOY_B', 'label' => 'Hottoy B', 'expansion' => 'self']],
    ]);
    expect($log->changed['after'])->toBe([
        'grants' => [['permission_key' => DS_WRITE_KEY, 'level' => 'fixture']],
        'assignments' => [['dimension' => 'fixture', 'node_key' => 'HOTTOY_A', 'label' => 'Hottoy A', 'expansion' => 'self']],
    ]);

    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('HOTTOY_B', 'descendants')]])->assertOk();

    expect(AuditLog::query()->count())->toBe(2);
    $userLog = AuditLog::query()->latest('id')->first();
    expect($userLog->action)->toBe('data_scope.user_updated');
    expect($userLog->target_type)->toBe(User::class);
    expect((int) $userLog->target_id)->toBe((int) $this->subject->id);
    expect($userLog->changed)->toBe([
        'before' => ['assignments' => []],
        'after' => ['assignments' => [['dimension' => 'fixture', 'node_key' => 'HOTTOY_B', 'label' => 'Hottoy B', 'expansion' => 'descendants']]],
    ]);
});

test('the changed scope takes effect when resolved again after the save', function (): void {
    // Memoise the current scope in this request's resolver first.
    expect(dsWriteVisible($this, $this->subject))->toBe(['HOTTOY_B']);

    ($this->putRole)([dsA('HOTTOY_A', 'self')])->assertOk();
    expect(dsWriteVisible($this, $this->subject))->toBe(['HOTTOY_A']);

    ($this->put)('/users/' . $this->subject->uuid, ['assignments' => [dsA('HOTTOY_B', 'self')]])->assertOk();
    expect(dsWriteVisible($this, $this->subject))->toBe(['HOTTOY_A', 'HOTTOY_B']);
});
