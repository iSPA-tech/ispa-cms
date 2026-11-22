<?php

declare(strict_types=1);

use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
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

    foreach (['post.view', 'post.update', 'post.delete', 'user.view', 'role.update'] as $key) {
        [$group, $name] = explode('.', $key);
        Permission::create(['group' => $group, 'name' => $name, 'title' => $key]);
    }
});

/**
 * Create a role with the given custom data and permission keys.
 *
 * @param list<string> $permissions
 */
function rsRole(string $name, array $custom, array $permissions = [], ?int $userId = null): Role
{
    $role = Role::create(['name' => $name, 'custom' => $custom, 'user_id' => $userId]);
    $role->permissions()->attach(rsPermissionIds($permissions));

    return $role;
}

/**
 * Permission ids for "group.name" keys.
 *
 * @param list<string> $keys
 *
 * @return list<int>
 */
function rsPermissionIds(array $keys): array
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
function rsKeys(Role $role): array
{
    $keys = $role->fresh()->permissions->map(fn (Permission $p): string => $p->key)->all();
    sort($keys);

    return $keys;
}

/**
 * Insert a user holding a role of the given level and return its id.
 */
function rsUser(int $level): int
{
    // User id 1 is always a Webmaster (User::isWebmaster), so importers never take that id.
    if (!DB::table('users')->where('id', 1)->exists()) {
        DB::table('users')->insert(['id' => 1, 'username' => 'root']);
    }

    $id = (int) DB::table('users')->insertGetId(['username' => 'importer' . $level . uniqid()]);
    $role = Role::create(['name' => 'Importer level ' . $level . ' ' . $id, 'custom' => ['level' => $level]]);
    DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $id]);

    return $id;
}

/**
 * A Webmaster importer (level 0, never user id 1 so the level path is exercised).
 */
function rsWebmaster(): int
{
    return rsUser(0);
}

it('exports name, custom without user_id and sorted permission keys, never protected roles', function (): void {
    $creator = (int) DB::table('users')->insertGetId(['username' => 'creator']);
    rsRole('Editor', ['level' => 5, 'badge' => 'info', 'user_id' => 9], ['post.view', 'post.update'], $creator);
    rsRole('Webmaster', ['level' => 0], ['post.view', 'role.update']);
    rsRole('Root clone', ['level' => 0], ['post.delete']);
    rsRole('Null level', ['level' => null], ['post.delete']);

    $export = (new RolesSection())->export(new ExportContext());

    expect($export->rows)->toBe([
        ['name' => 'Editor', 'custom' => ['badge' => 'info', 'level' => 5], 'permissions' => ['post.update', 'post.view']],
    ])
        ->and($export->skipped)->toBe(['protected' => 3])
        ->and(json_encode($export->toArray()))->not->toContain('"id"')
        ->and(json_encode($export->toArray()))->not->toContain('user_id');
});

it('blocks Webmaster and level-0 rows and leaves Webmaster untouched on a forged apply', function (): void {
    $importer = rsWebmaster();
    $webmaster = rsRole('Webmaster', ['level' => 0], ['post.view']);
    $rootClone = rsRole('Root clone', ['level' => 0], ['post.view']);

    $rows = [
        ['name' => 'webmaster', 'custom' => ['level' => 5], 'permissions' => ['post.delete', 'role.update']],
        ['name' => 'Root clone', 'custom' => ['level' => 5], 'permissions' => ['post.delete']],
        ['name' => 'Shadow', 'custom' => ['level' => 0], 'permissions' => ['post.delete']],
        ['name' => 'Bad level', 'custom' => ['level' => 'abc'], 'permissions' => []],
    ];
    $section = new RolesSection();

    $diff = $section->diff($rows, new DiffContext($importer));

    expect(array_column($diff->items, 'action'))->toBe(['blocked', 'blocked', 'blocked', 'blocked']);

    $choices = ['webmaster' => true, 'Webmaster' => true, 'Root clone' => true, 'Shadow' => true, 'Bad level' => true];
    $result = $section->apply($rows, new ApplyContext($choices, $importer));

    expect($result->blocked)->toBe(4)
        ->and($result->updated)->toBe(0)
        ->and(rsKeys($webmaster))->toBe(['post.view'])
        ->and(rsKeys($rootClone))->toBe(['post.view'])
        ->and($webmaster->fresh()->custom)->toBe(['level' => 0])
        ->and(Role::query()->whereIn('name', ['Shadow', 'Bad level'])->count())->toBe(0);
});

it('blocks a name that matches two target roles', function (): void {
    $importer = rsWebmaster();
    rsRole('Editor', ['level' => 5], ['post.view']);
    rsRole('editor', ['level' => 6], ['post.view']);

    $diff = (new RolesSection())->diff(
        [['name' => 'Editor', 'custom' => ['level' => 5], 'permissions' => ['post.update']]],
        new DiffContext($importer),
    );

    expect($diff->item('Editor'))->toMatchArray(['action' => 'blocked'])
        ->and($diff->item('Editor')['reason'])->toContain('ambiguous');
});

it('warns about and skips permission keys missing on the target and creates new roles with existing ones', function (): void {
    $importer = rsWebmaster();
    $permissionCount = Permission::query()->count();
    $rows = [['name' => 'Author', 'custom' => ['level' => 7, 'user_id' => 42], 'permissions' => ['post.view', 'shop.order.refund']]];
    $section = new RolesSection();

    $item = $section->diff($rows, new DiffContext($importer))->item('Author');

    expect($item['action'])->toBe('create')
        ->and($item['after']['permissions'])->toBe(['post.view'])
        ->and($item['missing_permissions'])->toBe(['shop.order.refund'])
        ->and($item['warnings'])->toHaveCount(1);

    $result = $section->apply($rows, new ApplyContext([], $importer));
    $author = Role::query()->where('name', 'Author')->firstOrFail();

    expect($result->updated)->toBe(1)
        ->and($result->items[0]['outcome'])->toBe('created')
        ->and(rsKeys($author))->toBe(['post.view'])
        ->and($author->custom)->toBe(['level' => 7])
        ->and($author->user_id)->toBe($importer)
        ->and(Permission::query()->count())->toBe($permissionCount);
});

