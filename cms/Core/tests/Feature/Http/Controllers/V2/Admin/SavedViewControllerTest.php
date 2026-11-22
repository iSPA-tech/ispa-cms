<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\SavedView;
use Cms\Core\SavedViews\SavedViewRegistry;
use Cms\Core\Tests\Fixtures\Resources\OrganizationsFixtureResource;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for the saved views API, "must not see" cases first.
 *
 * The fixture list `test/list` needs `fixture.view` to see shared views and `fixture.admin` to share.
 */
class SavedViewControllerTest extends TestCase
{
    /** Saved views endpoint. */
    private const URL = 'https://localhost/api/v2/admin/saved-views';

    /** Create the tables, occupy user id 1 (a Webmaster by id) and register the fixture list. */
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->nullable();
            $table->integer('type')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('custom')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('group')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        (require base_path('cms/Core/database/migrations/2026_11_05_120000_create_core_saved_views_table.php'))->up();

        // User id 1 counts as Webmaster (User::isWebmaster), so keep it out of the role-based cases.
        $this->user('Site owner', []);

        app(SavedViewRegistry::class)->register('test/list', 'fixture.view', 'fixture.admin');
        app(SavedViewRegistry::class)->register('test/private-only', 'fixture.view');
    }

    /**
     * Create an admin user holding the given permission keys through a role.
     *
     * @param  array<string>  $permissions
     * @param  array<string, mixed>|null  $roleCustom
     */
    private function user(string $name, array $permissions, ?array $roleCustom = null): User
    {
        $user = User::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::slug($name) . '-' . Str::lower(Str::random(5)) . '@example.test',
            'password' => 'secret',
            'type' => UserType::ADMIN,
        ]);

        if ($permissions !== [] || $roleCustom !== null) {
            $role = Role::query()->create(['name' => 'role-' . $name, 'custom' => $roleCustom]);
            foreach ($permissions as $key) {
                [$group, $ability] = explode('.', $key, 2);
                $permission = Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability]);
                $role->permissions()->attach($permission->id);
            }
            $user->roles()->attach($role->id);
        }

        return $user->fresh();
    }

    /** Sign the user in through Sanctum. */
    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** Store a view directly. */
    private function savedView(User $owner, bool $shared = false, string $list = 'test/list', string $name = 'A view'): SavedView
    {
        return SavedView::query()->create([
            'user_id' => $owner->id,
            'list_key' => $list,
            'name' => $name,
            'state' => ['q' => 'acme'],
            'is_shared' => $shared,
        ]);
    }

    /**
     * Uuids returned by the index of a list.
     *
     * @return array<int, string>
     */
    private function listed(string $list = 'test/list'): array
    {
        return array_column($this->getJson(self::URL . '?list=' . urlencode($list))->assertOk()->json('data'), 'uuid');
    }

    // --- Must-not-see cases ---

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $view = $this->savedView($this->user('Owner', []));

        $this->getJson(self::URL . '?list=test/list')->assertStatus(401);
        $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'x', 'state' => []])->assertStatus(401);
        $this->putJson(self::URL . '/' . $view->uuid, ['name' => 'x'])->assertStatus(401);
        $this->deleteJson(self::URL . '/' . $view->uuid)->assertStatus(401);
    }

    public function test_another_users_private_view_is_never_listed_changed_or_deleted(): void
    {
        $a = $this->user('A', ['fixture.view', 'fixture.admin']);
        $private = $this->savedView($a);
        $this->as($this->user('B', ['fixture.view', 'fixture.admin']));

        $this->assertNotContains($private->uuid, $this->listed());
        $this->putJson(self::URL . '/' . $private->uuid, ['name' => 'Taken'])->assertStatus(404);
        $this->patchJson(self::URL . '/' . $private->uuid, ['is_shared' => true])->assertStatus(404);
        $this->deleteJson(self::URL . '/' . $private->uuid)->assertStatus(404);

        $this->assertSame('A view', $private->fresh()->name);
        $this->assertFalse($private->fresh()->is_shared);
    }

    public function test_shared_view_is_hidden_from_a_user_without_the_view_ability(): void
    {
        $shared = $this->savedView($this->user('A', ['fixture.view', 'fixture.admin']), true);

        $this->as($this->user('No view', []));
        $this->assertSame([], $this->listed());

        $this->as($this->user('Viewer', ['fixture.view']));
        $this->assertSame([$shared->uuid], $this->listed());
    }

    public function test_another_users_shared_view_cannot_be_updated_even_by_a_share_holder(): void
    {
        $shared = $this->savedView($this->user('A', ['fixture.view', 'fixture.admin']), true);
        $this->as($this->user('Admin', ['fixture.view', 'fixture.admin']));

        $this->putJson(self::URL . '/' . $shared->uuid, ['name' => 'Taken'])->assertStatus(404);
        $this->assertSame('A view', $shared->fresh()->name);
    }

    public function test_user_without_share_ability_cannot_create_or_flip_a_shared_view(): void
    {
        $user = $this->as($this->user('Agent', ['fixture.view']));

        $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'Mine', 'state' => ['q' => 'x'], 'is_shared' => true])
            ->assertStatus(403);
        $this->assertSame(0, SavedView::query()->count());

        $own = $this->savedView($user);
        $this->putJson(self::URL . '/' . $own->uuid, ['is_shared' => true])->assertStatus(403);
        $this->assertFalse($own->fresh()->is_shared);
    }

    public function test_list_without_share_ability_cannot_be_shared_by_an_admin(): void
    {
        $this->as($this->user('Admin', ['fixture.view', 'fixture.admin']));

        $this->postJson(self::URL, ['list' => 'test/private-only', 'name' => 'Mine', 'state' => [], 'is_shared' => true])
            ->assertStatus(403);
    }

    public function test_unknown_list_key_returns_404(): void
    {
        $this->as($this->user('Admin', ['fixture.view', 'fixture.admin']));

        $this->getJson(self::URL . '?list=nope/list')->assertStatus(404);
        $this->postJson(self::URL, ['list' => 'nope/list', 'name' => 'x', 'state' => []])->assertStatus(404);
    }

    public function test_twenty_first_view_of_a_list_is_rejected(): void
    {
        $user = $this->as($this->user('Agent', ['fixture.view']));
        foreach (range(1, 20) as $i) {
            $this->savedView($user, false, 'test/list', 'View ' . $i);
        }
        // Views of another list and other users do not count.
        $this->savedView($user, false, 'test/private-only');
        $this->savedView($this->user('Other', ['fixture.view']));

        $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'One more', 'state' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('list');
        $this->postJson(self::URL, ['list' => 'test/private-only', 'name' => 'Fine', 'state' => []])->assertStatus(201);
    }

    public function test_invalid_state_is_rejected(): void
    {
        $this->as($this->user('Agent', ['fixture.view']));

        foreach ([
            ['q' => str_repeat('a', 201)],
            ['f' => ['nested' => 'object']],
            ['f' => 1.5],
            ['f' => true],
            ['__proto__' => 'x'],
            ['a.b' => 'x'],
            'not-an-object',
            ['a', 'b'],
        ] as $state) {
            $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'Bad', 'state' => $state])
                ->assertStatus(422)
                ->assertJsonValidationErrors('state');
        }
        $this->assertSame(0, SavedView::query()->count());
    }

    public function test_share_holder_deletes_another_users_shared_view_but_not_their_private_one(): void
    {
        $a = $this->user('A', ['fixture.view']);
        $shared = $this->savedView($a, true);
        $private = $this->savedView($a);

        $this->as($this->user('Agent', ['fixture.view']));
        $this->deleteJson(self::URL . '/' . $shared->uuid)->assertStatus(403);

        $this->as($this->user('Admin', ['fixture.view', 'fixture.admin']));
        $this->deleteJson(self::URL . '/' . $private->uuid)->assertStatus(404);
        $this->deleteJson(self::URL . '/' . $shared->uuid)->assertNoContent();

        $this->assertNull($shared->fresh());
        $this->assertNotNull($private->fresh());
    }

    public function test_items_never_expose_numeric_ids(): void
    {
        $user = $this->as($this->user('Agent', ['fixture.view']));
        $this->savedView($user);

        $item = $this->getJson(self::URL . '?list=test/list')->assertOk()->json('data.0');

        $this->assertSame(['uuid', 'name', 'state', 'is_shared', 'is_mine', 'can_manage', 'can_delete'], array_keys($item));
    }

    // --- Allowed flows ---

    public function test_owner_creates_lists_updates_and_deletes_a_private_view(): void
    {
        $this->as($this->user('Agent', ['fixture.view']));

        $uuid = $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'Open', 'state' => ['status' => ['open'], 'page' => 1]])
            ->assertStatus(201)
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.is_shared', false)
            ->assertJsonPath('data.can_manage', true)
            ->json('data.uuid');

        $this->putJson(self::URL . '/' . $uuid, ['name' => 'Renamed', 'state' => ['q' => 'x'], 'position' => 3])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.state', ['q' => 'x']);
        $this->assertSame(3, SavedView::query()->where('uuid', $uuid)->value('position'));

        $this->assertSame([$uuid], $this->listed());
        $this->deleteJson(self::URL . '/' . $uuid)->assertNoContent();
        $this->assertSame([], $this->listed());
    }

    public function test_share_holder_shares_and_others_see_it_read_only(): void
    {
        $this->as($this->user('Admin', ['fixture.view', 'fixture.admin']));
        $uuid = $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'Team', 'state' => [], 'is_shared' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.is_shared', true)
            ->json('data.uuid');

        $this->as($this->user('Viewer', ['fixture.view']));
        $item = $this->getJson(self::URL . '?list=test/list')->assertOk()->json('data.0');
        $this->assertSame($uuid, $item['uuid']);
        $this->assertFalse($item['is_mine']);
        $this->assertFalse($item['can_manage']);
        $this->assertFalse($item['can_delete']);
    }

    public function test_owner_who_lost_share_ability_cannot_edit_their_shared_view_but_can_delete_it(): void
    {
        $user = $this->as($this->user('Former admin', ['fixture.view']));
        $shared = $this->savedView($user, true);

        $this->putJson(self::URL . '/' . $shared->uuid, ['state' => ['q' => 'changed']])->assertStatus(403);
        $this->putJson(self::URL . '/' . $shared->uuid, ['is_shared' => false])->assertStatus(403);
        $this->assertSame(['q' => 'acme'], $shared->fresh()->state);

        $this->deleteJson(self::URL . '/' . $shared->uuid)->assertNoContent();
    }

    public function test_webmaster_may_share_without_the_list_permission(): void
    {
        $this->as($this->user('Webmaster', [], ['level' => 0]));

        $this->postJson(self::URL, ['list' => 'test/list', 'name' => 'Team', 'state' => [], 'is_shared' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.is_shared', true);
    }

    // --- Registry ---

    public function test_registry_resolves_explicit_keys_resources_and_unknown_keys(): void
    {
        $registry = app(SavedViewRegistry::class);
        app(ResourceRegistry::class)->register('fixture/organizations', OrganizationsFixtureResource::class);

        $this->assertSame(['view' => 'fixture.view', 'share' => 'fixture.admin'], $registry->resolve('test/list'));
        $this->assertSame(['view' => 'fixture.view', 'share' => null], $registry->resolve('test/private-only'));
        // The fixture resource's form declares no ability, so nobody but a Webmaster may share on it.
        $this->assertSame(['view' => 'helpdesk.organizations.view', 'share' => null], $registry->resolve('fixture/organizations'));
        $this->assertNull($registry->resolve('unknown/list'));
        $this->assertSame($registry, app(SavedViewRegistry::class));

        $registry->register('fixture/organizations', 'explicit.view', 'explicit.share');
        $this->assertSame(['view' => 'explicit.view', 'share' => 'explicit.share'], $registry->resolve('fixture/organizations'));
    }
}
