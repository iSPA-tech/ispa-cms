<?php

declare(strict_types=1);

use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\StarterPacks\StarterPackException;
use Cms\Core\Services\StarterPacks\StarterRoleApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function (): void {
    foreach (['role_user', 'permission_role', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100)->nullable();
        $table->text('custom')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->char('group', 50)->nullable();
        $table->char('name', 50)->nullable();
        $table->string('title', 255)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('permission_role', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('permission_id');
    });

    Schema::create('role_user', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('user_id');
    });

    foreach (['helpdesk.agent', 'helpdesk.view', 'helpdesk.manage', 'post.view'] as $key) {
        [$group, $name] = explode('.', $key);
        Permission::create(['group' => $group, 'name' => $name, 'title' => $key]);
    }

    // User id 1 is always a Webmaster (User::isWebmaster); it is the acting user.
    DB::table('users')->insert(['id' => 1, 'username' => 'root']);
    // A pre-existing role with a member and a permission, to prove nothing pre-existing is touched.
    $editor = Role::create(['name' => 'Editor', 'custom' => ['level' => 5, 'badge' => 'info']]);
    $editor->permissions()->attach(sraPermissionIds(['post.view']));
    $member = (int) DB::table('users')->insertGetId(['username' => 'member']);
    DB::table('role_user')->insert(['role_id' => $editor->id, 'user_id' => $member]);
});

/**
 * Permission ids for "group.name" keys.
 *
 * @param list<string> $keys
 *
 * @return list<int>
 */
function sraPermissionIds(array $keys): array
{
    return Permission::query()->get()
        ->filter(fn (Permission $p): bool => in_array($p->key, $keys, true))
        ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
}

/**
 * Sorted permission keys attached to a role.
 *
 * @return list<string>
 */
function sraKeys(Role $role): array
{
    $keys = $role->fresh()->permissions->map(fn (Permission $p): string => $p->key)->all();
    sort($keys);

    return $keys;
}

/**
 * Snapshot of all roles and link rows that existed before an operation.
 *
 * @return array<string, mixed>
 */
function sraSnapshot(): array
{
    return [
        'roles' => DB::table('roles')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all(),
        'permission_role' => DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()
            ->map(fn ($r): array => (array) $r)->all(),
        'role_user' => DB::table('role_user')->orderBy('role_id')->orderBy('user_id')->get()
            ->map(fn ($r): array => (array) $r)->all(),
    ];
}

/**
 * Assert that every row of the earlier snapshot is still present, unchanged.
 *
 * @param array<string, mixed> $before
 */
function sraAssertPreservedSince(array $before): void
{
    $after = sraSnapshot();
    $afterRoles = collect($after['roles'])->keyBy('id');
    foreach ($before['roles'] as $role) {
        expect($afterRoles[$role['id']] ?? null)->toBe($role);
    }

    foreach (['permission_role', 'role_user'] as $table) {
        foreach ($before[$table] as $link) {
            expect($after[$table])->toContain($link);
        }
    }

    // Links of pre-existing roles never grow either.
    $oldIds = collect($before['roles'])->pluck('id')->all();
    foreach (['permission_role', 'role_user'] as $table) {
        $oldNow = collect($after[$table])->filter(fn (array $l): bool => in_array($l['role_id'], $oldIds, true))->values()->all();
        expect($oldNow)->toBe($before[$table]);
    }
}

/**
 * A valid one-role pack.
 *
 * @return list<array<string, mixed>>
 */
function sraPack(): array
{
    return [[
        'name' => 'Help center agent',
        'label' => ['vi' => 'Nhân viên hỗ trợ', 'en' => 'Help center agent'],
        'custom' => ['level' => 20],
        'permissions' => ['helpdesk|agent', 'helpdesk|view'],
    ]];
}

it('creates a new role with exactly the listed permissions, the label in custom, and no members', function (): void {
    $before = sraSnapshot();

    $result = app(StarterRoleApplier::class)->apply(sraPack(), 1);

    expect($result)->toBe(['created' => ['Help center agent'], 'skipped' => [], 'missing_permissions' => []]);
    $role = Role::query()->where('name', 'Help center agent')->firstOrFail();
    expect(sraKeys($role))->toBe(['helpdesk.agent', 'helpdesk.view'])
        ->and($role->custom)->toBe(['label' => ['vi' => 'Nhân viên hỗ trợ', 'en' => 'Help center agent'], 'level' => 20])
        ->and(DB::table('role_user')->where('role_id', $role->id)->count())->toBe(0);
    sraAssertPreservedSince($before);
});

