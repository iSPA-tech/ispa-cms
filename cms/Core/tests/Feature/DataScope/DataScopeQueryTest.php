<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\DataScope\DataScope;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopedResource;
use Cms\Core\Services\DataScope\ScopedResourceRegistry;
use Cms\Core\Tests\Fixtures\DataScope\ScopeFixtureRecord;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Cms\Core\Tests\Support\DataScopeTestTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

uses(\Cms\Core\Tests\TestCase::class, DataScopeTestTables::class, ScopeTreeFixture::class);

const DATA_SCOPE_QUERY_VIEW = 'fixture.records.view';
const DATA_SCOPE_QUERY_MANAGE = 'fixture.records.manage';
const DATA_SCOPE_QUERY_RESOURCE = 'fixture/records';

/**
 * Fake module repository for the query layer tests: name => [enabled, manifest].
 *
 * @param array<string, array{0: bool, 1: array<string, mixed>}> $modules
 */
function dataScopeQueryFakeModules(array $modules): RepositoryInterface
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

    $modules = dataScopeQueryFakeModules([
        'Fixture' => [true, ['permissions' => [
            ['key' => DATA_SCOPE_QUERY_VIEW, 'scopes' => ['all', 'own', 'fixture']],
            ['key' => DATA_SCOPE_QUERY_MANAGE, 'scopes' => ['all', 'own', 'fixture']],
        ]]],
    ]);
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');

    $resources = new ScopedResourceRegistry();
    $resources->register(
        ScopedResource::make(DATA_SCOPE_QUERY_RESOURCE, ScopeFixtureRecord::class, DATA_SCOPE_QUERY_VIEW)
            ->own(static fn (Builder $q, User $actor) => $q->where('owner_user_id', $actor->id))
            ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds))
            ->unrestrictedWith(DATA_SCOPE_QUERY_MANAGE)
    );

    app()->instance(DimensionRegistry::class, $dimensions);
    app()->instance(ScopedResourceRegistry::class, $resources);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();
    CMSLogger::spy();

    // Extra records without a node: one owned by the user created below, one owned by nobody.
    $this->recordCodes = array_flip($this->scopeRecordIds);
    $this->addRecord = function (string $code, ?string $nodeCode, ?int $ownerId): int {
        $id = (int) DB::table('scope_fixture_records')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'node_id' => $nodeCode === null ? null : $this->nodeId($nodeCode),
            'owner_user_id' => $ownerId,
        ]);
        $this->recordCodes[$id] = $code;

        return $id;
    };

    $this->makeUser = static function (): User {
        $id = (int) DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Tester']);

        return User::query()->findOrFail($id);
    };

    $this->makeRole = static function (User $user, array $keys): int {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . Str::random(6)]);

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

    $this->grant = static function (int $roleId, string $level, string $key = DATA_SCOPE_QUERY_VIEW): void {
        DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $roleId, 'permission_key' => $key, 'level' => $level]);
    };

    $this->assign = function (int $roleId, string $code, string $expansion): void {
        DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
            'uuid' => (string) Str::uuid(),
            'subject_type' => 'role',
            'subject_id' => $roleId,
            'dimension' => 'fixture',
            'node_id' => $this->nodeId($code),
            'expansion' => $expansion,
        ]);
    };

    /** A user holding the view permission through one role with the given level (null = no grant row = all). */
    $this->actorWith = function (?string $level): User {
        $user = ($this->makeUser)();
        $roleId = ($this->makeRole)($user, [DATA_SCOPE_QUERY_VIEW]);

        if ($level !== null) {
            ($this->grant)($roleId, $level);
        }

        $this->lastRoleId = $roleId;

        return $user;
    };

    $this->scope = static fn (): DataScope => app(DataScope::class);

    /** Sorted record codes visible through the scoped query. */
    $this->codes = function (?User $actor, ?Closure $before = null): array {
        $query = ScopeFixtureRecord::query();

        if ($before !== null) {
            $before($query);
        }

        $ids = ($this->scope)()->apply($query, DATA_SCOPE_QUERY_RESOURCE, $actor)->pluck('id')->all();
        $codes = array_map(fn ($id): string => $this->recordCodes[(int) $id] ?? '#' . $id, $ids);
        sort($codes);

        return $codes;
    };
});

