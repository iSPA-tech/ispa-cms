<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Chunk 2 Task 22: generic target search/detail
 * (lines
 * 1181-1216, DoD line 1200; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * `/target-contexts/{type}/targets` and `/target-contexts/{type}/targets/{target_id}`
 * route entries).
 *
 * Registers the Task 12b CONTRACT FAKE {@see FakeWidgetTargetContextResolver}
 * against the real {@see WidgetTargetContextRegistry} multiplexer -- proving
 * the CONTROLLER dispatches the Task 7 typed resolver methods generically
 * (project/read authorization, capped pagination, safe labels, opaque
 * string IDs, 403/404) without ever branching on target type or converting
 * identifiers. The real project-owned Page resolver is Task 23's own scope,
 * not built here.
 */
final class WidgetTargetDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Discovery Tester',
            'email' => 'discovery-tester-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => $type,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => 'Test Role ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function authorizedUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_layout.viewAny']);
    }

    private function unauthorizedUser(): User
    {
        return $this->userOfType(UserType::ADMIN);
    }

    private function registerPageStaticFake(): FakeWidgetTargetContextResolver
    {
        $fake = new FakeWidgetTargetContextResolver();

        app(WidgetTemplateContextRegistry::class)->registerTemplateContext('page_static', []);
        app(WidgetTargetContextRegistry::class)->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: 'page.static',
            labelKey: 'widget_site_layout.template_context.page_static',
            templateContextKey: 'page_static',
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        $this->app->bind(FakeWidgetTargetContextResolver::class, fn () => $fake);

        return $fake;
    }

    // ===================================================================
    // GET /target-contexts/{type}/targets (search)
    // ===================================================================

    public function test_search_requires_widget_layout_view_any_permission(): void
    {
        $this->registerPageStaticFake();

        $response = $this->actingAs($this->unauthorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_search_returns_404_for_an_unregistered_target_type(): void
    {
        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/never.registered/targets');

        $response->assertStatus(404);
    }

    public function test_search_returns_matching_targets_scoped_to_the_authorized_project(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('123', 'page.static', self::PROJECT, 'Gioi Thieu', 'reader', ['slug' => 'gioi-thieu']);
        $fake->withTarget('999', 'page.static', 'SomeOtherProject', 'Cross Project', 'reader', ['slug' => 'x']);

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('123', $rows[0]['target_id']);
        $this->assertSame('string', gettype($rows[0]['target_id']), 'target_id must stay an opaque string end-to-end.');
        $this->assertSame('Gioi Thieu', $rows[0]['label']);
        $this->assertSame('reader', $rows[0]['route_name']);
        $this->assertSame(['slug' => 'gioi-thieu'], $rows[0]['route_parameters']);
    }

    public function test_search_never_returns_an_unreadable_row(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('locked-1', 'page.static', self::PROJECT, 'Locked', 'reader', [], readable: false);

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets');

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_search_respects_the_keyword_filter(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('1', 'page.static', self::PROJECT, 'Gioi Thieu', 'reader');
        $fake->withTarget('2', 'page.static', self::PROJECT, 'Lien He', 'reader');

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets?search=Lien');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('2', $rows[0]['target_id']);
    }

    public function test_search_caps_per_page_at_the_frozen_maximum(): void
    {
        $fake = $this->registerPageStaticFake();
        for ($i = 1; $i <= 5; $i++) {
            $fake->withTarget((string) $i, 'page.static', self::PROJECT, "Target {$i}", 'reader');
        }

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets?per_page=500');

        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_search_rejects_a_non_numeric_page_parameter_with_422(): void
    {
        $this->registerPageStaticFake();

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets?page=not-a-number');

        $response->assertStatus(422);
    }

    // ===================================================================
    // GET /target-contexts/{type}/targets/{target_id} (detail)
    // ===================================================================

    public function test_detail_requires_widget_layout_view_any_permission(): void
    {
        $this->registerPageStaticFake();

        $response = $this->actingAs($this->unauthorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets/123');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_detail_returns_404_for_an_unregistered_target_type(): void
    {
        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/never.registered/targets/123');

        $response->assertStatus(404);
    }

    public function test_detail_returns_the_resolved_target(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('123', 'page.static', self::PROJECT, 'Gioi Thieu', 'reader', ['slug' => 'gioi-thieu']);

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets/123');

        $response->assertOk()
            ->assertJsonPath('data.target_id', '123')
            ->assertJsonPath('data.target_type', 'page.static')
            ->assertJsonPath('data.label', 'Gioi Thieu')
            ->assertJsonPath('data.route_name', 'reader')
            ->assertJsonPath('data.route_parameters', ['slug' => 'gioi-thieu']);
    }

    public function test_detail_returns_404_for_an_unknown_target_id(): void
    {
        $this->registerPageStaticFake();

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets/does-not-exist');

        $response->assertStatus(404);
    }

    public function test_detail_returns_404_for_a_cross_project_target_id_never_leaking_it_exists(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('other-project-1', 'page.static', 'SomeOtherProject', 'Not Yours', 'reader');

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets/other-project-1');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('Not Yours', $response->getContent());
    }

    public function test_detail_returns_403_for_an_unreadable_target_without_leaking_metadata(): void
    {
        $fake = $this->registerPageStaticFake();
        $fake->withTarget('locked-1', 'page.static', self::PROJECT, 'Secret Label', 'reader', [], readable: false);

        $response = $this->actingAs($this->authorizedUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/target-contexts/page.static/targets/locked-1');

        $response->assertStatus(403);
        $this->assertStringNotContainsString('Secret Label', $response->getContent());
    }
}
