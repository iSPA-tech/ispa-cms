<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Cms\Core\Tests\Support\DataScopeTestTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

uses(\Cms\Core\Tests\TestCase::class, DataScopeTestTables::class, ScopeTreeFixture::class);

const SCOPE_SUMMARY_KEY = 'fixture.summary.view';

/**
 * Fake module repository for the summary tests: name => [enabled, manifest].
 *
 * @param array<string, array{0: bool, 1: array<string, mixed>}> $modules
 */
function scopeSummaryFakeModules(array $modules): RepositoryInterface
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

/**
 * Collect every [path => value] leaf and every key of a nested array.
 *
 * @param array<mixed> $data
 * @param array<int, string|int> $keys
 * @param array<string, mixed> $leaves
 */
function scopeSummaryWalk(array $data, array &$keys, array &$leaves, string $prefix = ''): void
{
    foreach ($data as $key => $value) {
        $keys[] = $key;
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            scopeSummaryWalk($value, $keys, $leaves, $path);
        } else {
            $leaves[$path] = $value;
        }
    }
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

    $modules = scopeSummaryFakeModules([
        'Fixture' => [true, ['permissions' => [
            ['key' => SCOPE_SUMMARY_KEY, 'scopes' => ['all', 'own', 'fixture']],
        ]]],
    ]);
    $registry = new DimensionRegistry($modules);
    $registry->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');

    app()->instance(DimensionRegistry::class, $registry);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();
    CMSLogger::spy();

    $this->userIds = [1];

    $this->makeUser = function (): User {
        $id = (int) DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Tester']);
        $this->userIds[] = $id;

        return User::query()->findOrFail($id);
    };

    /** Create a role holding the summary permission, attached to the user, with an optional grant level. */
    $this->makeRole = static function (User $user, ?string $level): int {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . Str::random(6)]);
        $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId([
            'group' => 'fixture.summary',
            'name' => 'view',
            'title' => SCOPE_SUMMARY_KEY,
        ]);
        DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        if ($level !== null) {
            DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $roleId, 'permission_key' => SCOPE_SUMMARY_KEY, 'level' => $level]);
        }

        return $roleId;
    };

    $this->assign = function (string $type, int $subjectId, int $nodeId, string $expansion): void {
        DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
            'uuid' => (string) Str::uuid(),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'dimension' => 'fixture',
            'node_id' => $nodeId,
            'expansion' => $expansion,
        ]);
    };

    $this->summary = static fn (User $user, string $key = SCOPE_SUMMARY_KEY): array => app(ScopeSummary::class)
        ->for(User::query()->findOrFail($user->id), $key);

    /** Assert that the summary carries no `id`-like keys and no node or user id as a value. */
    $this->assertNoIds = function (array $summary): void {
        $keys = [];
        $leaves = [];
        scopeSummaryWalk($summary, $keys, $leaves);
        $ids = array_merge(array_values($this->scopeNodeIds), $this->userIds,
            DB::table('scope_fixture_nodes')->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        foreach ($keys as $key) {
            if (is_string($key)) {
                expect($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, 'Id'))->toBeFalse("key {$key}");
            }
        }

        foreach ($leaves as $path => $value) {
            if (str_ends_with($path, '.more')) {
                expect($value)->toBeInt();

                continue;
            }

            expect(is_int($value) || is_float($value))->toBeFalse("numeric value at {$path}");

            if (is_string($value) && ctype_digit($value)) {
                expect(in_array((int) $value, $ids, true))->toBeFalse("id-like value at {$path}");
            }
        }
    };
});