it('marks additions to an existing role as an off-by-default choice and never lists removals', function (): void {
    $importer = rsWebmaster();
    rsRole('Editor', ['level' => 5], ['post.view', 'user.view']);

    $diff = (new RolesSection())->diff([
        ['name' => 'Editor', 'custom' => ['level' => 3], 'permissions' => ['post.view', 'post.update']],
    ], new DiffContext($importer));
    $item = $diff->item('Editor');

    expect($item['action'])->toBe('update')
        ->and($item['needs_choice'])->toBeTrue()
        ->and($item['default_choice'])->toBeFalse()
        ->and($item['after']['added_permissions'])->toBe(['post.update'])
        ->and($item['before']['permissions'])->toBe(['post.view', 'user.view'])
        ->and(json_encode($item))->not->toContain('remove');
});

it('reports an existing role with nothing to add as unchanged', function (): void {
    $importer = rsWebmaster();
    rsRole('Editor', ['level' => 5], ['post.view', 'user.view']);

    $diff = (new RolesSection())->diff([
        ['name' => 'Editor', 'custom' => ['level' => 5], 'permissions' => ['post.view']],
    ], new DiffContext($importer));

    expect($diff->item('Editor')['action'])->toBe('unchanged');
});

it('adds nothing to an existing role without an explicit choice and only additions with one', function (): void {
    $importer = rsWebmaster();
    $editor = rsRole('Editor', ['level' => 5], ['post.view', 'user.view']);
    $rows = [['name' => 'Editor', 'custom' => ['level' => 2], 'permissions' => ['post.view', 'post.update']]];
    $section = new RolesSection();

    $none = $section->apply($rows, new ApplyContext([], $importer));

    expect($none->skipped)->toBe(1)
        ->and(rsKeys($editor))->toBe(['post.view', 'user.view']);

    $chosen = $section->apply($rows, new ApplyContext(['Editor' => true], $importer));

    expect($chosen->updated)->toBe(1)
        ->and(rsKeys($editor))->toBe(['post.update', 'post.view', 'user.view'])
        ->and($editor->fresh()->custom)->toBe(['level' => 5]);
});

it('blocks an incoming level more privileged than the importing user', function (): void {
    $importer = rsUser(3);
    $rows = [['name' => 'Manager', 'custom' => ['level' => 1], 'permissions' => ['post.view']]];
    $section = new RolesSection();

    expect($section->diff($rows, new DiffContext($importer))->item('Manager')['action'])->toBe('blocked');

    $result = $section->apply($rows, new ApplyContext(['Manager' => true], $importer));

    expect($result->blocked)->toBe(1)
        ->and(Role::query()->where('name', 'Manager')->exists())->toBeFalse();

    $ok = $section->diff([['name' => 'Clerk', 'custom' => ['level' => 3], 'permissions' => []]], new DiffContext($importer));
    expect($ok->item('Clerk')['action'])->toBe('create');
});

it('fails closed when the importing user is unknown', function (): void {
    $rows = [['name' => 'Clerk', 'custom' => ['level' => 50], 'permissions' => ['post.view']]];
    $section = new RolesSection();

    expect($section->diff($rows, new DiffContext())->item('Clerk')['action'])->toBe('blocked')
        ->and($section->diff($rows, new DiffContext(999))->item('Clerk')['action'])->toBe('blocked');

    $result = $section->apply($rows, new ApplyContext(['Clerk' => true]));

    expect($result->blocked)->toBe(1)
        ->and(Role::query()->where('name', 'Clerk')->exists())->toBeFalse();
});

it('round-trips roles after the target drifted without touching other roles', function (): void {
    $importer = rsWebmaster();
    rsRole('Editor', ['level' => 5, 'badge' => 'info'], ['post.view', 'post.update']);
    rsRole('Author', ['level' => 7], ['post.view']);
    $section = new RolesSection();
    $export = $section->export(new ExportContext());

    // Drift: Editor loses a permission, Author is removed, Guest appears.
    $editor = Role::query()->where('name', 'Editor')->firstOrFail();
    $editor->permissions()->detach(rsPermissionIds(['post.update']));
    Role::query()->where('name', 'Author')->firstOrFail()->forceDelete();
    $guest = rsRole('Guest', ['level' => 90], ['user.view']);

    $result = $section->apply($export->rows, new ApplyContext(['Editor' => true], $importer));
    $after = collect($section->export(new ExportContext())->rows)->keyBy('name');

    expect($result->updated)->toBe(2)
        ->and($after['Editor'])->toBe(collect($export->rows)->keyBy('name')['Editor'])
        ->and($after['Author'])->toBe(collect($export->rows)->keyBy('name')['Author'])
        ->and(rsKeys($guest))->toBe(['user.view'])
        ->and($guest->fresh()->custom)->toBe(['level' => 90]);
});

it('clears permission caches after apply through the seam', function (): void {
    $section = new class extends RolesSection {
        public int $clears = 0;

        protected function clearPermissionCaches(): void
        {
            ++$this->clears;
        }
    };

    $section->afterApply(new ApplyContext());

    expect($section->clears)->toBe(1);
});
