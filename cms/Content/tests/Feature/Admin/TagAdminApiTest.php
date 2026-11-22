<?php

declare(strict_types=1);

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The admin tag screen (base-admin pages/category/tag.vue): create with a name, open, save and delete by uuid.
// Before 2026-11-21 only the list and delete worked: create used the unit-of-measure request (code and symbol
// required), show returned an undefined variable, and edit/update called image methods the Tag model never had.

uses(TestCase::class, RefreshDatabase::class)->beforeEach(function (): void {
    $this->withoutMiddleware([ApiLocalization::class, ThrottleRequests::class, HttpsProtocol::class, Localization::class]);
    tagAdminActingAs(['viewAny', 'create', 'update', 'delete']);
});

/** Sign in as an admin whose role holds only the given `tag.*` permissions (2026-11-21: the tag API checks them). */
function tagAdminActingAs(array $abilities): User
{
    $role = Role::query()->create(['name' => 'Tag editor ' . uniqid()]);
    foreach ($abilities as $ability) {
        $permission = Permission::query()->firstOrCreate(['group' => 'tag', 'name' => $ability], ['title' => $ability]);
        $role->permissions()->attach($permission->id);
    }
    $user = User::forceCreate([
        'name' => 'Tag Admin', 'email' => 'tag-admin-' . uniqid('', true) . '@test.local',
        'password' => bcrypt('secret'), 'type' => 0,
    ]);
    $user->roles()->attach($role->id);
    Sanctum::actingAs($user);

    return $user;
}

it('creates a tag from its name alone', function (): void {
    $this->postJson('/api/v2/admin/tag', ['name' => 'Du lịch', 'description' => 'Bài về du lịch'])->assertOk();

    $tag = Tag::query()->ofType(Tag::TYPE_POST)->where('name', 'Du lịch')->first();
    expect($tag)->not->toBeNull()->and($tag->slug)->toBe('du-lich')->and($tag->uuid)->not->toBeEmpty();
    $this->postJson('/api/v2/admin/tag', ['description' => 'no name'])->assertStatus(406); // the project's validation error status (RedirectFailedTrait)
});

it('opens, saves and deletes a tag by uuid', function (): void {
    $tag = Tag::query()->forceCreate(['name' => 'Travel', 'slug' => 'travel']);

    $this->getJson('/api/v2/admin/tag')->assertOk()->assertJsonFragment(['uuid' => $tag->uuid]);
    $this->getJson('/api/v2/admin/tag/' . $tag->uuid)->assertOk()->assertJsonPath('data.name', 'Travel');
    $this->getJson('/api/v2/admin/tag/' . $tag->uuid . '/edit')->assertOk()->assertJsonPath('data.uuid', $tag->uuid);
    $this->putJson('/api/v2/admin/tag/' . $tag->uuid, ['name' => 'Trips', 'description' => 'Short trips'])->assertOk();
    expect($tag->fresh()->only(['name', 'slug', 'description']))->toBe(['name' => 'Trips', 'slug' => 'trips', 'description' => 'Short trips']);

    $this->deleteJson('/api/v2/admin/tag/' . $tag->uuid)->assertOk();
    expect(Tag::query()->find($tag->id))->toBeNull();
});

it('takes no numeric id, no create form route and no user tag', function (): void {
    $tag = Tag::query()->forceCreate(['name' => 'Travel', 'slug' => 'travel']);
    $userTag = Tag::query()->forceCreate(['name' => 'VIP', 'slug' => 'vip', 'type' => Tag::TYPE_USER]);

    $this->getJson('/api/v2/admin/tag/' . $tag->id)->assertNotFound();
    $this->getJson('/api/v2/admin/tag/create')->assertNotFound();
    $this->getJson('/api/v2/admin/tag/' . $userTag->uuid)->assertStatus(400); // the API's usual not-found error
});

it('refuses each tag action without its permission', function (): void {
    $tag = Tag::query()->forceCreate(['name' => 'Travel', 'slug' => 'travel']);
    $noPermission = fn ($response) => $response->assertStatus(400)->assertJsonPath('message', __('mgs.no.permission'));

    tagAdminActingAs([]);
    $noPermission($this->getJson('/api/v2/admin/tag'));
    $noPermission($this->getJson('/api/v2/admin/tag/search?keywords=Tr'));
    $noPermission($this->getJson('/api/v2/admin/tag/' . $tag->uuid . '/edit'));

    tagAdminActingAs(['viewAny']);
    $this->getJson('/api/v2/admin/tag')->assertOk();
    $noPermission($this->postJson('/api/v2/admin/tag', ['name' => 'New']));
    $noPermission($this->putJson('/api/v2/admin/tag/' . $tag->uuid, ['name' => 'Renamed']));
    $noPermission($this->deleteJson('/api/v2/admin/tag/' . $tag->uuid));

    expect(Tag::query()->count())->toBe(1)->and($tag->fresh()->name)->toBe('Travel');
});

it('gives every role the tag rights matching its post rights when the permissions arrive', function (): void {
    $grantAll = static function (Role $role, array $names): void {
        foreach ($names as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => 'post', 'name' => $name], ['title' => $name])->id);
        }
    };
    $editor = Role::query()->create(['name' => 'Editor']);
    $grantAll($editor, ['viewAny', 'create', 'update']);
    $reader = Role::query()->create(['name' => 'Reader']);
    $grantAll($reader, ['viewAny']);
    $other = Role::query()->create(['name' => 'Other']);

    $migration = require module_path('Content', 'database/migrations/2026_11_21_200000_grant_tag_permissions_to_post_roles.php');
    $migration->up();
    $migration->up(); // idempotent

    $tagRights = static fn (Role $role): array => $role->permissions()->where('group', 'tag')->orderBy('name')->pluck('name')->all();
    expect($tagRights($editor))->toBe(['create', 'update', 'viewAny'])
        ->and($tagRights($reader))->toBe(['viewAny'])
        ->and($tagRights($other))->toBe([])
        ->and(Permission::query()->where('group', 'tag')->orderBy('name')->pluck('name')->all())->toBe(['create', 'delete', 'update', 'viewAny']);
});

it('seeds the tag permissions on a fresh install with Content enabled', function (): void {
    Permission::query()->where('group', 'tag')->forceDelete();

    $this->seed(\Cms\Core\Database\Seeders\PermissionTableSeed::class);

    expect(Permission::query()->where('group', 'tag')->orderBy('name')->pluck('name')->all())
        ->toBe(['create', 'delete', 'update', 'viewAny']);
});
