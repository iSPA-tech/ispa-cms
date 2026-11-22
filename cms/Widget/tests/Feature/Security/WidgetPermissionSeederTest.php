<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Security;

use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Policies\WidgetInstancePolicy;
use Cms\Widget\Policies\WidgetLayoutPolicy;
use Cms\Widget\Providers\AuthServiceProvider as WidgetAuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Chunk 2 Task 14: frozen permission inventory seeded idempotently, attached
 * to Webmaster/super-admin, and policy-method mapping proven (plan lines
 * 829-832; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * "permissions"; adr-widget-audit-durability.md context).
 *
 * Scope is inventory/idempotency/role-attachment/policy-mapping ONLY. Most
 * controllers/routes do not exist yet -- Task 24b owns live route-permission
 * conformance and the freshly seeded Webmaster 400/403 proof (non-goal here).
 */
final class WidgetPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetArtifactBlobRepositoryTest::beforeRefreshingDatabase() (Task
     * 13b) for the full explanation. Short version: the Widget module stays
     * disabled by design (no modules_statuses.json entry), so neither its
     * migrations nor its own AuthServiceProvider are auto-discovered during
     * a normal boot; and RefreshDatabaseState::$migrated is a process-wide
     * static flag, so this hook also resets it first, forcing a real
     * migrate:fresh for THIS class regardless of what any earlier-running,
     * non-Widget-scoped RefreshDatabase test left that flag set to (Laravel
     * checks the flag AFTER calling this hook). Additive to the default
     * paths (Illuminate\Database\Migrations\Migrator::path() merges, and
     * this class never overrides migrateFreshUsing()).
     */
    protected function beforeRefreshingDatabase(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Manually boots the real Cms\Widget\Providers\AuthServiceProvider so
     * the policy registration this task adds to that file is exercised
     * faithfully, without flipping the module-wide enabled flag (out of this
     * task's scope; that is a Gate-level decision, not this test's).
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new WidgetAuthServiceProvider($this->app))->boot();
    }

    /**
     * The frozen, non-reserved permission inventory, derived STRAIGHT from
     * the Task-12 machine fixture (the authoritative contract) instead of a
     * third hand-copy: the fixture and the seeder are now the only two
     * transcriptions of the key set, and the bridge test below proves them
     * set-equal in both directions -- so neither can drift without a red
     * test. `widget_template.import` is excluded by its own `reserved` flag
     * (Chunk 4); `widget.trust`/`widget_theme.trust` sit outside the
     * fixture's module inventory entirely (core_owned_references) and are
     * never touched by this seeder.
     *
     * @return array<string, list<string>> group => action names
     */
    private function fixturePermissionGroups(): array
    {
        $path = base_path('cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json');
        $this->assertFileExists($path, 'Task-12 frozen fixture must exist.');

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $groups = [];
        foreach ($fixture['permissions'] as $permission) {
            if (($permission['reserved'] ?? false) === true) {
                continue;
            }

            [$group, $name] = explode('.', $permission['key'], 2);
            $groups[$group][] = $name;
        }

        $this->assertNotEmpty($groups, 'Fixture must declare at least one non-reserved permission.');

        return $groups;
    }

    /**
     * @return list<string> Flat "group.name" keys from the fixture (non-reserved).
     */
    private function fixturePermissionKeys(): array
    {
        $keys = [];
        foreach ($this->fixturePermissionGroups() as $group => $actions) {
            foreach ($actions as $action) {
                $keys[] = $group . '.' . $action;
            }
        }

        return $keys;
    }

    /**
     * "group.name" keys already in the permissions table, e.g. the `tag.*` rows a Content migration creates under
     * RefreshDatabase (2026-11-21). Taken before the seeder runs, so the inventory tests judge only what it adds.
     *
     * @return list<string>
     */
    private function permissionKeys(): array
    {
        return Permission::query()
            ->get()
            ->map(static fn (Permission $permission): string => $permission->group . '.' . $permission->name)
            ->all();
    }

    // -----------------------------------------------------------------
    // Inventory + idempotency + role attachment
    // -----------------------------------------------------------------

    public function test_seeder_creates_the_exact_frozen_permission_inventory(): void
    {
        $before = $this->permissionKeys();
        $this->seed(WidgetPermissionTableSeeder::class);

        foreach ($this->fixturePermissionGroups() as $group => $actions) {
            foreach ($actions as $action) {
                $this->assertTrue(
                    Permission::query()->where('group', $group)->where('name', $action)->exists(),
                    "Expected permission {$group}.{$action} to be seeded."
                );
            }
        }

        // Deliberately UNSCOPED count of what the seeder ADDED (rows present
        // before it ran are excluded): a stray permission in ANY group --
        // including a brand-new group the whereIn-by-known-groups form would
        // have silently ignored -- must fail this assertion.
        $this->assertSame(
            count($this->fixturePermissionKeys()),
            Permission::query()->count() - count($before),
            'Seeder must create exactly the frozen set, no extras in any group.'
        );
    }

    public function test_seeded_permission_set_is_set_equal_to_the_fixture_inventory_in_both_directions(): void
    {
        $before = $this->permissionKeys();
        $this->seed(WidgetPermissionTableSeeder::class);

        $expected = $this->fixturePermissionKeys();
        $actual = array_values(array_diff($this->permissionKeys(), $before));

        sort($expected);
        sort($actual);

        // Both directions: a fixture key the seeder forgot AND a seeder key
        // absent from the fixture each break set equality. This is the
        // bridge that keeps the seeder's hand transcription honest against
        // the Task-12 authoritative contract.
        $this->assertSame(
            $expected,
            $actual,
            'Seeded permissions must be set-equal to the fixture\'s non-reserved inventory (no omissions, no extras).'
        );
    }

    public function test_seeder_never_seeds_the_reserved_import_permission_or_core_owned_trust_keys(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);

        $this->assertFalse(
            Permission::query()->where('group', 'widget_template')->where('name', 'import')->exists(),
            'widget_template.import is reserved for Chunk 4 and must not be seeded yet.'
        );
        $this->assertFalse(
            Permission::query()->where('group', 'widget')->where('name', 'trust')->exists(),
            'widget.trust stays Core-owned (Task 9b); this seeder must never create it.'
        );
        $this->assertFalse(
            Permission::query()->where('group', 'widget_theme')->where('name', 'trust')->exists(),
            'widget_theme.trust stays Core-owned (Task 9b); this seeder must never create it.'
        );
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $before = $this->permissionKeys();
        $this->seed(WidgetPermissionTableSeeder::class);
        $this->seed(WidgetPermissionTableSeeder::class);

        $this->assertSame(
            count($this->fixturePermissionKeys()),
            Permission::query()->count() - count($before),
            'Re-running the seeder must not create duplicate permission rows (unscoped total).'
        );
    }

    public function test_seeder_attaches_every_enabled_action_to_webmaster_and_super_admin_without_detaching_unrelated_permissions(): void
    {
        $webmaster = Role::query()->create(['name' => 'Webmaster']);
        $superAdmin = Role::query()->create(['name' => 'super-admin']);
        $unrelated = Permission::query()->create(['group' => 'unrelated', 'name' => 'view', 'title' => 'Unrelated']);
        $webmaster->permissions()->attach($unrelated->id);

        $this->seed(WidgetPermissionTableSeeder::class);

        foreach ($this->fixturePermissionGroups() as $group => $actions) {
            foreach ($actions as $action) {
                $permission = Permission::query()->where('group', $group)->where('name', $action)->firstOrFail();

                $this->assertTrue(
                    $webmaster->permissions()->whereKey($permission->id)->exists(),
                    "Webmaster must have {$group}.{$action}."
                );
                $this->assertTrue(
                    $superAdmin->permissions()->whereKey($permission->id)->exists(),
                    "super-admin must have {$group}.{$action}."
                );
            }
        }

        $this->assertTrue(
            $webmaster->permissions()->whereKey($unrelated->id)->exists(),
            'syncWithoutDetaching must never detach a pre-existing unrelated permission.'
        );
    }

    // -----------------------------------------------------------------
    // Policy-method mapping
    // -----------------------------------------------------------------

    public function test_widget_instance_and_layout_policies_are_registered_for_their_models(): void
    {
        $instancePolicy = Gate::getPolicyFor(WidgetInstance::class);
        $layoutPolicy = Gate::getPolicyFor(WidgetLayout::class);

        $this->assertInstanceOf(WidgetInstancePolicy::class, $instancePolicy);
        $this->assertInstanceOf(WidgetLayoutPolicy::class, $layoutPolicy);
    }

    public function test_widget_instance_policy_maps_every_frozen_ability_to_its_permission_key(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $user = $this->userWithPermissions([
            'widget_instance.viewAny', 'widget_instance.view', 'widget_instance.create',
            'widget_instance.update', 'widget_instance.delete',
        ]);
        $instance = WidgetInstance::query()->create(['project_name' => 'Default', 'name' => 'Test Instance']);

        $this->assertTrue($user->can('viewAny', WidgetInstance::class));
        $this->assertTrue($user->can('view', $instance));
        $this->assertTrue($user->can('create', WidgetInstance::class));
        $this->assertTrue($user->can('update', $instance));
        $this->assertTrue($user->can('delete', $instance));
    }

    public function test_widget_instance_policy_denies_without_the_matching_permission(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $user = $this->userWithPermissions([]);
        $instance = WidgetInstance::query()->create(['project_name' => 'Default', 'name' => 'Test Instance']);

        $this->assertFalse($user->can('viewAny', WidgetInstance::class));
        $this->assertFalse($user->can('view', $instance));
        $this->assertFalse($user->can('create', WidgetInstance::class));
        $this->assertFalse($user->can('update', $instance));
        $this->assertFalse($user->can('delete', $instance));
    }

    // -----------------------------------------------------------------
    // Draft editor cannot publish/rollback
    // -----------------------------------------------------------------

    public function test_draft_editor_can_edit_and_preview_but_cannot_publish_or_rollback(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $draftEditor = $this->userWithPermissions([
            'widget_layout.viewAny', 'widget_layout.view', 'widget_layout.update', 'widget_layout.preview',
        ]);
        $layout = WidgetLayout::query()->create(['project_name' => 'Default']);

        $this->assertTrue($draftEditor->can('view', $layout));
        $this->assertTrue($draftEditor->can('update', $layout));
        $this->assertTrue($draftEditor->can('preview', $layout));

        $this->assertFalse(
            $draftEditor->can('publish', $layout),
            'A draft editor without widget_layout.publish must not be able to publish.'
        );
        $this->assertFalse(
            $draftEditor->can('rollback', $layout),
            'A draft editor without widget_layout.rollback must not be able to roll back.'
        );
    }

    // -----------------------------------------------------------------
    // Publisher without widget_template.approve cannot approve/revoke
    // -----------------------------------------------------------------

    public function test_publisher_without_template_approve_cannot_approve_or_revoke_a_manifest_or_promote_a_theme(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $publisher = $this->userWithPermissions([
            'widget_layout.viewAny', 'widget_layout.view', 'widget_layout.update',
            'widget_layout.preview', 'widget_layout.publish', 'widget_layout.rollback',
        ]);
        $layout = WidgetLayout::query()->create(['project_name' => 'Default']);

        $this->assertTrue($publisher->can('publish', $layout));
        $this->assertTrue($publisher->can('rollback', $layout));

        // Manifest approval/revocation and Theme promotion share the same
        // widget_template.approve permission key (no separate Manifest
        // policy class exists yet -- the actual approval service is Task
        // 15b's scope). This proves the authorization gate only, via
        // Gate/can with the seeded key, per this task's design notes.
        $this->assertFalse(
            $publisher->can('widget_template.approve'),
            'A publisher without widget_template.approve must not be able to approve/revoke a Manifest or promote a Theme.'
        );
    }

    // -----------------------------------------------------------------
    // Approver without widget_template.emergencyDisable cannot block
    // -----------------------------------------------------------------

    public function test_approver_without_emergency_disable_cannot_perform_an_emergency_runtime_block(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $approver = $this->userWithPermissions(['widget_template.approve']);

        $this->assertTrue($approver->can('widget_template.approve'));
        $this->assertFalse(
            $approver->can('widget_template.emergencyDisable'),
            'An approver without widget_template.emergencyDisable must not perform an emergency runtime block.'
        );
    }

    public function test_holder_of_emergency_disable_passes_the_emergency_runtime_block_gate(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $onCall = $this->userWithPermissions(['widget_template.emergencyDisable']);

        $this->assertTrue($onCall->can('widget_template.emergencyDisable'));
        $this->assertFalse(
            $onCall->can('widget_template.approve'),
            'Holding emergencyDisable must not implicitly grant approve.'
        );
    }

    // -----------------------------------------------------------------
    // Ownership fallback stays inert (see WidgetInstancePolicy docblock)
    // -----------------------------------------------------------------

    public function test_instance_creator_with_zero_permissions_is_still_denied_ownership_fallback_is_inert(): void
    {
        $this->seed(WidgetPermissionTableSeeder::class);
        $creator = $this->userWithPermissions([]);
        $instance = WidgetInstance::query()->create([
            'project_name' => 'Default',
            'name' => 'Creator Owned Instance',
            'created_by' => $creator->id,
        ]);

        // widget_instances stores its creator in created_by, NOT user_id, so
        // AbstractModelPolicy::owns() (ownershipKey() = 'user_id') can never
        // match: being the creator is not a grant path. If a user_id column
        // is ever added to widget_instances, this test starts failing --
        // that is the intended alarm (the inherited fallback would silently
        // fail OPEN).
        $this->assertFalse(
            $creator->can('view', $instance),
            'Creator without widget_instance.view must be denied: ownership fallback must stay inert.'
        );
        $this->assertFalse(
            $creator->can('update', $instance),
            'Creator without widget_instance.update must be denied: ownership fallback must stay inert.'
        );
        $this->assertFalse(
            $creator->can('delete', $instance),
            'Creator without widget_instance.delete must be denied: ownership fallback must stay inert.'
        );
    }

    // -----------------------------------------------------------------
    // i18n: live sql sidecar must never drift from the frozen exported one
    // -----------------------------------------------------------------

    public function test_live_sql_i18n_sidecar_is_identical_to_the_frozen_exported_sidecar(): void
    {
        $exported = require base_path('cms/Widget/database/seeders/exported/translates-admin-widget-site-layout.php');
        $live = require base_path('cms/Widget/database/seeders/sql/translate-admin-widget-site-layout.php');

        $this->assertIsArray($exported);
        $this->assertIsArray($live);

        // Strict identity pin (order, keys, values, types): the sql/ copy is
        // what cms:translation-sync actually imports, the exported/ file is
        // the Task-12 contract-validated source -- any edit to one without
        // the other is drift and must fail here.
        $this->assertSame(
            serialize($exported),
            serialize($live),
            'cms/Widget/database/seeders/sql/translate-admin-widget-site-layout.php must stay row-for-row identical '
            . 'to the frozen exported/translates-admin-widget-site-layout.php (edit both together, never one).'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a user with exactly the given permission keys attached through a
     * dedicated real, non-zero-level Role (so User::isWebmaster()'s
     * roleLevel()===0 path is never accidentally exercised here).
     *
     * @param list<string> $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Test Role ' . uniqid('', true),
            'custom' => ['level' => 50],
        ]);

        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
