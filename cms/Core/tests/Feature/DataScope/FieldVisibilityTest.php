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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

uses(\Cms\Core\Tests\TestCase::class, DataScopeTestTables::class, ScopeTreeFixture::class);

const FIELD_VISIBILITY_VIEW = 'fixture.visibility.view';
const FIELD_VISIBILITY_SECRETS = 'fixture.visibility.secrets';
const FIELD_VISIBILITY_RESOURCE = 'fixture/visibility';

/**
 * Fake module repository for the field visibility tests: name => [enabled, manifest].
 *
 * @param array<string, array{0: bool, 1: array<string, mixed>}> $modules
 */
function fieldVisibilityFakeModules(array $modules): RepositoryInterface
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
 * A presenter-shaped row with marked fields at the top level, nested in an assoc array and inside a list.
 *
 * @return array<string, mixed>
 */
function fieldVisibilityRow(): array
{
    return [
        'uuid' => 'c0ffee00-0000-4000-8000-000000000001',
        'title' => 'Printer jam',
        'internal_note' => 'VIP customer',
        'contact' => ['name' => 'Ann', 'email' => 'ann@example.test', 'phone' => '0900'],
        'members' => [
            ['role' => 'owner', 'customer' => ['name' => 'Ann', 'email' => 'ann@example.test']],
            ['role' => 'member', 'customer' => ['name' => 'Bob']],
            ['role' => 'guest', 'customer' => null],
        ],
        'counts' => ['open' => 2, 'ratio' => 0.5, 'flag' => false, 'none' => null],
        'tags' => [],
    ];
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

    $modules = fieldVisibilityFakeModules([
        'Fixture' => [true, ['permissions' => [
            ['key' => FIELD_VISIBILITY_VIEW, 'scopes' => ['all', 'own', 'fixture']],
        ]]],
    ]);
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $this->scopeClosureHierarchy(), 'fixture.dimension');

    $resources = new ScopedResourceRegistry();
    $resources->register(
        ScopedResource::make(FIELD_VISIBILITY_RESOURCE, ScopeFixtureRecord::class, FIELD_VISIBILITY_VIEW)
            ->own(static fn (Builder $q, User $actor) => $q->where('owner_user_id', $actor->id))
            ->dimension('fixture', static fn (Builder $q, $visibleNodeIds) => $q->whereIn('node_id', $visibleNodeIds))
            ->hideUnless('contact.email', FIELD_VISIBILITY_SECRETS)
            ->hideUnless('members.*.customer.email', FIELD_VISIBILITY_SECRETS)
            ->hideUnless('missing.deep.key', FIELD_VISIBILITY_SECRETS)
            ->hideUnlessAll('internal_note')
    );

    app()->instance(DimensionRegistry::class, $dimensions);
    app()->instance(ScopedResourceRegistry::class, $resources);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();
    CMSLogger::spy();

    $this->makeUser = static function (): User {
        $id = (int) DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Tester']);

        return User::query()->findOrFail($id);
    };

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

    /** A user holding the given keys through one role, with an optional grant level for the view permission. */
    $this->actor = function (array $keys, ?string $level = null, array $custom = []): User {
        $user = ($this->makeUser)();
        $roleId = ($this->makeRole)($user, $keys, $custom);

        if ($level !== null) {
            DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $roleId, 'permission_key' => FIELD_VISIBILITY_VIEW, 'level' => $level]);
        }

        if ($level === 'fixture') {
            DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
                'uuid' => (string) Str::uuid(),
                'subject_type' => 'role',
                'subject_id' => $roleId,
                'dimension' => 'fixture',
                'node_id' => $this->nodeId('MKT'),
                'expansion' => 'descendants',
            ]);
        }

        return User::query()->findOrFail($user->id);
    };

    $this->scope = static fn (): DataScope => app(DataScope::class);
});

