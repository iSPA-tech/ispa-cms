<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\DataScope\DimensionRegistry;
use Cms\Core\Services\DataScope\ManifestScopeCatalog;
use Cms\Core\Services\DataScope\ScopeSummary;
use Cms\Core\Tests\Fixtures\DataScope\ScopeTreeFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;

/*
 * the data-scope read API (dimensions, node search, role and user scope), gated by data_scope.manage.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class, ScopeTreeFixture::class);

const DS_READ_KEY = 'fixture.records.view';
const DS_READ_PLAIN_KEY = 'fixture.plain.view';
const DS_READ_BASE = '/api/v2/admin/data-scope';

/**
 * Fake module repository declaring the scoped fixture permission.
 */
function dsReadModules(bool $fixtureEnabled = true): RepositoryInterface
{
    $manifest = [
        'permissions' => [['key' => DS_READ_KEY, 'label' => 'text.fixture.perm.records', 'scopes' => ['all', 'own', 'fixture']]],
        'scope_dimensions' => [['name' => 'fixture', 'hierarchy' => 'closure']],
    ];
    $module = Mockery::mock(Module::class);
    $module->shouldReceive('getName')->andReturn('Fixture');
    $module->shouldReceive('isEnabled')->andReturn($fixtureEnabled);
    $module->shouldReceive('get')->andReturnUsing(static fn (string $key, $default = null) => $manifest[$key] ?? $default);

    $repo = Mockery::mock(RepositoryInterface::class);
    $repo->shouldReceive('allEnabled')->andReturn($fixtureEnabled ? ['Fixture' => $module] : []);
    $repo->shouldReceive('find')->andReturnUsing(static fn (string $name) => $name === 'Fixture' ? $module : null);

    return $repo;
}

/**
 * Create an admin user.
 */
function dsReadUser(string $name): User
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
 * Create a role holding the permission keys and attach it to the user (when given); returns the role.
 *
 * @param array<int, string> $keys
 */
function dsReadRole(?User $user, array $keys): Role
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
 * Bind the fixture dimension and catalog into the container.
 */
function dsReadBind(object $test, bool $enabled = true): void
{
    $modules = dsReadModules($enabled);
    $dimensions = new DimensionRegistry($modules);
    $dimensions->register('fixture', 'Fixture', $test->scopeClosureHierarchy(), 'fixture.dimension');

    app()->instance(DimensionRegistry::class, $dimensions);
    app()->instance(RepositoryInterface::class, $modules);
    app()->scoped(ManifestScopeCatalog::class, static fn () => new ManifestScopeCatalog($modules));
    app()->forgetScopedInstances();
}

/**
 * Collect every key of a nested array.
 *
 * @param array<mixed> $data
 * @return array<int, string|int>
 */
function dsReadKeys(array $data): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        $keys[] = $key;

        if (is_array($value)) {
            $keys = array_merge($keys, dsReadKeys($value));
        }
    }

    return $keys;
}

beforeEach(function (): void {
    $this->withoutMiddleware([\App\Http\Middleware\HttpsProtocol::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
    $this->createScopeTreeFixture();
    dsReadBind($this);

    $this->manager = dsReadUser('manager');
    dsReadRole($this->manager, ['data_scope.manage']);
    $this->outsider = dsReadUser('outsider');

    $this->get = function (string $path, ?User $as = null) {
        Laravel\Sanctum\Sanctum::actingAs($as ?? $this->manager, ['*']);

        return $this->getJson(DS_READ_BASE . $path);
    };

    $this->subject = dsReadUser('subject');
    $this->subjectRole = dsReadRole($this->subject, [DS_READ_KEY, DS_READ_PLAIN_KEY]);
    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->insert(['role_id' => $this->subjectRole->id, 'permission_key' => DS_READ_KEY, 'level' => 'fixture']);
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
        'uuid' => (string) Str::uuid(),
        'subject_type' => 'role',
        'subject_id' => $this->subjectRole->id,
        'dimension' => 'fixture',
        'node_id' => $this->nodeId('MKT_HOTTOY'),
        'expansion' => 'descendants',
    ]);
});

test('every endpoint answers 403 without data_scope.manage', function (): void {
    foreach (['/dimensions', '/dimensions/fixture/nodes', '/roles/' . $this->subjectRole->id, '/users/' . $this->subject->uuid] as $path) {
        ($this->get)($path, $this->outsider)->assertForbidden();
    }
});

test('dimensions lists enabled dimensions with label, module and hierarchy', function (): void {
    $res = ($this->get)('/dimensions')->assertOk();

    expect($res->json('data'))->toBe([
        ['name' => 'fixture', 'label' => 'fixture.dimension', 'label_key' => 'fixture.dimension', 'module' => 'Fixture', 'hierarchy' => 'closure'],
    ]);
});

test('a disabled dimension is not listed and its nodes answer 404', function (): void {
    dsReadBind($this, false);

    expect(($this->get)('/dimensions')->assertOk()->json('data') ?? [])->toBe([]);
    ($this->get)('/dimensions/fixture/nodes')->assertNotFound();
});

