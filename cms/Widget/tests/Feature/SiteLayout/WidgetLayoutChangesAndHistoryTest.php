<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\SiteLayout\Drafts\WidgetPlacementContentService;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Unified Widget Catalog Part 2, Task 5: the publish dialog's change list, a readable release
 * history, a preview that needs no page target, and the area count the admin nav uses.
 */
final class WidgetLayoutChangesAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.changes.slot';

    private const TEMPLATE = 'page.static';

    private const TARGET_TYPE = 'test.changes.page';

    private const ROUTE_NAME = 'test.widget.changes.page';

    private const BASE = '/api/v2/admin/widget/layouts/';

    private string $storageRoot;

    /** @var array<string, string|null> */
    private static array $modulesStatusesSnapshot = [];

    private Widget $widget;

    private WidgetTheme $theme;

    private User $user;

    private WidgetLayout $layout;

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

    private static function previewRedisReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            set_error_handler(static fn (): bool => true);
            $socket = fsockopen('127.0.0.1', 6399, $errno, $errstr, 0.5);
            restore_error_handler();
            $reachable = $socket !== false;
            if ($socket !== false) {
                fclose($socket);
            }
        }

        return $reachable;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);
        // The preview store's singleton captures this before the provider boots.
        config(['database.redis.widget_preview' => ['host' => '127.0.0.1', 'port' => 6399, 'database' => 15, 'timeout' => 2.0]]);
        app()->setLocale('vi');

        $this->storageRoot = storage_path('framework/testing/widget-changes-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->app->make(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'widget_site_layout.slot.site_header_before',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));
        $this->app->make(WidgetTemplateContextRegistry::class)->registerTemplateContext(self::TEMPLATE, [self::SLOT]);

        $resolver = (new FakeWidgetTargetContextResolver())
            ->withTarget('page-hidden', self::TARGET_TYPE, self::PROJECT, 'Bản nháp chưa đăng', self::ROUTE_NAME, ['slug' => 'page-hidden'], readable: false)
            ->withTarget('page-1', self::TARGET_TYPE, self::PROJECT, 'Giới thiệu', self::ROUTE_NAME, ['slug' => 'page-1']);
        $this->app->instance(FakeWidgetTargetContextResolver::class, $resolver);
        $this->app->make(WidgetTargetContextRegistry::class)->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'widget_site_layout.target_context.page',
            templateContextKey: self::TEMPLATE,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));
        $this->registerTargetRoute();

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);

        $this->widget = Widget::query()->create(['name' => 'Area Widget', 'description' => '', 'js' => '']);
        $this->theme = WidgetTheme::query()->create([
            'widget_id' => $this->widget->id,
            'name' => 'Area Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);
        $this->user = $this->layoutEditor();
        $this->layout = $this->emptyLayout(self::PROJECT, 'site');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function registerTargetRoute(): void
    {
        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-changes/{slug}', static fn (string $slug) => response('page:' . $slug))
                ->name(self::ROUTE_NAME);
            app('router')->getRoutes()->refreshNameLookups();
        }
    }

    private function layoutEditor(): User
    {
        $user = User::forceCreate([
            'name' => 'Chị Lan',
            'email' => 'area-editor-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => UserType::ADMIN,
        ]);

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => 'Area editor ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach (['widget_layout.viewAny', 'widget_layout.view', 'widget_layout.update', 'widget_layout.publish', 'widget_layout.preview'] as $key) {
            [$group, $name] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->where('group', $group)->where('name', $name)->firstOrFail()->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function emptyLayout(string $project, string $key): WidgetLayout
    {
        $layout = WidgetLayout::query()->create(['project_name' => $project, 'key' => $key]);
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);
        $contracts = ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
        $draft = WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => $project,
            'version' => 1,
            'state' => WidgetLayoutVersion::STATE_DRAFT,
            'lock_version' => 0,
            'registry_contracts' => $contracts,
            'registry_contract_hash' => hash('sha256', json_encode($contracts)),
        ]);
        $layout->current_draft_version_id = $draft->id;
        $layout->save();

        return $layout->fresh();
    }

    private function draft(): WidgetLayoutVersion
    {
        return WidgetLayoutVersion::query()->findOrFail($this->layout->fresh()->current_draft_version_id);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $scope
     */
    private function addPlacement(array $values, array $scope = ['scope_type' => 'global']): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'add-' . uniqid('', true)])
            ->postJson(self::BASE . $this->layout->uuid . '/draft/placements', $scope + [
                'slot_key' => self::SLOT,
                'widget_id' => $this->widget->id,
                'widget_theme_id' => $this->theme->id,
                'values' => $values,
                'expected_draft_revision' => $this->draft()->lock_version,
            ])
            ->assertCreated();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function editContent(string $placementUuid, array $values): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'edit-' . uniqid('', true)])
            ->patchJson(self::BASE . $this->layout->uuid . '/draft/placements/' . $placementUuid . '/content', [
                'values' => $values,
                'expected_draft_revision' => $this->draft()->lock_version,
            ])
            ->assertOk();
    }

    private function publish(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'publish-' . uniqid('', true)])
            ->postJson(self::BASE . $this->layout->uuid . '/publish', [
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ])
            ->assertOk();
    }

    /**
     * @param list<array<string, mixed>> $placements placement payloads from a draft response
     */
    private function saveDraft(array $placements): void
    {
        $draft = $this->draft();
        $this->actingAs($this->user, 'sanctum')
            ->putJson(self::BASE . $this->layout->uuid . '/draft', [
                'expected_current_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
                'placements' => array_map(static fn (array $p): array => [
                    'slot_key' => $p['slot_key'],
                    'scope_type' => $p['scope_type'],
                    'scope_key' => $p['scope_key'],
                    'target_type' => $p['target_type'],
                    'target_id' => $p['target_id'],
                    'widget_instance_uuid' => $p['widget_instance_uuid'],
                    'widget_instance_version' => $p['widget_instance_version'],
                    'position' => $p['position'],
                    'enabled' => $p['enabled'],
                    'rules' => [],
                ], $placements),
            ])
            ->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftPlacements(): array
    {
        return $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')->json('data.placements');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changes(): array
    {
        $rows = $this->actingAs($this->user, 'sanctum')
            ->getJson(self::BASE . $this->layout->uuid . '/draft/changes')
            ->assertOk()
            ->json('data');
        usort($rows, static fn (array $a, array $b): int => [$a['kind'], $a['scope_type']] <=> [$b['kind'], $b['scope_type']]);

        return $rows;
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    public function test_change_list_covers_added_removed_changed(): void
    {
        $this->addPlacement(['title' => 'Kept']);
        $this->addPlacement(['title' => 'By type'], ['scope_type' => 'template', 'scope_key' => self::TEMPLATE]);
        $this->addPlacement(['title' => 'One page'], ['scope_type' => 'target', 'target_type' => self::TARGET_TYPE, 'target_id' => 'page-1']);

        // Nothing published yet: every placement is new.
        $this->assertSame(['added', 'added', 'added'], array_column($this->changes(), 'kind'));

        $this->publish();
        $this->assertSame([], $this->changes(), 'Right after publish the draft equals the live release.');

        $placements = $this->draftPlacements();
        $byScope = array_column($placements, null, 'scope_type');
        // Reordering alone is not a change.
        $this->saveDraft(array_map(static fn (array $p): array => ['position' => 2 - $p['position']] + $p, $placements));
        $this->assertSame([], $this->changes());

        $byScope = array_column($this->draftPlacements(), null, 'scope_type');
        $this->editContent($byScope['target']['uuid'], ['title' => 'One page edited']);
        $byScope = array_column($this->draftPlacements(), null, 'scope_type');
        $this->saveDraft([$byScope['global'], $byScope['target']]);
        $this->addPlacement(['title' => 'New'], ['scope_type' => 'global']);

        $common = ['slot_key' => self::SLOT, 'slot_label' => 'Trước tiêu đề đầu trang', 'widget_name' => 'Area Widget'];
        $this->assertSame([
            ['kind' => 'added'] + $common + ['scope_type' => 'global', 'scope_label' => 'Toàn website'],
            ['kind' => 'changed'] + $common + ['scope_type' => 'target', 'scope_label' => 'Riêng trang này · Giới thiệu'],
            ['kind' => 'removed'] + $common + ['scope_type' => 'template', 'scope_label' => 'Theo loại trang · Trang tĩnh'],
        ], array_map(static fn (array $row): array => [
            'kind' => $row['kind'],
            'slot_key' => $row['slot_key'],
            'slot_label' => $row['slot_label'],
            'widget_name' => $row['widget_name'],
            'scope_type' => $row['scope_type'],
            'scope_label' => $row['scope_label'],
        ], $this->changes()));

        // Another project's layout is never visible.
        $other = $this->emptyLayout('OtherProject', 'site');
        $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $other->uuid . '/draft/changes')->assertNotFound();
    }

    public function test_forked_shared_instance_is_one_change(): void
    {
        $shared = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $this->user->id, 'legacy-shared', [
            'name' => 'Legacy shared',
            'widget_id' => $this->widget->id,
            'widget_theme_id' => $this->theme->id,
            'values' => ['title' => 'Shared'],
        ])->instance;
        $row = ['widget_instance_uuid' => $shared->uuid, 'widget_instance_version' => 1, 'slot_key' => self::SLOT, 'scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'enabled' => true];
        $this->saveDraft([$row + ['position' => 0], $row + ['position' => 1]]);
        $this->publish();

        $first = WidgetPlacement::query()->where('layout_version_id', $this->draft()->id)->orderBy('position')->firstOrFail();
        $this->editContent($first->uuid, ['title' => 'Forked']);

        $changes = $this->changes();
        $this->assertCount(1, $changes, 'Editing one of two placements of a shared instance forks it: one change, not added + removed.');
        $this->assertSame('changed', $changes[0]['kind']);
    }

    public function test_history_carries_publisher_name_and_live_flag(): void
    {
        $this->addPlacement(['title' => 'First']);
        $this->publish();
        $this->addPlacement(['title' => 'Second']);
        $this->publish();

        $response = $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/releases')->assertOk();

        $versions = array_column($response->json('data'), 'version');
        $this->assertCount(2, $versions);
        $this->assertGreaterThan($versions[1], $versions[0], 'Newest first.');
        $this->assertSame([true, false], array_column($response->json('data'), 'is_live'));
        $this->assertSame(['Chị Lan', 'Chị Lan'], array_column($response->json('data'), 'published_by_name'));
        foreach ($response->json('data') as $release) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $release['published_at']);
        }
    }

    public function test_preview_without_target_renders(): void
    {
        if (!self::previewRedisReachable()) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable');
        }
        (new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 15]))->flushdb();

        $this->addPlacement(['title' => 'Header']);
        $overlay = array_map(static fn (array $p): array => array_diff_key($p, ['uuid' => true]), $this->draftPlacements());

        // The home page: all areas, no page target.
        $minted = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::BASE . $this->layout->uuid . '/preview-sessions', ['overlay' => $overlay, 'locale' => 'vi'])
            ->assertCreated()
            ->assertJsonPath('diagnostics.canonical_route.name', 'home')
            ->assertJsonPath('diagnostics.target_type', null)
            ->assertJsonPath('diagnostics.target_id', null);

        $url = (string) $minted->json('preview_url');
        $this->assertSame('/', parse_url($url, PHP_URL_PATH) ?? '/');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];
        $consumed = app(WidgetPreviewSessionService::class)->consume($token, self::PROJECT, 'home', []);
        $this->assertTrue($consumed->isSuccess());
        $this->assertCount(1, $consumed->context->overlayPlacements);

        // A site without a home page previews its first published page, never an unpublished one.
        $routes = new RouteCollection();
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getName() !== 'home') {
                $routes->add($route);
            }
        }
        app('router')->setRoutes($routes);
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertFalse(Route::has('home'));

        $this->actingAs($this->user, 'sanctum')
            ->postJson(self::BASE . $this->layout->uuid . '/preview-sessions', ['overlay' => [], 'locale' => 'vi'])
            ->assertCreated()
            ->assertJsonPath('diagnostics.target_type', self::TARGET_TYPE)
            ->assertJsonPath('diagnostics.target_id', 'page-1')
            ->assertJsonPath('diagnostics.canonical_route.name', self::ROUTE_NAME);
    }

    public function test_untargeted_preview_request_rules(): void
    {
        $mint = fn (array $body, ?User $user = null): TestResponse => $this->actingAs($user ?? $this->user, 'sanctum')
            ->postJson(self::BASE . $this->layout->uuid . '/preview-sessions', $body + ['overlay' => [], 'locale' => 'vi']);

        // A half target is rejected, including a page type without a page.
        $mint(['target_type' => self::TARGET_TYPE])->assertStatus(422);
        $mint(['target_id' => 'page-1'])->assertStatus(422);
        $mint(['template_context' => self::TEMPLATE])->assertStatus(422);

        // The preview permission still applies without a target.
        $viewer = User::forceCreate(['name' => 'Viewer', 'email' => 'viewer-' . uniqid('', true) . '@test.local', 'password' => bcrypt('x'), 'type' => UserType::ADMIN]);
        $mint([], $viewer)->assertForbidden();
    }

    public function test_clone_repin_of_shared_instance_is_one_change(): void
    {
        $this->widget->forceFill(['origin' => Widget::ORIGIN_HUB])->save();
        $clone = Widget::query()->create(['name' => 'Area Widget (bản riêng)', 'description' => '', 'js' => '', 'cloned_from_widget_key' => $this->widget->fresh()->widget_key]);
        $cloneTheme = WidgetTheme::query()->create([
            'widget_id' => $clone->id,
            'name' => 'Area Theme',
            'content' => '<div class="card clone">{title}</div>',
            'css' => '.card { color: blue; }',
            'project_name' => [self::PROJECT],
        ]);
        $shared = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $this->user->id, 'legacy-shared-hub', [
            'name' => 'Legacy shared',
            'widget_id' => $this->widget->id,
            'widget_theme_id' => $this->theme->id,
            'values' => ['title' => 'Shared'],
        ])->instance;
        $row = ['widget_instance_uuid' => $shared->uuid, 'widget_instance_version' => 1, 'slot_key' => self::SLOT, 'scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'enabled' => true];
        $this->saveDraft([$row + ['position' => 0], $row + ['position' => 1]]);
        $this->publish();

        // What RepinPlacementsToClone does for each placement: move it to the clone (the shared instance forks).
        $draft = $this->draft();
        foreach (WidgetPlacement::query()->where('layout_version_id', $draft->id)->orderBy('position')->pluck('uuid') as $i => $uuid) {
            $result = app(WidgetPlacementContentService::class)->updateContent(
                self::PROJECT, (string) $this->layout->uuid, (string) $uuid, (int) $this->user->id, 'clone-' . $i,
                (int) $this->draft()->lock_version, ['title' => 'Shared'], (int) $cloneTheme->id, (int) $clone->id,
            );
            $this->assertNotNull($result);
            if ($i === 0) {
                break; // one placement moved, the other still on the hub widget
            }
        }

        $changes = $this->changes();
        $this->assertCount(1, $changes, 'A clone repin is one change, not removed + added.');
        $this->assertSame('changed', $changes[0]['kind']);
        $this->assertSame('Area Widget (bản riêng)', $changes[0]['widget_name']);
    }

    public function test_reorder_of_two_versions_of_one_instance_is_not_a_change(): void
    {
        $shared = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $this->user->id, 'legacy-two-versions', [
            'name' => 'Legacy two versions',
            'widget_id' => $this->widget->id,
            'widget_theme_id' => $this->theme->id,
            'values' => ['title' => 'V1'],
        ])->instance;
        app(WidgetInstanceService::class)->update(self::PROJECT, (string) $shared->uuid, (int) $this->user->id, 'legacy-two-versions-2', 1, [
            'widget_id' => $this->widget->id,
            'widget_theme_id' => $this->theme->id,
            'values' => ['title' => 'V2'],
        ]);
        $this->assertSame(2, (int) $shared->fresh()->currentVersion->version);
        $row = ['widget_instance_uuid' => $shared->uuid, 'slot_key' => self::SLOT, 'scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'enabled' => true];
        $this->saveDraft([$row + ['widget_instance_version' => 1, 'position' => 0], $row + ['widget_instance_version' => 2, 'position' => 1]]);
        $this->publish();

        $this->saveDraft([$row + ['widget_instance_version' => 2, 'position' => 0], $row + ['widget_instance_version' => 1, 'position' => 1]]);

        $this->assertSame([], $this->changes());
    }

    public function test_scope_wording_reaches_existing_sites(): void
    {
        DB::table('translates')->where('name', 'widget_site_layout.scope.target')->delete();
        DB::table('translates')->insert([
            'modules' => json_encode(['Widget']),
            'group_name' => 'widget_site_layout',
            'name' => 'widget_site_layout.scope.target',
            'text' => json_encode(['en' => 'Specific page', 'vi' => 'Riêng trang cụ thể']),
            'for_web' => 0,
            'for_adm' => 1,
            'for_app' => 0,
            'status' => 'status_published',
            'created_at' => '2026-07-25 22:51:12',
            'updated_at' => '2026-07-25 22:51:12',
        ]);

        (new TranslationModuleSeederImporter())->importFromModule('Widget', base_path('cms/Widget'));

        $text = json_decode((string) DB::table('translates')->where('name', 'widget_site_layout.scope.target')->value('text'), true);
        $this->assertSame('Riêng trang này', $text['vi']);
    }

    public function test_area_count_reported(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/admin/widget/slots')
            ->assertOk()
            ->assertJsonPath('meta.area_count', 1)
            ->assertJsonCount(1, 'data');
    }
}