afterEach(function (): void {
    foreach (['data_scope_assignments', 'data_scope_grants', 'role_user', 'permission_role', 'permissions', 'roles', 'users',
        'scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('returns every record, nodeless ones included, for scope all', function (): void {
    $actor = ($this->actorWith)(null);
    ($this->addRecord)('NULL_MINE', null, $actor->id);
    ($this->addRecord)('NULL_OTHER', null, null);

    expect(($this->codes)($actor))->toBe([
        'HOBBY_A', 'HOTTOY_A', 'HOTTOY_B', 'MKT', 'MKT_HOBBY', 'MKT_HOTTOY', 'NULL_MINE', 'NULL_OTHER', 'TRADE', 'TRADE_X',
    ]);
});

it('returns only owned records for scope own', function (): void {
    $actor = ($this->actorWith)('own');
    $other = ($this->makeUser)();
    DB::table('scope_fixture_records')->where('id', $this->scopeRecordIds['HOTTOY_A'])->update(['owner_user_id' => $actor->id]);
    DB::table('scope_fixture_records')->where('id', $this->scopeRecordIds['MKT'])->update(['owner_user_id' => $other->id]);
    ($this->addRecord)('NULL_MINE', null, $actor->id);
    ($this->addRecord)('NULL_OTHER', null, $other->id);

    $codes = ($this->codes)($actor);

    expect($codes)->toBe(['HOTTOY_A', 'NULL_MINE'])
        ->and($codes)->not->toContain('MKT', 'NULL_OTHER', 'HOTTOY_B', 'TRADE');
});

it('returns the held self nodes and anchor subtrees for a dimension, roots included', function (): void {
    $actor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'TRADE', 'descendants');
    ($this->assign)($this->lastRoleId, 'HOTTOY_A', 'self');
    ($this->addRecord)('NULL_MINE', null, $actor->id);
    ($this->addRecord)('NULL_OTHER', null, null);

    $codes = ($this->codes)($actor);

    expect($codes)->toBe(['HOTTOY_A', 'TRADE', 'TRADE_X'])
        ->and($codes)->not->toContain('MKT', 'MKT_HOTTOY', 'HOTTOY_B', 'MKT_HOBBY', 'HOBBY_A', 'NULL_MINE', 'NULL_OTHER');
});

it('unions own and a dimension across two roles', function (): void {
    $actor = ($this->actorWith)('own');
    $roleB = ($this->makeRole)($actor, [DATA_SCOPE_QUERY_VIEW]);
    ($this->grant)($roleB, 'fixture');
    ($this->assign)($roleB, 'MKT_HOBBY', 'descendants');
    DB::table('scope_fixture_records')->where('id', $this->scopeRecordIds['TRADE_X'])->update(['owner_user_id' => $actor->id]);
    ($this->addRecord)('NULL_MINE', null, $actor->id);
    ($this->addRecord)('NULL_OTHER', null, null);

    $codes = ($this->codes)(User::query()->findOrFail($actor->id));

    expect($codes)->toBe(['HOBBY_A', 'MKT_HOBBY', 'NULL_MINE', 'TRADE_X'])
        ->and($codes)->not->toContain('MKT', 'MKT_HOTTOY', 'HOTTOY_A', 'HOTTOY_B', 'TRADE', 'NULL_OTHER');
});

it('returns no rows for a null actor or a user without the permission', function (): void {
    $stranger = ($this->makeUser)();
    $roleId = ($this->makeRole)($stranger, ['fixture.other.view']);
    ($this->assign)($roleId, 'MKT', 'descendants');

    expect(($this->codes)(null))->toBe([])
        ->and(($this->codes)($stranger))->toBe([])
        ->and(($this->scope)()->scopeFor(DATA_SCOPE_QUERY_RESOURCE, null)->isEmpty())->toBeTrue();
});

it('shows a nodeless record only to all and to its owner under own', function (): void {
    $owner = ($this->actorWith)('own');
    $mine = ($this->addRecord)('NULL_MINE', null, $owner->id);
    $dimensionActor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'MKT', 'descendants');
    ($this->assign)($this->lastRoleId, 'TRADE', 'descendants');
    $allActor = ($this->actorWith)(null);
    $otherOwn = ($this->actorWith)('own');

    $record = ScopeFixtureRecord::query()->findOrFail($mine);
    $scope = ($this->scope)();

    expect($scope->allows(DATA_SCOPE_QUERY_RESOURCE, $record, $allActor))->toBeTrue()
        ->and($scope->allows(DATA_SCOPE_QUERY_RESOURCE, $record, $owner))->toBeTrue()
        ->and($scope->allows(DATA_SCOPE_QUERY_RESOURCE, $record, $otherOwn))->toBeFalse()
        ->and($scope->allows(DATA_SCOPE_QUERY_RESOURCE, $record, $dimensionActor))->toBeFalse()
        ->and(($this->codes)($dimensionActor))->not->toContain('NULL_MINE');
});

