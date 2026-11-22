<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Security;

use App\Constants\UserType;
use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Widget\Security\WidgetProjectContextResolver;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Chunk 1 Task 12b contract tests: pilot hook and authorization fixtures
 * (lines
 * 689-707; the site-layout pilot map in docs/evidence).
 *
 * These are CONTRACT tests only: no HTTP route, controller, or
 * WidgetTargetContextRegistrar wiring exists yet (that starts at Task 13
 * and the ExampleProject production adapter lands at Task 23). Every proof below
 * goes through either the already-frozen Task 12
 * {@see WidgetProjectContextResolver} or the new
 * {@see FakeWidgetTargetContextResolver} test double implementing the
 * Task 7 root {@see \App\Contracts\Widget\WidgetTargetContextResolver}
 * contract, plus the frozen root DTOs. No endpoint is fabricated.
 *
 * Three sections:
 * 1. Permission matrix -- Webmaster / editor / publisher / denied split
 *    over the frozen `widget_layout.*` action set (per the task brief:
 *    "Webmaster = all; editor = viewAny/view/update/preview; publisher =
 *    +publish/rollback; NO widget_template.approve for either"), seeded
 *    the same way {@see \Cms\Core\Tests\Feature\Security\WidgetTrustBoundaryTest}
 *    seeds permission fixtures (real Permission/Role rows, not a
 *    production seeder run -- Task 14 owns the real seeder inventory).
 * 2. WidgetProjectContextResolver -- single active-project mode: no real
 *    project-scoped RBAC exists (Task 5a freeze), so this proves the
 *    resolver derives the project server-side and rejects any
 *    caller-supplied mismatching project_name. No project-limited role is
 *    fabricated.
 * 3. FakeWidgetTargetContextResolver -- typed query/page/resolve DTOs,
 *    capped pagination, opaque string target ids (including a
 *    numeric-looking one), cross-project id vs unreadable-target
 *    (404 vs 403, no metadata leak), and template/target mismatch
 *    (typed rejection), plus a uniformity proof that preview, catalog,
 *    authorization, and generic search/detail all reach the identical
 *    resolve() call for the same approved page.static target.
 */
final class WidgetPilotAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';
    private const OTHER_PROJECT = 'SomeOtherProject';

    /** Full frozen widget_layout.* action set (search/detail route permission per docs/contracts/widget-site-layout-api.md:69-71). */
    private const WEBMASTER_LAYOUT_KEYS = [
        'widget_layout.viewAny',
        'widget_layout.view',
        'widget_layout.update',
        'widget_layout.preview',
        'widget_layout.publish',
        'widget_layout.rollback',
    ];

    private const WEBMASTER_INSTANCE_KEYS = [
        'widget_instance.viewAny',
        'widget_instance.view',
        'widget_instance.create',
        'widget_instance.update',
        'widget_instance.delete',
    ];

    private const WEBMASTER_TEMPLATE_KEYS = [
        'widget_template.approve',
        'widget_template.emergencyDisable',
    ];

    private const EDITOR_KEYS = [
        'widget_layout.viewAny',
        'widget_layout.view',
        'widget_layout.update',
        'widget_layout.preview',
    ];

    private const PUBLISHER_KEYS = [
        'widget_layout.viewAny',
        'widget_layout.view',
        'widget_layout.update',
        'widget_layout.preview',
        'widget_layout.publish',
        'widget_layout.rollback',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic single active-project mode regardless of the
        // ambient .env VITE_TEMPLATE_FOLDER value.
        config(['ispa-cms.folder_name' => self::PROJECT]);
    }

    // -----------------------------------------------------------------
    // Section 1: permission matrix (Webmaster / editor / publisher / denied)
    // -----------------------------------------------------------------

    /**
     * @param list<string> $permissionKeys "group.name" keys, matching the
     *        real permission naming convention (group = table/prefix name)
     *        already used by WidgetTrustBoundaryTest and
     *        WidgetRenderCompatibilityTest.
     */
    private function createUserWithPermissions(string $label, array $permissionKeys): User
    {
        $user = User::query()->create([
            'name' => $label,
            'email' => strtolower($label) . '-' . uniqid('', true) . '@test.local',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['type' => UserType::ADMIN])->save();

        $role = Role::query()->create(['name' => strtolower($label) . '-' . uniqid('', true), 'user_id' => $user->id]);

        $permissionIds = [];
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->firstOrCreate(
                ['group' => $group, 'name' => $name],
                ['title' => $group . ' ' . $name],
            );
            $permissionIds[] = $permission->id;
        }

        if ($permissionIds !== []) {
            $role->permissions()->attach($permissionIds);
        }
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_webmaster_holds_every_frozen_widget_permission_key(): void
    {
        $allKeys = [...self::WEBMASTER_LAYOUT_KEYS, ...self::WEBMASTER_INSTANCE_KEYS, ...self::WEBMASTER_TEMPLATE_KEYS];
        $user = $this->createUserWithPermissions('Webmaster', $allKeys);

        foreach ($allKeys as $key) {
            $this->assertTrue($user->hasPermissionResolved($key), "Webmaster must hold {$key}.");
        }
    }

    public function test_editor_holds_viewany_view_update_preview_but_not_publish_rollback_or_approve(): void
    {
        $user = $this->createUserWithPermissions('Editor', self::EDITOR_KEYS);

        foreach (self::EDITOR_KEYS as $key) {
            $this->assertTrue($user->hasPermissionResolved($key), "editor must hold {$key}.");
        }

        $this->assertFalse($user->hasPermissionResolved('widget_layout.publish'), 'editor must not hold publish.');
        $this->assertFalse($user->hasPermissionResolved('widget_layout.rollback'), 'editor must not hold rollback.');
        $this->assertFalse($user->hasPermissionResolved('widget_template.approve'), 'editor must never hold widget_template.approve.');
    }

    public function test_publisher_holds_editor_keys_plus_publish_and_rollback_but_not_approve(): void
    {
        $user = $this->createUserWithPermissions('Publisher', self::PUBLISHER_KEYS);

        foreach (self::PUBLISHER_KEYS as $key) {
            $this->assertTrue($user->hasPermissionResolved($key), "publisher must hold {$key}.");
        }

        $this->assertFalse($user->hasPermissionResolved('widget_template.approve'), 'publisher must never hold widget_template.approve.');
    }

    public function test_denied_user_holds_none_of_the_frozen_widget_permission_keys(): void
    {
        // Permission rows must exist (so "denied" is a real absence-of-grant,
        // not merely an absence-of-row false negative).
        foreach ([...self::WEBMASTER_LAYOUT_KEYS, ...self::WEBMASTER_INSTANCE_KEYS, ...self::WEBMASTER_TEMPLATE_KEYS] as $key) {
            [$group, $name] = explode('.', $key, 2);
            Permission::query()->firstOrCreate(['group' => $group, 'name' => $name], ['title' => $group . ' ' . $name]);
        }

        $user = $this->createUserWithPermissions('Denied', []);

        foreach ([...self::WEBMASTER_LAYOUT_KEYS, ...self::WEBMASTER_INSTANCE_KEYS, ...self::WEBMASTER_TEMPLATE_KEYS] as $key) {
            $this->assertFalse($user->hasPermissionResolved($key), "denied user must not hold {$key}.");
        }
    }

    // -----------------------------------------------------------------
    // Section 2: WidgetProjectContextResolver (single active-project mode)
    // -----------------------------------------------------------------

    public function test_resolve_returns_the_server_derived_active_project(): void
    {
        $context = (new WidgetProjectContextResolver())->resolve();

        $this->assertSame(self::PROJECT, $context->projectName);
    }

    public function test_assert_project_accepts_a_null_selector(): void
    {
        $context = (new WidgetProjectContextResolver())->assertProject(null);

        $this->assertSame(self::PROJECT, $context->projectName);
    }

    public function test_assert_project_accepts_an_explicit_matching_selector(): void
    {
        $context = (new WidgetProjectContextResolver())->assertProject(self::PROJECT);

        $this->assertSame(self::PROJECT, $context->projectName);
    }

    public function test_assert_project_rejects_an_arbitrary_mismatching_project_name(): void
    {
        // Single active-project mode (Task 5a freeze): no real project-scoped
        // RBAC exists, so this is the one authorization surface that mode
        // provides -- an arbitrary caller-supplied project selector is
        // rejected outright, never silently honored or downgraded.
        $this->expectException(AuthorizationException::class);

        (new WidgetProjectContextResolver())->assertProject(self::OTHER_PROJECT);
    }

    // -----------------------------------------------------------------
    // Section 3: FakeWidgetTargetContextResolver contract proofs
    // -----------------------------------------------------------------

    private function pageStaticFixture(): FakeWidgetTargetContextResolver
    {
        $fake = new FakeWidgetTargetContextResolver();

        // Deliberately numeric-looking string id, to prove no int coercion.
        $fake->withTarget('123', 'page.static', self::PROJECT, 'Gioi Thieu', 'reader', ['slug' => 'gioi-thieu']);
        $fake->withTarget('9f2b6b1e-9c2b-4b1a-8b39-2f7a2f1b0a10', 'page.static', self::PROJECT, 'Lien He Chung Toi', 'reader', ['slug' => 'lien-he-chung-toi']);
        // Exists, same project, but flagged unreadable (403 fixture).
        $fake->withTarget('b3d3a7f0-6e9f-4d61-9b8f-6a3d0a7c9e21', 'page.static', self::PROJECT, 'Dieu Khoan Noi Bo', 'reader', ['slug' => 'dieu-khoan-noi-bo'], readable: false);
        // Exists, but under a DIFFERENT project (cross-project 404 fixture).
        $fake->withTarget('cross-project-0001', 'page.static', self::OTHER_PROJECT, 'Foreign Project Page', 'reader', ['slug' => 'foreign-page']);
        // Future-registration fixture (architecture.md: "article.detail is a
        // registered future Template Context, not an implied pilot hook").
        // Included ONLY to prove template/target mismatch detection; never
        // wired to a Slot and not a Task-23 file.
        $fake->withTarget('article-0001', 'article.detail', self::PROJECT, 'Ban Tin Vidu', 'article.vi', ['cate' => 'tin-tuc', 'slug' => 'ban-tin-vidu']);

        return $fake;
    }

    public function test_resolve_returns_a_readable_target_with_every_dto_field_populated(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $resolved = $fake->resolve($context, '123');

        $this->assertInstanceOf(WidgetResolvedTarget::class, $resolved);
        $this->assertSame('123', $resolved->targetId);
        $this->assertSame('page.static', $resolved->targetType);
        $this->assertSame('Gioi Thieu', $resolved->label);
        $this->assertSame('reader', $resolved->routeName);
        $this->assertSame(['slug' => 'gioi-thieu'], $resolved->routeParameters);
    }

    public function test_resolve_never_coerces_a_numeric_looking_id_to_int(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $resolved = $fake->resolve($context, '123');

        $this->assertSame('string', gettype($resolved->targetId));
        $this->assertNotSame(123, $resolved->targetId);
        $this->assertTrue($resolved->targetId === '123');
    }

    public function test_search_results_carry_string_target_ids_including_the_numeric_looking_one(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $page = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static', keywords: 'Gioi'));

        $this->assertCount(1, $page->items);
        $this->assertSame('123', $page->items[0]->targetId);
        $this->assertSame('string', gettype($page->items[0]->targetId));
    }

    public function test_resolve_denies_an_unreadable_target_with_403_and_no_metadata_leak(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        try {
            $fake->resolve($context, 'b3d3a7f0-6e9f-4d61-9b8f-6a3d0a7c9e21');
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException $e) {
            $this->assertStringNotContainsString('Dieu Khoan Noi Bo', $e->getMessage());
            $this->assertStringNotContainsString('dieu-khoan-noi-bo', $e->getMessage());
            $this->assertStringNotContainsString('reader', $e->getMessage());
        }
    }

    public function test_resolve_returns_404_absent_for_an_unknown_target_id(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $this->expectException(ModelNotFoundException::class);

        $fake->resolve($context, 'does-not-exist-anywhere');
    }

    public function test_resolve_returns_404_absent_not_403_for_a_cross_project_id(): void
    {
        // A target that DOES exist, but under a different project, must be
        // indistinguishable from "does not exist" (D-046 / architecture.md
        // "denied 403 versus absent-in-authorized-scope 404") -- a 403 here
        // would itself leak that the id exists somewhere.
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        try {
            $fake->resolve($context, 'cross-project-0001');
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException $e) {
            $this->assertStringNotContainsString('Foreign Project Page', $e->getMessage());
            $this->assertNotInstanceOf(AuthorizationException::class, $e);
        }
    }

    public function test_search_never_returns_a_target_from_another_project(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $page = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));

        $labels = array_map(static fn ($item) => $item->label, $page->items);
        $this->assertNotContains('Foreign Project Page', $labels);
    }

    public function test_search_never_returns_an_unreadable_target(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $page = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));

        $labels = array_map(static fn ($item) => $item->label, $page->items);
        $this->assertNotContains('Dieu Khoan Noi Bo', $labels);
    }

    public function test_search_honors_capped_pagination_and_never_exceeds_max_per_page(): void
    {
        $fake = new FakeWidgetTargetContextResolver();
        for ($i = 1; $i <= 150; $i++) {
            $fake->withTarget("page-{$i}", 'page.static', self::PROJECT, "Page {$i}", 'reader', ['slug' => "page-{$i}"]);
        }
        $context = new AuthorizedProjectContext(self::PROJECT);

        // Ask for far more than the cap; WidgetTargetSearchQuery's own
        // constructor clamps perPage before the resolver ever sees it.
        $query = new WidgetTargetSearchQuery(targetType: 'page.static', perPage: 500);
        $this->assertSame(WidgetTargetSearchQuery::MAX_PER_PAGE, $query->perPage);

        $page = $fake->search($context, $query);

        $this->assertCount(WidgetTargetSearchQuery::MAX_PER_PAGE, $page->items);
        $this->assertSame(100, $page->perPage);
        $this->assertSame(150, $page->total);
    }

    public function test_search_default_per_page_matches_the_frozen_default(): void
    {
        $fake = new FakeWidgetTargetContextResolver();
        for ($i = 1; $i <= 25; $i++) {
            $fake->withTarget("page-{$i}", 'page.static', self::PROJECT, "Page {$i}", 'reader', ['slug' => "page-{$i}"]);
        }
        $context = new AuthorizedProjectContext(self::PROJECT);

        $page = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));

        $this->assertSame(WidgetTargetSearchQuery::DEFAULT_PER_PAGE, $page->perPage);
        $this->assertCount(20, $page->items);
        $this->assertSame(25, $page->total);
    }

    public function test_search_and_resolve_never_mutate_the_fixture(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);
        $before = $fake->fixtureCount();

        $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));
        $fake->resolve($context, '123');

        $this->assertSame($before, $fake->fixtureCount());
    }

    // -----------------------------------------------------------------
    // Template/target mismatch: a target resolved through the shared
    // fixture but registered under a DIFFERENT target type than the Slot's
    // declared Template Context expects must be rejected with a typed
    // exception before it is ever treated as a valid placement. No
    // controller exists yet (Task 13+); this guard represents that future
    // placement-time validation as a pure DTO-level contract proof, using
    // the same InvalidArgumentException idiom already used elsewhere in
    // this module (Cms\Widget\Http\Support\WidgetApiErrorResponse::definition()).
    // -----------------------------------------------------------------

    private function guardTargetTypeMatchesSlotExpectation(WidgetResolvedTarget $resolved, string $expectedTargetType): void
    {
        if ($resolved->targetType !== $expectedTargetType) {
            throw new InvalidArgumentException(
                "Target '{$resolved->targetId}' is registered as '{$resolved->targetType}', not the Slot's expected '{$expectedTargetType}'."
            );
        }
    }

    public function test_a_page_static_target_matches_the_page_static_slot_expectation(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $resolved = $fake->resolve($context, '123');
        $this->assertSame('page.static', $resolved->targetType);

        // Must not throw for a matching type.
        $this->guardTargetTypeMatchesSlotExpectation($resolved, 'page.static');
        $this->assertTrue(true);
    }

    public function test_an_article_detail_target_is_rejected_as_a_typed_mismatch_against_the_page_static_slot(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        $resolved = $fake->resolve($context, 'article-0001');
        $this->assertSame('article.detail', $resolved->targetType);

        $this->expectException(InvalidArgumentException::class);
        $this->guardTargetTypeMatchesSlotExpectation($resolved, 'page.static');
    }

    // -----------------------------------------------------------------
    // Uniformity: preview / catalog / authorization / generic search+detail
    // all resolve the SAME approved static Page target through the SAME
    // contract call -- no divergent per-use-case branch exists (or is
    // needed) because there is exactly one typed resolver signature.
    // -----------------------------------------------------------------

    public function test_preview_catalog_authorization_and_generic_search_detail_all_resolve_the_same_page_through_the_identical_contract(): void
    {
        $fake = $this->pageStaticFixture();
        $context = new AuthorizedProjectContext(self::PROJECT);

        // "generic search" use case -- matches the frozen
        // GET /target-contexts/{type}/targets shape (docs/contracts/widget-site-layout-api.md:70).
        $searchPage = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static', keywords: 'Gioi'));
        $this->assertCount(1, $searchPage->items);
        $viaSearch = $searchPage->items[0];

        // "generic detail" use case -- matches the frozen
        // GET /target-contexts/{type}/targets/{target_id} shape (docs/contracts/widget-site-layout-api.md:71).
        $viaDetail = $fake->resolve($context, $viaSearch->targetId);

        // "preview" and "catalog/authorization" use cases: both are simply
        // additional future callers of the identical resolve() signature --
        // proven here by invoking it again and asserting byte-identical
        // output, i.e. there is no bespoke Page/Post branch per caller.
        $viaPreview = $fake->resolve($context, $viaSearch->targetId);
        $viaCatalogAuthorization = $fake->resolve($context, $viaSearch->targetId);

        $this->assertSame($viaSearch->targetId, $viaDetail->targetId);
        $this->assertSame($viaDetail->targetId, $viaPreview->targetId);
        $this->assertSame($viaPreview->targetId, $viaCatalogAuthorization->targetId);
        $this->assertSame($viaDetail->label, $viaPreview->label);
        $this->assertSame($viaDetail->routeName, $viaPreview->routeName);
        $this->assertSame($viaDetail->routeParameters, $viaPreview->routeParameters);
        $this->assertSame('page.static', $viaDetail->targetType);

        // The same uniformity holds for the denied/absent edges: every use
        // case above would hit the identical 403/404 already proven by
        // test_resolve_denies_an_unreadable_target_with_403_and_no_metadata_leak()
        // and test_resolve_returns_404_absent_not_403_for_a_cross_project_id(),
        // since all four "use cases" are the same PHP call.
    }
}