afterEach(function (): void {
    foreach (['data_scope_assignments', 'data_scope_grants', 'role_user', 'permission_role', 'permissions', 'roles', 'users',
        'scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('summarises scope all without dimensions', function (): void {
    $user = ($this->makeUser)();
    ($this->makeRole)($user, null);

    $summary = ($this->summary)($user);

    expect($summary)->toBe(['level' => 'all', 'own' => false, 'dimensions' => []]);
    ($this->assertNoIds)($summary);
});

it('summarises scope own without dimensions', function (): void {
    $user = ($this->makeUser)();
    ($this->makeRole)($user, 'own');

    $summary = ($this->summary)($user);

    expect($summary)->toBe(['level' => 'own', 'own' => true, 'dimensions' => []]);
    ($this->assertNoIds)($summary);
});

it('summarises scope none for a user without the permission, even with assignments', function (): void {
    $user = ($this->makeUser)();
    ($this->assign)('user', $user->id, $this->nodeId('MKT'), 'descendants');

    $summary = ($this->summary)($user);

    expect($summary)->toBe(['level' => 'none', 'own' => false, 'dimensions' => []]);
    ($this->assertNoIds)($summary);
});

it('summarises dimension nodes from the held nodes, deduplicated, without deleted ones', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, 'fixture');
    ($this->assign)('role', $roleId, $this->nodeId('MKT_HOTTOY'), 'descendants');
    ($this->assign)('role', $roleId, $this->nodeId('HOTTOY_A'), 'self');
    ($this->assign)('user', $user->id, $this->nodeId('HOTTOY_A'), 'self');
    ($this->assign)('role', $roleId, $this->nodeId('TRADE_X'), 'branch');
    $this->softDelete('TRADE_X');

    $summary = ($this->summary)($user);

    expect($summary)->toBe([
        'level' => 'nodes',
        'own' => false,
        'dimensions' => [[
            'name' => 'fixture',
            'label' => __('fixture.dimension'),
            'label_key' => 'fixture.dimension',
            'nodes' => [
                ['key' => 'HOTTOY_A', 'label' => 'Hottoy A', 'trail' => ['Mkt', 'Mkt Hottoy', 'Hottoy A'], 'expansion' => 'self'],
                ['key' => 'MKT_HOTTOY', 'label' => 'Mkt Hottoy', 'trail' => ['Mkt', 'Mkt Hottoy'], 'expansion' => 'descendants'],
            ],
            'more' => 0,
        ]],
    ]);
    ($this->assertNoIds)($summary);
});

it('carries the label key alongside the label frozen in the active locale', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, 'fixture');
    ($this->assign)('role', $roleId, $this->nodeId('TRADE'), 'self');

    app()->setLocale('vi');
    $summary = ($this->summary)($user);

    expect($summary['dimensions'][0]['label_key'])->toBe('fixture.dimension')
        ->and($summary['dimensions'][0]['label'])->toBe((string) __('fixture.dimension'));
});

it('summarises own plus dimension nodes as mixed', function (): void {
    $user = ($this->makeUser)();
    ($this->makeRole)($user, 'own');
    $roleId = ($this->makeRole)($user, 'fixture');
    ($this->assign)('role', $roleId, $this->nodeId('TRADE'), 'descendants');

    $summary = ($this->summary)($user);

    expect($summary['level'])->toBe('mixed')
        ->and($summary['own'])->toBeTrue()
        ->and($summary['dimensions'])->toBe([[
            'name' => 'fixture',
            'label' => __('fixture.dimension'),
            'label_key' => 'fixture.dimension',
            'nodes' => [['key' => 'TRADE', 'label' => 'Trade', 'trail' => ['Trade'], 'expansion' => 'descendants']],
            'more' => 0,
        ]]);
    ($this->assertNoIds)($summary);
});

it('lists at most 50 nodes per dimension sorted by label and counts the rest', function (): void {
    $user = ($this->makeUser)();
    $roleId = ($this->makeRole)($user, 'fixture');
    $tradeId = $this->nodeId('TRADE');

    for ($i = 55; $i >= 1; $i--) {
        $code = sprintf('BULK_%02d', $i);
        $id = (int) DB::table('scope_fixture_nodes')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $tradeId,
            'code' => $code,
            'name' => sprintf('Bulk %02d', $i),
            'path' => '/TRADE/' . $code . '/',
        ]);
        DB::table('scope_fixture_closures')->insert([
            ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0],
            ['ancestor_id' => $tradeId, 'descendant_id' => $id, 'depth' => 1],
        ]);
        ($this->assign)('role', $roleId, $id, 'self');
    }

    $summary = ($this->summary)($user);
    $nodes = $summary['dimensions'][0]['nodes'];

    expect($summary['level'])->toBe('nodes')
        ->and($nodes)->toHaveCount(50)
        ->and($summary['dimensions'][0]['more'])->toBe(5)
        ->and(array_column($nodes, 'key'))->toBe(array_map(static fn (int $i): string => sprintf('BULK_%02d', $i), range(1, 50)))
        ->and($nodes[0])->toBe(['key' => 'BULK_01', 'label' => 'Bulk 01', 'trail' => ['Trade', 'Bulk 01'], 'expansion' => 'self']);
    ($this->assertNoIds)($summary);
});