it('answers allows true in scope and false out of scope', function (): void {
    $actor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'MKT_HOTTOY', 'descendants');
    $scope = ($this->scope)();

    expect($scope->allows(DATA_SCOPE_QUERY_RESOURCE, ScopeFixtureRecord::query()->findOrFail($this->scopeRecordIds['HOTTOY_B']), $actor))->toBeTrue()
        ->and($scope->allows(DATA_SCOPE_QUERY_RESOURCE, ScopeFixtureRecord::query()->findOrFail($this->scopeRecordIds['MKT']), $actor))->toBeFalse()
        ->and($scope->allows(DATA_SCOPE_QUERY_RESOURCE, ScopeFixtureRecord::query()->findOrFail($this->scopeRecordIds['MKT']), null))->toBeFalse();
});

it('throws the same not-found exception for an out-of-scope uuid and an unknown one', function (): void {
    $actor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'MKT_HOTTOY', 'descendants');
    $scope = ($this->scope)();
    $uuidOf = static fn (int $id): string => (string) DB::table('scope_fixture_records')->where('id', $id)->value('uuid');

    $found = $scope->findOrFail(DATA_SCOPE_QUERY_RESOURCE, $uuidOf($this->scopeRecordIds['HOTTOY_A']), $actor);
    expect($found)->toBeInstanceOf(ScopeFixtureRecord::class)->and($found->id)->toBe($this->scopeRecordIds['HOTTOY_A']);

    $capture = static function (Closure $call): Throwable {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        throw new RuntimeException('Expected an exception.');
    };

    $outOfScope = $capture(fn () => $scope->findOrFail(DATA_SCOPE_QUERY_RESOURCE, $uuidOf($this->scopeRecordIds['TRADE']), $actor));
    $unknown = $capture(fn () => $scope->findOrFail(DATA_SCOPE_QUERY_RESOURCE, (string) Str::uuid(), $actor));

    expect($outOfScope)->toBeInstanceOf(ModelNotFoundException::class)
        ->and(get_class($outOfScope))->toBe(get_class($unknown))
        ->and($outOfScope->getMessage())->toBe($unknown->getMessage())
        ->and($outOfScope->getModel())->toBe($unknown->getModel())
        ->and($outOfScope->getIds())->toBe($unknown->getIds());
});

it('allows a target only when the class is unscoped or the record is in scope', function (): void {
    $actor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'MKT_HOTTOY', 'descendants');
    $scope = ($this->scope)();

    expect($scope->allowsTarget(User::class, 12345, $actor))->toBeTrue()
        ->and($scope->allowsTarget(null, null, $actor))->toBeTrue()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $this->scopeRecordIds['HOTTOY_A'], $actor))->toBeTrue()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $this->scopeRecordIds['TRADE'], $actor))->toBeFalse()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, 999999, $actor))->toBeFalse()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, null, $actor))->toBeFalse()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $this->scopeRecordIds['HOTTOY_A'], null))->toBeFalse();
});

it('keeps caller orWhere clauses from widening the scope, before or after apply', function (string $order): void {
    $actor = ($this->actorWith)('fixture');
    ($this->assign)($this->lastRoleId, 'MKT_HOTTOY', 'descendants');
    $scope = ($this->scope)();
    $ids = $this->scopeRecordIds;

    $build = function (int $first, int $second) use ($order, $scope, $actor): Builder {
        $query = ScopeFixtureRecord::query();
        $callerWheres = static fn (Builder $q) => $q->where('id', $first)->orWhere('id', $second);

        if ($order === 'before') {
            $callerWheres($query);

            return $scope->apply($query, DATA_SCOPE_QUERY_RESOURCE, $actor);
        }

        return $callerWheres($scope->apply($query, DATA_SCOPE_QUERY_RESOURCE, $actor));
    };

    // Both caller targets out of scope.
    expect($build($ids['TRADE_X'], $ids['MKT'])->count())->toBe(0)
        ->and($build($ids['TRADE_X'], $ids['MKT'])->get()->all())->toBe([])
        ->and($build($ids['TRADE_X'], $ids['MKT'])->paginate(10)->total())->toBe(0)
        ->and($build($ids['TRADE_X'], $ids['MKT'])->paginate(10)->items())->toBe([]);

    // One caller target in scope, one out: only the in-scope one comes back.
    expect($build($ids['HOTTOY_A'], $ids['MKT'])->count())->toBe(1)
        ->and($build($ids['HOTTOY_A'], $ids['MKT'])->get()->pluck('id')->all())->toBe([$ids['HOTTOY_A']])
        ->and($build($ids['HOTTOY_A'], $ids['MKT'])->paginate(10)->total())->toBe(1)
        ->and(collect($build($ids['HOTTOY_A'], $ids['MKT'])->paginate(10)->items())->pluck('id')->all())->toBe([$ids['HOTTOY_A']]);
})->with(['before apply' => 'before', 'after apply' => 'after']);