test('unknown or malformed dimension answers 404', function (): void {
    ($this->get)('/dimensions/nope/nodes')->assertNotFound();
    ($this->get)('/dimensions/Bad-Name/nodes')->assertNotFound();
});

test('node search matches labels and returns trails and string keys', function (): void {
    $nodes = ($this->get)('/dimensions/fixture/nodes?q=hottoy')->assertOk()->json('data');

    expect(collect($nodes)->pluck('label')->all())->toContain('Mkt Hottoy', 'Hottoy A', 'Hottoy B');

    $hottoyA = collect($nodes)->firstWhere('label', 'Hottoy A');
    expect($hottoyA['key'])->toBeString()->toBe('HOTTOY_A');
    expect($hottoyA['trail'])->toBe(['Mkt', 'Mkt Hottoy', 'Hottoy A']);
    expect(array_keys($hottoyA))->toBe(['key', 'label', 'trail']);
});

test('node search is capped at 20 results', function (): void {
    for ($i = 1; $i <= 25; $i++) {
        $code = sprintf('BULK_%02d', $i);
        DB::table('scope_fixture_nodes')->insert([
            'uuid' => (string) Str::uuid(), 'parent_id' => null, 'code' => $code, 'name' => 'Bulk ' . $i, 'path' => '/' . $code . '/',
        ]);
    }

    $nodes = ($this->get)('/dimensions/fixture/nodes?q=Bulk')->assertOk()->json('data');

    expect($nodes)->toHaveCount(20);
});

test('a role lists only scoped permissions with their level', function (): void {
    $data = ($this->get)('/roles/' . $this->subjectRole->id)->assertOk()->json('data');

    expect($data['grants'])->toBe([[
        'permission_key' => DS_READ_KEY,
        'title' => 'Title ' . DS_READ_KEY,
        'label_key' => 'text.fixture.perm.records',
        'allowed_levels' => ['all', 'own', 'fixture'],
        'level' => 'fixture',
    ]]);

    expect($data['assignments'])->toHaveCount(1);
    expect($data['assignments'][0])->toMatchArray([
        'dimension' => 'fixture',
        'expansion' => 'descendants',
        'node' => ['key' => 'MKT_HOTTOY', 'label' => 'Mkt Hottoy', 'trail' => ['Mkt', 'Mkt Hottoy']],
    ]);
    expect($data['assignments'][0]['uuid'])->toBeString();
});

test('a scoped permission without a grant row reads as level all', function (): void {
    DB::table(CoreTableConstant::DATA_SCOPE_GRANTS)->where('role_id', $this->subjectRole->id)->delete();

    $grants = ($this->get)('/roles/' . $this->subjectRole->id)->assertOk()->json('data.grants');

    expect($grants[0]['level'])->toBe('all');
});

test('unknown role or user answers 404', function (): void {
    ($this->get)('/roles/999999')->assertNotFound();
    ($this->get)('/users/' . Str::uuid())->assertNotFound();
});

test('a user effective summary equals ScopeSummary output', function (): void {
    $data = ($this->get)('/users/' . $this->subject->uuid)->assertOk()->json('data');

    $expected = app(ScopeSummary::class)->for($this->subject, DS_READ_KEY);

    expect($data['effective'])->toHaveCount(1);
    expect($data['effective'][0]['permission_key'])->toBe(DS_READ_KEY);
    expect($data['effective'][0]['summary'])->toBe($expected);
    expect($expected['level'])->toBe('nodes');
    expect($data['assignments'])->toBe([]);
});

test('a user assignment is listed under the user', function (): void {
    DB::table(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)->insert([
        'uuid' => (string) Str::uuid(),
        'subject_type' => 'user',
        'subject_id' => $this->subject->id,
        'dimension' => 'fixture',
        'node_id' => $this->nodeId('TRADE'),
        'expansion' => 'self',
    ]);

    $assignments = ($this->get)('/users/' . $this->subject->uuid)->assertOk()->json('data.assignments');

    expect($assignments)->toHaveCount(1);
    expect($assignments[0]['node']['key'])->toBe('TRADE');
});

test('no response carries numeric node ids', function (): void {
    $responses = [
        ($this->get)('/dimensions/fixture/nodes?q=')->json('data'),
        ($this->get)('/roles/' . $this->subjectRole->id)->json('data'),
        ($this->get)('/users/' . $this->subject->uuid)->json('data'),
    ];

    foreach ($responses as $data) {
        $keys = dsReadKeys($data);
        expect($keys)->not->toContain('node_id');
        expect($keys)->not->toContain('id');
    }

    foreach (($this->get)('/dimensions/fixture/nodes?q=')->json('data') as $node) {
        expect($node['key'])->toBeString();
    }
});

test('a user effective entry carries the manifest permission label_key', function (): void {
    $effective = ($this->get)('/users/' . $this->subject->uuid)->assertOk()->json('data.effective');

    expect($effective[0]['label_key'])->toBe('text.fixture.perm.records');
});
