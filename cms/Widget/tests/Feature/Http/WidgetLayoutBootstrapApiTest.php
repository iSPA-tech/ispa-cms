<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Chunk 2 Task 17: `PUT /layouts/by-key/{layout_key}` bootstrap plus the
 * sibling `GET /layouts` (list) and `GET /layouts/{uuid}` (detail) reads
 * (lines
 * 1020-1051; architecture.md "Layout bootstrap";
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * `/layouts`, `/layouts/by-key/{layout_key}`, `/layouts/{uuid}` route
 * entries).
 *
 * No artifact/materialization plumbing is exercised here -- Bootstrap never
 * touches a Widget/Theme/Manifest, so this suite needs none of
 * WidgetManifestMaterializationApiTest's storage isolation.
 */
final class WidgetLayoutBootstrapApiTest extends TestCase
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
            'name' => 'Layout Tester',
            'email' => 'layout-tester-' . uniqid('', true) . '@test.local',
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

    private function fullAccessUser(): User
    {
        return $this->userOfType(UserType::ADMIN, [
            'widget_layout.viewAny',
            'widget_layout.view',
            'widget_layout.update',
        ]);
    }

    // -----------------------------------------------------------------
    // Bootstrap: happy path
    // -----------------------------------------------------------------

    public function test_bootstrap_creates_a_layout_and_initial_draft_and_returns_201(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');

        $response->assertCreated()
            ->assertJsonPath('data.project_name', self::PROJECT)
            ->assertJsonPath('data.key', 'site')
            ->assertJsonPath('data.current_draft.version', 1)
            ->assertJsonPath('data.current_draft.lock_version', 0)
            ->assertJsonPath('data.current_published', null)
            ->assertJsonStructure(['data' => ['uuid', 'project_name', 'key', 'current_draft', 'current_published', 'created_at', 'updated_at']]);

        $this->assertSame(1, WidgetLayout::query()->count());
        $this->assertSame(1, WidgetLayoutVersion::query()->count());

        $layout = WidgetLayout::query()->firstOrFail();
        $draft = WidgetLayoutVersion::query()->firstOrFail();

        $this->assertSame($layout->current_draft_version_id, $draft->id);
        $this->assertNull($layout->current_published_version_id);
        $this->assertSame(WidgetLayoutVersion::STATE_DRAFT, $draft->state);
        $this->assertSame(0, $draft->lock_version);

        $expected = app(WidgetRegistryContractSnapshot::class)->build();
        $this->assertSame($expected['contracts'], $draft->registry_contracts, 'Initial creation must compute the canonical EMPTY registry baseline.');
        $this->assertSame($expected['hash'], $draft->registry_contract_hash);
    }

    public function test_bootstrap_is_idempotent_and_returns_200_with_the_existing_identity_on_a_second_call(): void
    {
        $user = $this->fullAccessUser();

        $first = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $first->assertCreated();

        $second = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $second->assertOk()->assertJsonPath('data.uuid', $first->json('data.uuid'));

        $this->assertSame(1, WidgetLayout::query()->count(), 'A second bootstrap call must never create a second Layout.');
        $this->assertSame(1, WidgetLayoutVersion::query()->count(), 'A second bootstrap call must never create a second Draft.');
    }

    public function test_bootstrap_converges_on_an_already_existing_row_without_creating_a_second_one(): void
    {
        // Simulates the "conflict readback" half of the concurrency contract
        // deterministically in-process: a competing Layout row is already
        // present (as a genuinely concurrent second caller would have left
        // one) before this call ever runs. True two-connection race coverage
        // for the raw unique-violation retry path belongs to the chunk-exit
        // dual-engine matrix (lean per-task process), not this suite.
        $user = $this->fullAccessUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site')->assertCreated();
        $existingUuid = WidgetLayout::query()->firstOrFail()->uuid;

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');

        $response->assertOk()->assertJsonPath('data.uuid', $existingUuid);
        $this->assertSame(1, WidgetLayout::query()->count());
    }

    // -----------------------------------------------------------------
    // Bootstrap: unknown key rejection
    // -----------------------------------------------------------------

    public function test_bootstrap_rejects_an_unknown_layout_key(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/not-a-real-key');

        $response->assertStatus(422);
        $this->assertSame(0, WidgetLayout::query()->count());
    }

    // -----------------------------------------------------------------
    // Bootstrap: permission
    // -----------------------------------------------------------------

    public function test_bootstrap_denies_a_user_without_permission(): void
    {
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
        $this->assertSame(0, WidgetLayout::query()->count());
    }

    // -----------------------------------------------------------------
    // GET list/detail never create data
    // -----------------------------------------------------------------

    public function test_index_never_creates_a_layout(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/layouts');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertSame(0, WidgetLayout::query()->count());
    }

    public function test_index_denies_a_user_without_the_view_any_permission(): void
    {
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')->getJson('/api/v2/admin/widget/layouts');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_show_returns_404_for_an_unknown_uuid_and_never_creates_a_layout(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/layouts/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
        $this->assertSame(0, WidgetLayout::query()->count());
    }

    public function test_show_returns_the_bootstrapped_layout(): void
    {
        $user = $this->fullAccessUser();

        $created = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site')->assertCreated();
        $uuid = $created->json('data.uuid');

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v2/admin/widget/layouts/{$uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.key', 'site')
            ->assertJsonPath('data.current_draft.version', 1);
    }

    public function test_index_lists_only_layouts_for_the_authorized_project(): void
    {
        $user = $this->fullAccessUser();
        $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site')->assertCreated();

        WidgetLayout::query()->create(['project_name' => 'AnotherProject', 'key' => 'site']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/layouts');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(self::PROJECT, $response->json('data.0.project_name'));
    }
}