it('treats an actor with all on an unrestrictedWith ability as all', function (): void {
    $actor = ($this->actorWith)('own');
    ($this->makeRole)($actor, [DATA_SCOPE_QUERY_MANAGE]);

    $limited = ($this->actorWith)('own');
    $manageRole = ($this->makeRole)($limited, [DATA_SCOPE_QUERY_MANAGE]);
    ($this->grant)($manageRole, 'own', DATA_SCOPE_QUERY_MANAGE);

    expect(($this->scope)()->scopeFor(DATA_SCOPE_QUERY_RESOURCE, User::query()->findOrFail($actor->id))->all)->toBeTrue()
        ->and(($this->codes)(User::query()->findOrFail($actor->id)))->toHaveCount(8)
        ->and(($this->codes)(User::query()->findOrFail($limited->id)))->toBe([]);
});

it('rejects a duplicate resource key and an unknown one', function (): void {
    $registry = app(ScopedResourceRegistry::class);

    expect($registry->forModel(ScopeFixtureRecord::class)?->key())->toBe(DATA_SCOPE_QUERY_RESOURCE)
        ->and($registry->forModel(User::class))->toBeNull()
        ->and(fn () => $registry->register(ScopedResource::make(DATA_SCOPE_QUERY_RESOURCE, ScopeFixtureRecord::class, DATA_SCOPE_QUERY_VIEW)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('fixture/missing'))->toThrow(InvalidArgumentException::class);
});

it('lists every resource of a model in registration order and none for an unregistered model', function (): void {
    $registry = app(ScopedResourceRegistry::class);
    $registry->register(ScopedResource::make('fixture/records-manage', ScopeFixtureRecord::class, DATA_SCOPE_QUERY_MANAGE)
        ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds)));

    expect(array_map(static fn (ScopedResource $r): string => $r->key(), $registry->allForModel(ScopeFixtureRecord::class)))
        ->toBe([DATA_SCOPE_QUERY_RESOURCE, 'fixture/records-manage'])
        ->and(array_map(static fn (ScopedResource $r): string => $r->key(), $registry->allForModel('\\' . ScopeFixtureRecord::class)))
        ->toBe([DATA_SCOPE_QUERY_RESOURCE, 'fixture/records-manage'])
        ->and($registry->allForModel(User::class))->toBe([]);
});

it('checks only the first resource on a read target but every resource on a write target', function (): void {
    // The first resource (view) is wide: scope all. The second (manage) is narrowed to the MKT_HOTTOY subtree.
    app(ScopedResourceRegistry::class)->register(
        ScopedResource::make('fixture/records-manage', ScopeFixtureRecord::class, DATA_SCOPE_QUERY_MANAGE)
            ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds))
    );
    $actor = ($this->makeUser)();
    $roleId = ($this->makeRole)($actor, [DATA_SCOPE_QUERY_VIEW, DATA_SCOPE_QUERY_MANAGE]);
    ($this->grant)($roleId, 'fixture', DATA_SCOPE_QUERY_MANAGE);
    ($this->assign)($roleId, 'MKT_HOTTOY', 'descendants');
    $scope = ($this->scope)();
    $in = $this->scopeRecordIds['HOTTOY_A'];
    $out = $this->scopeRecordIds['TRADE'];

    expect($scope->allowsTarget(ScopeFixtureRecord::class, $in, $actor))->toBeTrue()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $out, $actor))->toBeTrue()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $in, $actor, true))->toBeTrue()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, $out, $actor, true))->toBeFalse()
        ->and($scope->allowsTarget(ScopeFixtureRecord::class, null, $actor, true))->toBeFalse()
        ->and($scope->allowsTarget(User::class, 1, $actor, true))->toBeTrue();
});
