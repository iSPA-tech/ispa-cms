<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 18: `POST /api/v2/admin/widget/layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin`
 * (lines
 * 1053-1080; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * versions-and-repin route entry).
 *
 * HTTP-layer contract only: permission gate, request-shape validation,
 * route wiring, status/envelope mapping. Transaction/locking/idempotency/
 * registry-recompute semantics are
 * {@see \Cms\Widget\Tests\Feature\SiteLayout\UpdateInstanceAndRepinDraftTest}'s
 * job -- mirrors this module's established HTTP-vs-service test split
 * (e.g. {@see WidgetInstanceApiTest} for the HTTP layer of Task 16).
 */
final class WidgetInstanceDraftRepinApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.repin.http.slot';

    private string $storageRoot;

    /** @var array<string, string|null> class-level snapshot: array-keyed shape, see WidgetInstanceApiTest's docblock for the double-restore hazard this avoids. */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = dirname(__DIR__, 5) . '/modules_statuses.json';
        self::$modulesStatusesSnapshot = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreModulesStatuses();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreModulesStatuses();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreModulesStatuses(): void
    {
        foreach (self::$modulesStatusesSnapshot as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$modulesStatusesSnapshot = [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-repin-api-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.repin.http.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->setWidgetModuleEnabled(true);

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->storageRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    private function setWidgetModuleEnabled(bool $enabled): void
    {
        app(ActivatorInterface::class)->setActiveByName('Widget', $enabled);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Repin API Tester',
            'email' => 'repin-api-tester-' . uniqid('', true) . '@test.local',
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
            'widget_instance.create',
            'widget_instance.update',
            'widget_layout.viewAny',
            'widget_layout.view',
            'widget_layout.update',
            'widget_template.approve',
        ]);
    }

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Test Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);
    }

    private function liveSourceFingerprint(Widget $widget, WidgetTheme $theme): string
    {
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: is_array($theme->project_name) ? $theme->project_name : ['Default'],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    private function approveManifestFor(Widget $widget, WidgetTheme $theme, User $approver): void
    {
        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();

        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $response->json('data.manifest_hash'), 1, (int) $approver->id, 'test approval');
    }

    /**
     * @return array{instance_uuid: string, version: int}
     */
    private function createReadyInstance(User $user, Widget $widget, WidgetTheme $theme, string $name = 'Shared Instance'): array
    {
        $this->approveManifestFor($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'create-key-' . uniqid('', true)])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => $name,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ]);
        $response->assertCreated();

        return ['instance_uuid' => $response->json('data.uuid'), 'version' => $response->json('data.current_version.version')];
    }

    /**
     * @return array{uuid: string, version: int, lock_version: int}
     */
    private function bootstrapLayout(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $response->assertSuccessful();

        return [
            'uuid' => $response->json('data.uuid'),
            'version' => $response->json('data.current_draft.version'),
            'lock_version' => $response->json('data.current_draft.lock_version'),
        ];
    }

    /**
     * @return array{layout_uuid: string, draft_version: int, lock_version: int, placement_uuid: string}
     */
    private function saveDraftWithPlacement(User $user, array $layout, array $instanceRef): array
    {
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v2/admin/widget/layouts/{$layout['uuid']}/draft", [
            'expected_current_draft_id' => $layout['version'],
            'expected_lock_version' => $layout['lock_version'],
            'placements' => [[
                'slot_key' => self::SLOT,
                'scope_type' => 'global',
                'scope_key' => null,
                'target_type' => null,
                'target_id' => null,
                'widget_instance_uuid' => $instanceRef['instance_uuid'],
                'widget_instance_version' => $instanceRef['version'],
                'position' => 0,
                'enabled' => true,
                'rules' => [],
            ]],
        ]);
        $response->assertOk();

        return [
            'layout_uuid' => $layout['uuid'],
            'draft_version' => $layout['version'],
            'lock_version' => $layout['lock_version'] + 1,
            'placement_uuid' => $response->json('data.0.uuid'),
        ];
    }

    private function usageFingerprint(int $draftVersion, int $draftLockVersion, int $instanceCurrentVersion, array $matchingPlacementUuids): string
    {
        sort($matchingPlacementUuids);

        return CanonicalJson::hash([
            'draft_version' => $draftVersion,
            'draft_lock_version' => $draftLockVersion,
            'instance_current_version' => $instanceCurrentVersion,
            'matching_placement_uuids' => $matchingPlacementUuids,
        ]);
    }

    private function repin(User $user, string $layoutUuid, string $instanceUuid, array $overrides = [], ?string $idempotencyKey = 'repin-http-key'): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'expected_current_instance_version' => 1,
            'expected_draft_id' => 1,
            'expected_draft_lock_version' => 1,
            'usage_fingerprint' => 'placeholder',
            'widget_id' => 1,
            'widget_theme_id' => 1,
            'values' => [],
            'provider_contract' => [],
        ], $overrides);

        $request = $this->actingAs($user, 'sanctum');
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $request->postJson("/api/v2/admin/widget/layouts/{$layoutUuid}/draft/instances/{$instanceUuid}/versions-and-repin", $payload);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_repin_returns_200_with_the_normalized_instance_and_draft(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $saved = $this->saveDraftWithPlacement($user, $layout, $instanceRef);

        $fingerprint = $this->usageFingerprint($saved['draft_version'], $saved['lock_version'], $instanceRef['version'], [$saved['placement_uuid']]);

        $response = $this->repin($user, $saved['layout_uuid'], $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => $instanceRef['version'],
            'expected_draft_id' => $saved['draft_version'],
            'expected_draft_lock_version' => $saved['lock_version'],
            'usage_fingerprint' => $fingerprint,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => ['title' => 'Repinned Via HTTP'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.instance.uuid', $instanceRef['instance_uuid'])
            ->assertJsonPath('data.instance.current_version.version', 2)
            ->assertJsonPath('data.instance.current_version.values.title', 'Repinned Via HTTP')
            ->assertJsonPath('data.instance.sync_status', 'ready')
            ->assertJsonPath('data.draft.version', $saved['draft_version'])
            ->assertJsonPath('data.draft.lock_version', $saved['lock_version'] + 1)
            ->assertJsonPath('data.draft.placements.0.widget_instance_uuid', $instanceRef['instance_uuid'])
            ->assertJsonPath('data.draft.placements.0.widget_instance_version', 2)
            ->assertJsonPath('data.sync_status', 'ready');
    }

    // -----------------------------------------------------------------
    // Permission (403 before 422/404/409)
    // -----------------------------------------------------------------

    public function test_repin_denies_a_user_missing_the_widget_layout_update_permission(): void
    {
        $user = $this->userOfType(UserType::ADMIN, ['widget_instance.update']);

        $response = $this->repin($user, '00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000001');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_repin_denies_a_user_missing_the_widget_instance_update_permission(): void
    {
        $user = $this->userOfType(UserType::ADMIN, ['widget_layout.update']);

        $response = $this->repin($user, '00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000001');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_repin_requires_an_idempotency_key(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->repin($user, '00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000001', idempotencyKey: null);

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Not found
    // -----------------------------------------------------------------

    public function test_repin_returns_404_for_an_unknown_layout_uuid(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);

        $response = $this->repin($user, '00000000-0000-0000-0000-000000000000', $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => $instanceRef['version'],
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_repin_returns_404_for_an_unknown_instance_uuid(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);

        $response = $this->repin($user, $layout['uuid'], '00000000-0000-0000-0000-000000000000', [
            'expected_draft_id' => $layout['version'],
            'expected_draft_lock_version' => $layout['lock_version'],
        ]);

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Stale state (409)
    // -----------------------------------------------------------------

    public function test_repin_returns_409_draft_stale_for_a_wrong_expected_draft_lock_version(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $saved = $this->saveDraftWithPlacement($user, $layout, $instanceRef);

        $fingerprint = $this->usageFingerprint($saved['draft_version'], $saved['lock_version'], $instanceRef['version'], [$saved['placement_uuid']]);

        $response = $this->repin($user, $saved['layout_uuid'], $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => $instanceRef['version'],
            'expected_draft_id' => $saved['draft_version'],
            'expected_draft_lock_version' => $saved['lock_version'] + 1, // wrong
            'usage_fingerprint' => $fingerprint,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', WidgetInstance::query()->where('uuid', $instanceRef['instance_uuid'])->value('id'))->count());
    }

    public function test_repin_returns_409_usage_changed_for_a_wrong_expected_current_instance_version(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $saved = $this->saveDraftWithPlacement($user, $layout, $instanceRef);

        $fingerprint = $this->usageFingerprint($saved['draft_version'], $saved['lock_version'], $instanceRef['version'], [$saved['placement_uuid']]);

        $response = $this->repin($user, $saved['layout_uuid'], $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => 999, // wrong
            'expected_draft_id' => $saved['draft_version'],
            'expected_draft_lock_version' => $saved['lock_version'],
            'usage_fingerprint' => $fingerprint,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_USAGE_CHANGED');
    }

    public function test_repin_returns_409_usage_changed_for_a_mismatched_usage_fingerprint(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $saved = $this->saveDraftWithPlacement($user, $layout, $instanceRef);

        $response = $this->repin($user, $saved['layout_uuid'], $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => $instanceRef['version'],
            'expected_draft_id' => $saved['draft_version'],
            'expected_draft_lock_version' => $saved['lock_version'],
            'usage_fingerprint' => 'deliberately-wrong',
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_USAGE_CHANGED');
    }

    // -----------------------------------------------------------------
    // Module lifecycle barrier: disable-wins creates no Version/repin
    // -----------------------------------------------------------------

    public function test_repin_returns_409_module_in_use_when_the_widget_module_is_disabled(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $saved = $this->saveDraftWithPlacement($user, $layout, $instanceRef);
        $fingerprint = $this->usageFingerprint($saved['draft_version'], $saved['lock_version'], $instanceRef['version'], [$saved['placement_uuid']]);

        $this->setWidgetModuleEnabled(false);

        $response = $this->repin($user, $saved['layout_uuid'], $instanceRef['instance_uuid'], [
            'expected_current_instance_version' => $instanceRef['version'],
            'expected_draft_id' => $saved['draft_version'],
            'expected_draft_lock_version' => $saved['lock_version'],
            'usage_fingerprint' => $fingerprint,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MODULE_IN_USE');

        $instance = WidgetInstance::query()->where('uuid', $instanceRef['instance_uuid'])->firstOrFail();
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'Disable-wins must create no new Version.');
    }

    // -----------------------------------------------------------------
    // Cross-check: Draft PUT never mutates the Instance it references
    // (existing Task 16/17 invariant, proven again per the plan's DoD line).
    // -----------------------------------------------------------------

    public function test_draft_put_never_mutates_the_pinned_instance(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceRef = $this->createReadyInstance($user, $widget, $theme);
        $layout = $this->bootstrapLayout($user);
        $this->saveDraftWithPlacement($user, $layout, $instanceRef);

        $instance = WidgetInstance::query()->where('uuid', $instanceRef['instance_uuid'])->firstOrFail();
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'Saving a Draft that references an Instance must never create a new Instance Version.');
        $this->assertSame($instanceRef['version'], $instance->currentVersion->version);
    }
}
