<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ResolvedScope;
use Cms\Core\Services\DataScope\ScopeResolver;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Cms\Core\Tests\Support\DataScopeTestTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

uses(\Cms\Core\Tests\TestCase::class, DataScopeTestTables::class, ScopeTreeFixture::class);

const SCOPE_RESOLVER_KEY = 'fixture.record.view';

/**
 * Fake module repository for the resolver tests: name => [enabled, manifest].
 *
 * @param array<string, array{0: bool, 1: array<string, mixed>}> $modules
 */
function scopeResolverFakeModules(array $modules): RepositoryInterface
{
    $built = [];

    foreach ($modules as $name => [$enabled, $manifest]) {
        $module = Mockery::mock(Module::class);
        $module->shouldReceive('getName')->andReturn($name);
        $module->shouldReceive('isEnabled')->andReturn($enabled);
        $module->shouldReceive('get')->andReturnUsing(static fn (string $key, $default = null) => $manifest[$key] ?? $default);
        $built[$name] = $module;
    }

    $repo = Mockery::mock(RepositoryInterface::class);
    $repo->shouldReceive('allEnabled')->andReturn(array_filter($built, static fn ($m) => $m->isEnabled()));
    $repo->shouldReceive('find')->andReturnUsing(static fn (string $name) => $built[$name] ?? null);

    return $repo;
}

beforeEach(function (): void {
    foreach (['data_scope_assignments', 'data_scope_grants', 'role_user', 'permission_role', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', static function (Blueprint $t): void {
        $t->id();
        $t->uuid('uuid')->nullable();
        $t->string('name')->nullable();
        $t->string('email')->nullable();
        $t->string('status')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::ROLES, static function (Blueprint $t): void {
        $t->id();
        $t->string('name', 100)->nullable();
        $t->text('custom')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::PERMISSIONS, static function (Blueprint $t): void {
        $t->id();
        $t->string('group')->nullable();
        $t->string('name')->nullable();
        $t->string('title')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::PERMISSION_ROLE, static function (Blueprint $t): void {
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('permission_id');
    });
    Schema::create(CoreTableConstant::ROLE_USER, static function (Blueprint $t): void {
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('user_id');
    });
    $this->createDataScopeTables();
    $this->createScopeTreeFixture();

    // User id 1 is always a webmaster; occupy it so test users are plain accounts.
    DB::table('users')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => 'Webmaster']);

    $modules = scopeResolverFakeModules([
        'Fixture' => [true, ['permissions' => [
            ['key' => SCOPE_RESOLVER_KEY, 'scopes' => ['all', 'own', 'fixture', 'ghost', 'fixture_off']],
            ['key' => 'fixture.other.view', 'scopes' => ['all', 'own', 'fixture']],
        ]]],
        'FixtureOff' => [false, []],
    ]);
    $registry = new DimensionRegistry($modules);
    $registry->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');
    $registry->register('other', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.other');
    $registry->register('fixture_off', 'FixtureOff', $this->scopeClosureHierarchy(), 'fixture.off');

    app()->instance(DimensionRegistry::class, $registry);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();
    CMSLogger::spy();

    /** Create a plain user and return a fresh model instance. */
    $this->makeUser = static function (): User {
        $id = (int) DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Tester']);

        return User::query()->findOrFail($id);
    };

    /** Create a role holding the given permission keys ("group.name"), optionally webmaster-level, attached to the user. */
    $this->makeRole = static function (User $user, array $keys, array $custom = []): int {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId([
            'name' => 'Role ' . Str::random(6),
            'custom' => $custom === [] ? null : json_encode($custom),
        ]);

        foreach ($keys as $key) {
            $dot = strrpos($key, '.');
            $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId([
                'group' => substr($key, 0, $dot),
                'name' => substr($key, $dot + 1),
                'title' => $key,
            ]);
            DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        return $roleId;
    };

    $this->grant = static function (int $roleId, string $level, string $key = SCOPE_RESOLVER_KEY): void {
        DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $roleId, 'permission_key' => $key, 'level' => $level]);
    };

    $this->assign = function (string $type, int $subjectId, string $code, string $expansion, string $dimension = 'fixture'): void {
        DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
            'uuid' => (string) Str::uuid(),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'dimension' => $dimension,
            'node_id' => $this->nodeId($code),
            'expansion' => $expansion,
        ]);
    };

    $this->resolve = static fn (User $user, string $key = SCOPE_RESOLVER_KEY): ResolvedScope => app(ScopeResolver::class)->for($user, $key);

    $this->visible = function (ResolvedScope $scope, string $dimension = 'fixture'): array {
        expect($scope->plans)->toHaveKey($dimension);

        return $this->scopeCodes($scope->plans[$dimension]->visibleIds($this->scopeClosureHierarchy()));
    };
});