afterEach(function (): void {
    foreach (['data_scope_assignments', 'data_scope_grants', 'role_user', 'permission_role', 'permissions', 'roles', 'users',
        'scope_fixture_records', 'scope_fixture_closures', 'scope_fixture_nodes'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('removes a hideUnless field for an actor without the ability and keeps it for one who has it', function (): void {
    $without = ($this->actor)([FIELD_VISIBILITY_VIEW]);
    $with = ($this->actor)([FIELD_VISIBILITY_VIEW, FIELD_VISIBILITY_SECRETS]);
    $row = fieldVisibilityRow();

    $stripped = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $without);
    $kept = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $with);

    expect($stripped['contact'])->toBe(['name' => 'Ann', 'phone' => '0900'])
        ->and($kept)->toBe($row)
        ->and(($this->scope)()->hiddenFields(FIELD_VISIBILITY_RESOURCE, $with))->toBe([])
        ->and(($this->scope)()->hiddenFields(FIELD_VISIBILITY_RESOURCE, $without))
        ->toBe(['contact.email', 'members.*.customer.email', 'missing.deep.key']);
});

it('keeps hideUnless fields for a webmaster without the ability', function (): void {
    $webmaster = ($this->actor)([FIELD_VISIBILITY_VIEW], null, ['level' => 0]);

    expect(($this->scope)()->strip(fieldVisibilityRow(), FIELD_VISIBILITY_RESOURCE, $webmaster))->toBe(fieldVisibilityRow());
});

it('removes a hideUnlessAll field for own and dimension actors and keeps it for all', function (): void {
    $keys = [FIELD_VISIBILITY_VIEW, FIELD_VISIBILITY_SECRETS];
    $own = ($this->actor)($keys, 'own');
    $dimension = ($this->actor)($keys, 'fixture');
    $all = ($this->actor)($keys);
    $row = fieldVisibilityRow();
    $expected = $row;
    unset($expected['internal_note']);

    expect(($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $own))->toBe($expected)
        ->and(($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $dimension))->toBe($expected)
        ->and(($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $all))->toBe($row)
        ->and(($this->scope)()->hiddenFields(FIELD_VISIBILITY_RESOURCE, $own))->toBe(['internal_note']);
});

it('strips every marked field for a null actor and leaves the rest byte-for-byte unchanged', function (): void {
    $row = fieldVisibilityRow();

    $stripped = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null);

    $expected = [
        'uuid' => 'c0ffee00-0000-4000-8000-000000000001',
        'title' => 'Printer jam',
        'contact' => ['name' => 'Ann', 'phone' => '0900'],
        'members' => [
            ['role' => 'owner', 'customer' => ['name' => 'Ann']],
            ['role' => 'member', 'customer' => ['name' => 'Bob']],
            ['role' => 'guest', 'customer' => null],
        ],
        'counts' => ['open' => 2, 'ratio' => 0.5, 'flag' => false, 'none' => null],
        'tags' => [],
    ];

    expect($stripped)->toBe($expected)
        ->and(array_keys($stripped))->toBe(array_keys($expected))
        ->and(json_encode($stripped))->toBe(json_encode($expected))
        ->and($stripped)->not->toHaveKey('missing')
        ->and(($this->scope)()->hiddenFields(FIELD_VISIBILITY_RESOURCE, null))
        ->toBe(['contact.email', 'members.*.customer.email', 'missing.deep.key', 'internal_note']);
});

it('applies the wildcard over associative arrays as well as lists', function (): void {
    $row = ['members' => [
        'first' => ['customer' => ['email' => 'a@example.test', 'name' => 'A']],
        'second' => ['customer' => 'not-an-array'],
        'third' => ['other' => 1],
    ]];

    expect(($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null))->toBe(['members' => [
        'first' => ['customer' => ['name' => 'A']],
        'second' => ['customer' => 'not-an-array'],
        'third' => ['other' => 1],
    ]]);
});

it('strips many rows with one hidden-field computation', function (): void {
    $actor = ($this->actor)([FIELD_VISIBILITY_VIEW], 'own');
    $rows = ['a' => fieldVisibilityRow(), 'b' => ['title' => 'Other', 'contact' => ['email' => 'x@example.test']]];
    $scope = ($this->scope)();
    $scope->scopeFor(FIELD_VISIBILITY_RESOURCE, $actor);

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $stripped = $scope->stripMany($rows, FIELD_VISIBILITY_RESOURCE, $actor);

    expect($queries)->toBe(0)
        ->and(array_keys($stripped))->toBe(['a', 'b'])
        ->and($stripped['a'])->not->toHaveKey('internal_note')
        ->and($stripped['a']['contact'])->toBe(['name' => 'Ann', 'phone' => '0900'])
        ->and($stripped['b'])->toBe(['title' => 'Other', 'contact' => []]);
});

it('rejects an empty field path or ability', function (): void {
    expect(fn () => ScopedResource::make('fixture/bad', ScopeFixtureRecord::class, FIELD_VISIBILITY_VIEW)->hideUnless('', 'x.y'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScopedResource::make('fixture/bad', ScopeFixtureRecord::class, FIELD_VISIBILITY_VIEW)->hideUnless('a..b', 'x.y'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScopedResource::make('fixture/bad', ScopeFixtureRecord::class, FIELD_VISIBILITY_VIEW)->hideUnless('a.b', ' '))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScopedResource::make('fixture/bad', ScopeFixtureRecord::class, FIELD_VISIBILITY_VIEW)->hideUnlessAll(''))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * A plain Eloquent model carrying the given attributes (no table needed).
 *
 * @param array<string, mixed> $attributes
 */
function fieldVisibilityModel(array $attributes): \Illuminate\Database\Eloquent\Model
{
    return (new class extends \Illuminate\Database\Eloquent\Model {})->forceFill($attributes);
}

/**
 * Nested value shapes that must all lose `customer.email`, keyed by a readable label.
 *
 * @return array<string, \Closure(): mixed>
 */
function fieldVisibilityCustomerShapes(): array
{
    $attributes = ['name' => 'Ann', 'email' => 'ann@example.test'];

    return [
        'model' => static fn () => fieldVisibilityModel($attributes),
        'json resource' => static fn () => new \Illuminate\Http\Resources\Json\JsonResource($attributes),
        'resource wrapping a model' => static fn () => new \Illuminate\Http\Resources\Json\JsonResource(fieldVisibilityModel($attributes)),
        'arrayable' => static fn () => new \Illuminate\Support\Collection($attributes),
        'json serializable' => static fn () => new class($attributes) implements \JsonSerializable {
            public function __construct(private array $data)
            {
            }

            public function jsonSerialize(): array
            {
                return $this->data;
            }
        },
    ];
}

it('removes a hidden path that runs through a nested model, resource, arrayable or json serializable', function (string $shape): void {
    $customer = fieldVisibilityCustomerShapes()[$shape]();
    $row = ['title' => 'T', 'members' => [['role' => 'owner', 'customer' => $customer]]];

    $stripped = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null);

    expect($stripped)->toBe(['title' => 'T', 'members' => [['role' => 'owner', 'customer' => ['name' => 'Ann']]]]);
})->with(['model', 'json resource', 'resource wrapping a model', 'arrayable', 'json serializable']);

it('accepts a model, resource or arrayable as the row itself', function (string $shape): void {
    $row = fieldVisibilityCustomerShapes()[$shape]();
    $nested = fieldVisibilityModel(['name' => 'Ann', 'email' => 'ann@example.test']);
    $top = $shape === 'model'
        ? fieldVisibilityModel(['title' => 'T', 'contact' => $nested, 'internal_note' => 'x'])
        : new \Illuminate\Support\Collection(['title' => 'T', 'contact' => $row, 'internal_note' => 'x']);

    expect(($this->scope)()->strip($top, FIELD_VISIBILITY_RESOURCE, null))
        ->toBe(['title' => 'T', 'contact' => ['name' => 'Ann']]);
})->with(['model', 'arrayable']);

it('accepts a json resource as the row itself', function (): void {
    $row = new \Illuminate\Http\Resources\Json\JsonResource(['title' => 'T', 'contact' => ['email' => 'a@example.test', 'name' => 'A']]);

    expect(($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null))->toBe(['title' => 'T', 'contact' => ['name' => 'A']]);
});

it('strips a collection of models and keeps the keys', function (): void {
    $rows = collect(['a' => fieldVisibilityModel(['title' => 'A', 'internal_note' => 'x']), 'b' => ['title' => 'B', 'internal_note' => 'y']]);

    expect(($this->scope)()->stripMany($rows, FIELD_VISIBILITY_RESOURCE, null))->toBe(['a' => ['title' => 'A'], 'b' => ['title' => 'B']]);
});

it('normalises rows of a nested resource inside stripMany', function (): void {
    $rows = [fieldVisibilityModel(['title' => 'A', 'contact' => ['email' => 'e@example.test', 'name' => 'E']])];

    expect(($this->scope)()->stripMany($rows, FIELD_VISIBILITY_RESOURCE, null))->toBe([['title' => 'A', 'contact' => ['name' => 'E']]]);
});

it('throws when a value the hidden path must traverse cannot become an array', function (mixed $container): void {
    $row = ['contact' => $container];

    expect(fn () => ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null))->toThrow(UnexpectedValueException::class)
        ->and(fn () => ($this->scope)()->stripMany([$row], FIELD_VISIBILITY_RESOURCE, null))->toThrow(UnexpectedValueException::class);
})->with([
    'stdClass' => fn () => new \stdClass(),
    'plain object' => fn () => new class {
        public string $email = 'x';
    },
]);

it('throws when the row itself cannot become an array', function (): void {
    expect(fn () => ($this->scope)()->strip(new \stdClass(), FIELD_VISIBILITY_RESOURCE, null))->toThrow(UnexpectedValueException::class)
        ->and(fn () => ($this->scope)()->strip('text', FIELD_VISIBILITY_RESOURCE, null))->toThrow(UnexpectedValueException::class);
});

it('leaves values off the hidden paths untouched and returns an unchanged plain row when nothing is hidden', function (): void {
    $date = \Illuminate\Support\Carbon::parse('2026-01-02 03:04:05');
    $object = new \stdClass();
    $row = ['title' => 'T', 'created_at' => $date, 'extra' => $object, 'contact' => ['email' => 'a@example.test', 'since' => $date]];

    $stripped = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, null);
    $all = ($this->actor)([FIELD_VISIBILITY_VIEW, FIELD_VISIBILITY_SECRETS]);
    $kept = ($this->scope)()->strip($row, FIELD_VISIBILITY_RESOURCE, $all);

    expect($stripped['created_at'])->toBe($date)
        ->and($stripped['extra'])->toBe($object)
        ->and($stripped['contact']['since'])->toBe($date)
        ->and($stripped['contact'])->not->toHaveKey('email')
        ->and($kept)->toBe($row);
});