it('skips an existing role with the same name and leaves its permissions and custom unchanged', function (): void {
    $before = sraSnapshot();
    $editor = Role::query()->where('name', 'Editor')->firstOrFail();

    $result = app(StarterRoleApplier::class)->apply([[
        'name' => ' editor ',
        'label' => ['vi' => 'Biên tập', 'en' => 'Editor'],
        'permissions' => ['helpdesk|agent', 'helpdesk|manage', 'post|view'],
    ]], 1);

    expect($result['created'])->toBe([])
        ->and($result['skipped'])->toBe([['name' => 'editor', 'reason' => 'role already exists; left unchanged']])
        ->and(sraKeys($editor))->toBe(['post.view'])
        ->and($editor->fresh()->custom)->toBe(['level' => 5, 'badge' => 'info'])
        ->and(Role::query()->count())->toBe(1);
    sraAssertPreservedSince($before);
});

it('rejects Webmaster and super-admin names in any case and writes nothing', function (string $name): void {
    $before = sraSnapshot();
    $pack = sraPack();
    $pack[] = ['name' => $name, 'permissions' => ['helpdesk|manage']];

    expect(fn () => app(StarterRoleApplier::class)->apply($pack, 1))->toThrow(StarterPackException::class);
    expect(sraSnapshot())->toBe($before);
})->with(['Webmaster', '  webMASTER ', 'super-admin', ' SUPER-Admin']);

it('reports a missing permission in group|name form and still creates the role with the rest', function (): void {
    $before = sraSnapshot();
    $pack = sraPack();
    $pack[0]['permissions'][] = 'helpdesk|export';

    $result = app(StarterRoleApplier::class)->apply($pack, 1);

    expect($result['created'])->toBe(['Help center agent'])
        ->and($result['missing_permissions'])->toBe(['Help center agent' => ['helpdesk|export']])
        ->and(sraKeys(Role::query()->where('name', 'Help center agent')->firstOrFail()))->toBe(['helpdesk.agent', 'helpdesk.view']);
    sraAssertPreservedSince($before);
});

it('changes nothing on a second apply', function (): void {
    $before = sraSnapshot();
    $applier = app(StarterRoleApplier::class);
    $applier->apply(sraPack(), 1);
    $afterFirst = sraSnapshot();

    $second = $applier->apply(sraPack(), 1);

    expect($second['created'])->toBe([])
        ->and($second['skipped'])->toBe([['name' => 'Help center agent', 'reason' => 'role already exists; left unchanged']])
        ->and(sraSnapshot())->toBe($afterFirst);
    sraAssertPreservedSince($before);
});

it('plans without writing and reports what apply would create', function (): void {
    $before = sraSnapshot();
    $pack = sraPack();
    $pack[0]['permissions'][] = 'helpdesk|export';
    $pack[] = ['name' => 'Editor', 'permissions' => ['post|view']];

    $plan = app(StarterRoleApplier::class)->plan($pack, 1);

    expect($plan)->toBe([
        'created' => ['Help center agent'],
        'skipped' => [['name' => 'Editor', 'reason' => 'role already exists; left unchanged']],
        'missing_permissions' => ['Help center agent' => ['helpdesk|export']],
    ])->and(sraSnapshot())->toBe($before);
});

it('reports a role blocked by RolesSection as skipped with its reason', function (): void {
    $before = sraSnapshot();

    $result = app(StarterRoleApplier::class)->apply([['name' => 'Root clone', 'custom' => ['level' => 0]]], 1);

    expect($result['created'])->toBe([])
        ->and($result['skipped'])->toBe([['name' => 'Root clone', 'reason' => 'level 0 is reserved for the Webmaster role']])
        ->and(sraSnapshot())->toBe($before);
});

it('rejects malformed input before any write', function (array $extra): void {
    $before = sraSnapshot();
    $pack = sraPack();
    $pack[] = $extra;

    expect(fn () => app(StarterRoleApplier::class)->apply($pack, 1))->toThrow(StarterPackException::class);
    expect(fn () => app(StarterRoleApplier::class)->plan($pack, 1))->toThrow(StarterPackException::class);
    expect(sraSnapshot())->toBe($before);
})->with([
    'dotted permission key' => [['name' => 'A', 'permissions' => ['helpdesk.agent']]],
    'two pipes' => [['name' => 'A', 'permissions' => ['helpdesk|agent|x']]],
    'empty group' => [['name' => 'A', 'permissions' => ['|agent']]],
    'non-string permission' => [['name' => 'A', 'permissions' => [5]]],
    'permissions not a list' => [['name' => 'A', 'permissions' => ['x' => 'helpdesk|agent']]],
    'unknown field' => [['name' => 'A', 'title' => 'x']],
    'duplicate name' => [['name' => ' help CENTER agent']],
    'empty name' => [['name' => '  ']],
    'label missing en' => [['name' => 'A', 'label' => ['vi' => 'A']]],
    'custom not an object' => [['name' => 'A', 'custom' => 'x']],
    'entry not an object' => [['not', 'an', 'object']],
]);