afterEach(function (): void {
    foreach (['data_scope_assignments', 'data_scope_grants', 'role_user', 'permission_role', 'permissions', 'roles', 'users',
        'scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('resolves a role without a grant row to all', function (): void {
    $user = ($this->makeUser)();
    ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);

    $scope = ($this->resolve)($user);

    expect($scope->all)->toBeTrue()->and($scope->own)->toBeFalse()->and($scope->plans)->toBe([])
        ->and($scope->isEmpty())->toBeFalse();
});

it('resolves a webmaster to all even with a grant row', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY], ['level' => 0]);
    ($this->grant)($roleId, 'own');

    expect(($this->resolve)($user)->all)->toBeTrue();
});

it('resolves a webmaster to everything even when no role holds the permission key', function (): void {
    $user = ($this->makeUser)();
    ($this->makeRole)($user, ['fixture.other.view'], ['level' => 0]);

    $scope = ($this->resolve)($user);

    expect($scope->all)->toBeTrue()->and($scope)->toEqual(ResolvedScope::everything());
});

it('resolves level own to own only, without plans', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleId, 'own');
    ($this->assign)('role', $roleId, 'MKT', 'descendants');

    $scope = ($this->resolve)($user);

    expect($scope->all)->toBeFalse()->and($scope->own)->toBeTrue()->and($scope->plans)->toBe([])
        ->and($scope->isEmpty())->toBeFalse();
});

it('plans a dimension level from the role assignment and sees only that subtree', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleId, 'fixture');
    ($this->assign)('role', $roleId, 'MKT_HOTTOY', 'descendants');

    $scope = ($this->resolve)($user);
    $visible = ($this->visible)($scope);

    expect($scope->all)->toBeFalse()->and($scope->own)->toBeFalse()
        ->and($scope->plans['fixture']->anchorIds)->toBe([$this->nodeId('MKT_HOTTOY')])
        ->and($visible)->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY'])
        ->and($visible)->not->toContain('MKT', 'MKT_HOBBY', 'HOBBY_A', 'TRADE', 'TRADE_X');
});

it('applies user assignments to every role of that dimension and role assignments to their own role only', function (): void {
    $user = ($this->makeUser)();
    $roleA = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    $roleB = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    $roleOther = ($this->makeRole)($user, ['fixture.other.view']);
    ($this->grant)($roleA, 'fixture');
    ($this->grant)($roleB, 'fixture');
    ($this->grant)($roleOther, 'fixture', 'fixture.other.view');
    ($this->assign)('role', $roleA, 'HOTTOY_A', 'self');
    ($this->assign)('role', $roleB, 'TRADE_X', 'self');
    ($this->assign)('role', $roleOther, 'MKT_HOBBY', 'descendants');
    ($this->assign)('user', $user->id, 'HOTTOY_B', 'self');

    $scope = ($this->resolve)($user);

    expect(($this->visible)($scope))->toBe(['HOTTOY_A', 'HOTTOY_B', 'TRADE_X'])
        ->and(count($scope->plans['fixture']->held))->toBe(3);

    // The other permission sees its own role's nodes plus the user's, not those of roles A and B.
    $other = ($this->resolve)(User::query()->findOrFail($user->id), 'fixture.other.view');
    expect(($this->visible)($other))->toBe(['HOBBY_A', 'HOTTOY_B', 'MKT_HOBBY']);
});

it('resolves to all when one of two roles is all', function (): void {
    $user = ($this->makeUser)();
    $roleA = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleA, 'fixture');
    ($this->assign)('role', $roleA, 'HOTTOY_A', 'self');

    expect(($this->resolve)($user)->all)->toBeTrue();

    $user2 = ($this->makeUser)();
    $roleC = ($this->makeRole)($user2, [SCOPE_RESOLVER_KEY]);
    $roleD = ($this->makeRole)($user2, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleC, 'own');
    ($this->grant)($roleD, 'all');

    expect(($this->resolve)($user2)->all)->toBeTrue();
});

it('resolves to empty for a level the manifest does not allow, an unregistered dimension or a disabled module', function (string $level): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleId, $level);
    ($this->assign)('role', $roleId, 'MKT', 'descendants', $level);
    ($this->assign)('user', $user->id, 'TRADE', 'descendants', $level);

    $scope = ($this->resolve)($user);

    expect($scope->all)->toBeFalse()->and($scope->own)->toBeFalse()->and($scope->plans)->toBe([])
        ->and($scope->isEmpty())->toBeTrue();
})->with(['not allowed' => 'other', 'unregistered' => 'ghost', 'disabled module' => 'fixture_off']);

it('logs an unusable level once per request without user data', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY, 'fixture.other.view']);
    ($this->grant)($roleId, 'ghost');

    ($this->resolve)($user);
    ($this->resolve)(User::query()->findOrFail($user->id));

    CMSLogger::shouldHaveReceived('warning')->once()->withArgs(static function (array $payload) use ($user): bool {
        return str_contains(json_encode($payload), 'ghost') && ! array_key_exists('user_id', $payload);
    });
});

it('ignores unknown expansion values in assignment rows', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleId, 'fixture');
    ($this->assign)('role', $roleId, 'MKT', 'everything');
    ($this->assign)('role', $roleId, 'TRADE_X', 'self');

    expect(($this->visible)(($this->resolve)($user)))->toBe(['TRADE_X']);
});

it('resolves a user without the permission to none, even with assignments', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, ['fixture.other.view']);
    ($this->grant)($roleId, 'fixture');
    ($this->assign)('role', $roleId, 'MKT', 'descendants');
    ($this->assign)('user', $user->id, 'MKT', 'descendants');

    $scope = ($this->resolve)($user);

    expect($scope->isEmpty())->toBeTrue()->and($scope)->toEqual(ResolvedScope::none());
});

it('applies the grant of a held legacy key', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, ['record.view']);
    ($this->grant)($roleId, 'fixture', 'record.view');
    ($this->assign)('role', $roleId, 'TRADE', 'descendants');

    expect(($this->visible)(($this->resolve)($user)))->toBe(['TRADE', 'TRADE_X']);
});

it('memoises per request and recomputes after the scoped instances are forgotten', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, [SCOPE_RESOLVER_KEY]);
    ($this->grant)($roleId, 'fixture');
    ($this->assign)('role', $roleId, 'MKT_HOTTOY', 'descendants');

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $first = ($this->resolve)(User::query()->findOrFail($user->id));
    $afterFirst = $queries;
    $second = ($this->resolve)(User::query()->findOrFail($user->id));
    $afterSecond = $queries;

    // The second call only runs the findOrFail query for the fresh user instance.
    expect($afterFirst)->toBeGreaterThan(1)
        ->and($afterSecond - $afterFirst)->toBe(1)
        ->and($second)->toBe($first);

    ($this->grant)(($this->makeRole)($user, [SCOPE_RESOLVER_KEY]), 'own');
    app()->forgetScopedInstances();
    $before = $queries;
    $third = ($this->resolve)(User::query()->findOrFail($user->id));

    expect($queries - $before)->toBeGreaterThan(1)
        ->and($third->own)->toBeTrue()
        ->and(($this->visible)($third))->toBe(['HOTTOY_A', 'HOTTOY_B', 'MKT_HOTTOY']);
});
