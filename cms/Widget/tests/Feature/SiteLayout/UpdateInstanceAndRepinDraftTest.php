<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\WidgetDataProvider;
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
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\Rendering\DataProviders\WidgetProviderDeclaration;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraftResult;
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
 * Chunk 2 Task 18: service-level proof for {@see UpdateInstanceAndRepinDraft}
 * (lines
 * 1053-1080; architecture.md "Instance create, clone, and update":
 * "'Update shared instance and repin this draft' belongs to a callable
 * WidgetLayoutOrchestrator endpoint after both services exist").
 *
 * Exercises the orchestrator DIRECTLY (no HTTP/routing/FormRequest layer --
 * that is {@see \Cms\Widget\Tests\Feature\Http\WidgetInstanceDraftRepinApiTest}'s
 * job) so the transaction/locking/idempotency/registry-recompute semantics
 * can be proven with minimal per-test boilerplate, mirroring this module's
 * established Feature/SiteLayout convention
 * ({@see \Cms\Widget\Tests\Feature\SiteLayout\WidgetSlotFallbackTest}).
 *
 * `usageFingerprint()` below is a DELIBERATE, DISCLOSED duplicate of the
 * identical private formula inside {@see UpdateInstanceAndRepinDraft} --
 * the fingerprint is an opaque server-computed comparison token (per the
 * coordinator brief: "never trust or parse a client value beyond
 * equality"), so a caller (this test, standing in for a real future
 * base-admin client) can only ever supply a value it independently
 * recomputes from client-visible identity (Draft version/lock_version,
 * Instance current version, sorted matching Placement uuids) -- never one
 * it reads back from the server. If the production formula ever changes,
 * this copy must change with it.
 */
final class UpdateInstanceAndRepinDraftTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.repin.slot';

    private string $storageRoot;

    /** @var array<string, string|null> class-level snapshot: see setUpBeforeClass()'s docblock. */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Array-keyed snapshot shape (proven-safe precedent:
     * {@see \Cms\Widget\Tests\Feature\Http\WidgetInstanceApiTest::$sharedFileSnapshots}) --
     * NOT a nullable scalar, which Task 17's own closure notes recorded as
     * an active double-restore-deletion hazard.
     */
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

        $this->storageRoot = storage_path('framework/testing/widget-repin-' . bin2hex(random_bytes(6)));

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
            labelKey: 'test.repin.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // PHPUnit runs under the cli SAPI: WidgetInstanceService's/this
        // orchestrator's own runVersionCreatingTransaction() therefore
        // routes through $this->barrier->transition() rather than the live
        // HTTP complementary window, mirroring WidgetInstanceApiTest's own
        // setUp() precedent/reasoning.
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

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Repin Tester',
            'email' => 'repin-tester-' . uniqid('', true) . '@test.local',
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
            'widget_layout.update',
            'widget_template.approve',
        ]);
    }

    /**
     * Materializes AND approves a Manifest for (widget, theme) inside
     * self::PROJECT via the real HTTP materialize endpoint plus
     * {@see WidgetManifestApprovalService}, mirroring
     * WidgetInstanceApiTest::approveManifestFor()/WidgetLayoutDraftApiTest::approveManifestFor().
     */
    private function approveManifestFor(Widget $widget, WidgetTheme $theme, User $approver): void
    {
        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();

        app(WidgetManifestApprovalService::class)->approve(
            self::PROJECT,
            $response->json('data.manifest_hash'),
            1,
            (int) $approver->id,
            'test approval',
        );
    }

    /**
     * Creates a ready (approved-manifest-backed) Instance Version 1 through
     * the real {@see WidgetInstanceService}, returning the persisted model.
     */
    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user, string $name = 'Shared Instance'): WidgetInstance
    {
        $this->approveManifestFor($widget, $theme, $user);

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => $name,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ],
        );

        return $result->instance;
    }

    /**
     * @return array{layout: WidgetLayout, draft: WidgetLayoutVersion}
     */
    private function createLayoutWithDraft(string $key = 'site'): array
    {
        $layout = WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => $key]);
        $draft = WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'state' => WidgetLayoutVersion::STATE_DRAFT,
            'lock_version' => 0,
            'registry_contracts' => [],
            'registry_contract_hash' => hash('sha256', '[]'),
        ]);
        $layout->current_draft_version_id = $draft->id;
        $layout->save();

        return ['layout' => $layout->fresh(), 'draft' => $draft->fresh()];
    }

    private function createPlacement(WidgetLayoutVersion $draft, WidgetInstanceVersion $version, string $slotKey = self::SLOT, int $position = 0): WidgetPlacement
    {
        return WidgetPlacement::query()->create([
            'project_name' => self::PROJECT,
            'layout_version_id' => $draft->id,
            'widget_instance_version_id' => $version->id,
            'slot_key' => $slotKey,
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => $position,
            'enabled' => true,
        ]);
    }

    /**
     * DELIBERATE DUPLICATE of {@see UpdateInstanceAndRepinDraft}'s own
     * private fingerprint formula -- see class docblock.
     *
     * @param list<string> $matchingPlacementUuids
     */
    private function usageFingerprint(WidgetLayoutVersion $draft, ?int $instanceCurrentVersion, array $matchingPlacementUuids): string
    {
        sort($matchingPlacementUuids);

        return CanonicalJson::hash([
            'draft_version' => $draft->version,
            'draft_lock_version' => $draft->lock_version,
            'instance_current_version' => $instanceCurrentVersion,
            'matching_placement_uuids' => $matchingPlacementUuids,
        ]);
    }

    private function orchestrator(): UpdateInstanceAndRepinDraft
    {
        return app(UpdateInstanceAndRepinDraft::class);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_repin_creates_a_new_version_and_repins_only_matching_placements_in_the_named_draft(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        $otherWidget = $this->createWidget();
        $otherTheme = $this->createTheme($otherWidget);
        $other = $this->createReadyInstance($otherWidget, $otherTheme, $user, 'Other Instance');

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $matching = $this->createPlacement($draft, $instance->currentVersion, self::SLOT, 0);
        $unrelated = $this->createPlacement($draft, $other->currentVersion, self::SLOT, 1);

        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$matching->uuid]);

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            WidgetLayout::query()->find($draft->layout_id)->uuid,
            $instance->uuid,
            (int) $user->id,
            'repin-key-1',
            $instance->currentVersion->version,
            $draft->version,
            $draft->lock_version,
            $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Repinned'], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $result->status);
        $this->assertSame(2, $result->instance->currentVersion->version);
        $this->assertSame('Repinned', $result->instance->currentVersion->values['title']);

        $matching->refresh();
        $unrelated->refresh();
        $this->assertSame($result->instance->current_version_id, $matching->widget_instance_version_id, 'The matching placement must now point at the new Version.');
        $this->assertSame($other->current_version_id, $unrelated->widget_instance_version_id, 'A placement referencing a DIFFERENT instance must never be touched.');

        $this->assertSame(1, $result->draft->lock_version, 'Draft lock_version must increment exactly once.');
        $this->assertSame(2, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
    }

    public function test_repin_does_not_touch_a_placement_pinned_to_an_older_version_of_a_different_instance(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $before = WidgetPlacement::query()->count();

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-key-empty',
            $instance->currentVersion->version,
            $draft->version,
            $draft->lock_version,
            $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $result->status);
        $this->assertSame($before, WidgetPlacement::query()->count(), 'Repin must never insert or delete Placement rows, only repoint widget_instance_version_id.');
    }

    // -----------------------------------------------------------------
    // Stale expected state: nothing is inserted or changed
    // -----------------------------------------------------------------

    public function test_repin_returns_draft_stale_and_inserts_nothing_when_expected_draft_lock_version_is_wrong(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-key-stale-draft',
            $instance->currentVersion->version,
            $draft->version,
            $draft->lock_version + 1, // wrong
            $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_DRAFT_STALE, $result->status);
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'A stale draft check must insert no Version.');
        $placement->refresh();
        $this->assertSame($instance->current_version_id, $placement->widget_instance_version_id);
        $draft->refresh();
        $this->assertSame(0, $draft->lock_version, 'A stale draft check must not increment lock_version.');
    }

    public function test_repin_returns_usage_changed_and_inserts_nothing_when_expected_current_instance_version_is_wrong(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-key-stale-instance',
            999, // wrong
            $draft->version,
            $draft->lock_version,
            $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_USAGE_CHANGED, $result->status);
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
        $draft->refresh();
        $this->assertSame(0, $draft->lock_version);
    }

    public function test_repin_returns_usage_changed_and_inserts_nothing_when_the_usage_fingerprint_does_not_match(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-key-bad-fingerprint',
            $instance->currentVersion->version,
            $draft->version,
            $draft->lock_version,
            'not-a-real-fingerprint',
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_USAGE_CHANGED, $result->status);
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
        $placement->refresh();
        $this->assertSame($instance->current_version_id, $placement->widget_instance_version_id);
    }

    // -----------------------------------------------------------------
    // Not found
    // -----------------------------------------------------------------

    public function test_repin_returns_layout_not_found_for_an_unknown_layout_uuid(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            '00000000-0000-0000-0000-000000000000',
            $instance->uuid,
            (int) $user->id,
            'repin-key-unknown-layout',
            1,
            1,
            0,
            'irrelevant',
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_LAYOUT_NOT_FOUND, $result->status);
    }

    public function test_repin_returns_instance_not_found_for_an_unknown_instance_uuid(): void
    {
        $user = $this->fullAccessUser();
        ['draft' => $draft] = $this->createLayoutWithDraft();
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            '00000000-0000-0000-0000-000000000000',
            (int) $user->id,
            'repin-key-unknown-instance',
            1,
            $draft->version,
            $draft->lock_version,
            'irrelevant',
            ['widget_id' => 1, 'widget_theme_id' => 1, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_INSTANCE_NOT_FOUND, $result->status);
    }

    // -----------------------------------------------------------------
    // Manifest gate: pending approval creates neither Version nor repin
    // -----------------------------------------------------------------

    public function test_repin_without_an_acting_user_returns_approval_required_and_creates_nothing_when_the_new_pairings_manifest_is_pending(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        // A second, distinct pairing that has been materialized but never
        // approved. DELIBERATELY different js/content/css from
        // createTheme()'s default: the manifest identity is
        // (project_name, manifest_hash) over BYTES, not widget/theme row
        // ids (WidgetLegacyManifestMaterializer's own docblock), so a
        // byte-identical pairing would silently reuse -- and thus already
        // be covered by -- the FIRST pairing's just-approved manifest row.
        $pendingWidget = $this->createWidget();
        $pendingTheme = WidgetTheme::query()->create([
            'widget_id' => $pendingWidget->id,
            'name' => 'Pending Theme',
            'content' => '<div class="pending">{title}</div>',
            'css' => '.pending { color: blue; }',
            'project_name' => [self::PROJECT],
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $pendingWidget->id,
            'widget_theme_id' => $pendingTheme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($pendingWidget, $pendingTheme),
        ])->assertSuccessful();

        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            // No acting user: nothing approves on anyone's behalf, so the gate still refuses.
            // With a user the manifest is approved automatically (WidgetManifestAutoApprovalTest).
            null,
            'repin-key-pending',
            $instance->currentVersion->version,
            $draft->version,
            $draft->lock_version,
            $fingerprint,
            ['widget_id' => $pendingWidget->id, 'widget_theme_id' => $pendingTheme->id, 'values' => [], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_APPROVAL_REQUIRED, $result->status);
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'Pending approval must create no Version.');
        $placement->refresh();
        $this->assertSame($instance->current_version_id, $placement->widget_instance_version_id, 'Pending approval must repin nothing.');
        $draft->refresh();
        $this->assertSame(0, $draft->lock_version, 'Pending approval must not touch the Draft lock.');
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_repin_replays_the_same_result_for_a_repeated_idempotency_key(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $input = ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Once'], 'provider_contract' => []];

        $first = $this->orchestrator()->handle(self::PROJECT, $layoutUuid, $instance->uuid, (int) $user->id, 'repeat-key', $instance->currentVersion->version, $draft->version, $draft->lock_version, $fingerprint, $input);
        $second = $this->orchestrator()->handle(self::PROJECT, $layoutUuid, $instance->uuid, (int) $user->id, 'repeat-key', $instance->currentVersion->version, $draft->version, $draft->lock_version, $fingerprint, $input);

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $first->status);
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $second->status);
        $this->assertSame($first->instance->currentVersion->version, $second->instance->currentVersion->version);
        $this->assertSame(2, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'A replay must insert exactly one Version, not two.');
    }

    public function test_repin_returns_idempotency_mismatch_for_the_same_key_with_a_different_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, $instance->currentVersion->version, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $this->orchestrator()->handle(self::PROJECT, $layoutUuid, $instance->uuid, (int) $user->id, 'conflict-key', $instance->currentVersion->version, $draft->version, $draft->lock_version, $fingerprint, ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'A'], 'provider_contract' => []]);

        $second = $this->orchestrator()->handle(self::PROJECT, $layoutUuid, $instance->uuid, (int) $user->id, 'conflict-key', $instance->currentVersion->version, $draft->version, $draft->lock_version, $fingerprint, ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'B'], 'provider_contract' => []]);

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_IDEMPOTENCY_CONFLICT, $second->status);
    }

    // -----------------------------------------------------------------
    // Standalone update vs. repin, and two repins sharing one Instance:
    // both allocate strictly sequential versions from the same MAX(version)
    // sequence (functional proof; the dual-engine/real-concurrency race
    // matrix is the chunk exit gate's job, per the coordinator brief).
    // -----------------------------------------------------------------

    public function test_a_standalone_update_followed_by_a_repin_allocate_sequential_versions(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        app(WidgetInstanceService::class)->update(
            self::PROJECT,
            $instance->uuid,
            (int) $user->id,
            'standalone-update-key',
            1,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Standalone'], 'provider_contract' => []],
        );

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $instance = $instance->fresh();
        $this->assertSame(2, $instance->currentVersion->version, 'Sanity: standalone update must land Version 2 first.');

        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $fingerprint = $this->usageFingerprint($draft, 2, [$placement->uuid]);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        $result = $this->orchestrator()->handle(self::PROJECT, $layoutUuid, $instance->uuid, (int) $user->id, 'repin-after-standalone', 2, $draft->version, $draft->lock_version, $fingerprint, ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Repinned'], 'provider_contract' => []]);

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $result->status);
        $this->assertSame(3, $result->instance->currentVersion->version, 'Repin must allocate the next sequential Version after the standalone update.');
        $this->assertSame(3, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
    }

    public function test_two_repins_on_two_layouts_sharing_one_instance_allocate_distinct_sequential_versions(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draftA] = $this->createLayoutWithDraft('site-a');
        ['draft' => $draftB] = $this->createLayoutWithDraft('site-b');
        $placementA = $this->createPlacement($draftA, $instance->currentVersion);
        $placementB = $this->createPlacement($draftB, $instance->currentVersion);
        $layoutUuidA = WidgetLayout::query()->find($draftA->layout_id)->uuid;
        $layoutUuidB = WidgetLayout::query()->find($draftB->layout_id)->uuid;

        $fingerprintA = $this->usageFingerprint($draftA, 1, [$placementA->uuid]);
        $resultA = $this->orchestrator()->handle(self::PROJECT, $layoutUuidA, $instance->uuid, (int) $user->id, 'repin-a', 1, $draftA->version, $draftA->lock_version, $fingerprintA, ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'A'], 'provider_contract' => []]);
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $resultA->status);
        $this->assertSame(2, $resultA->instance->currentVersion->version);

        $fingerprintB = $this->usageFingerprint($draftB, 2, [$placementB->uuid]);
        $resultB = $this->orchestrator()->handle(self::PROJECT, $layoutUuidB, $instance->uuid, (int) $user->id, 'repin-b', 2, $draftB->version, $draftB->lock_version, $fingerprintB, ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'B'], 'provider_contract' => []]);
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $resultB->status);
        $this->assertSame(3, $resultB->instance->currentVersion->version, "The second Layout's repin must allocate the NEXT version, not collide with the first.");

        $placementA->refresh();
        $placementB->refresh();
        $this->assertSame($resultA->instance->current_version_id, $placementA->widget_instance_version_id);
        $this->assertNotSame($placementA->widget_instance_version_id, $placementB->widget_instance_version_id);
        $this->assertSame(3, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
    }

    // -----------------------------------------------------------------
    // Registry hash: provider-contract changes refresh it, values-only
    // (label-only) changes do not.
    // -----------------------------------------------------------------

    public function test_repin_with_a_different_provider_contract_refreshes_the_draft_registry_hash(): void
    {
        app(WidgetDataProviderRegistry::class)->register(
            new class implements WidgetDataProvider {
                public function key(): string
                {
                    return 'test.repin.provider';
                }

                public function version(): int
                {
                    return 1;
                }

                public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult
                {
                    return new WidgetProviderResult();
                }
            },
            new WidgetProviderDeclaration(key: 'test.repin.provider', version: 1),
        );

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;
        $hashBefore = $draft->registry_contract_hash;

        $fingerprint = $this->usageFingerprint($draft, 1, [$placement->uuid]);
        $result = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-provider-change',
            1,
            $draft->version,
            $draft->lock_version,
            $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [], 'provider_contract' => [['key' => 'test.repin.provider', 'version' => 1]]],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $result->status);
        $this->assertNotSame($hashBefore, $result->draft->registry_contract_hash, 'A provider reference introduced by the new Version must refresh the registry hash.');
    }

    public function test_repin_with_only_a_values_change_does_not_alter_the_draft_registry_hash(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $layoutUuid = WidgetLayout::query()->find($draft->layout_id)->uuid;

        // Baseline: createLayoutWithDraft() seeds registry_contract_hash
        // with an arbitrary placeholder (mirrors WidgetInstanceApiTest::
        // placeInstanceVersion()'s own precedent), never a REAL computed
        // snapshot -- so the meaningful comparison is REPIN versus REPIN
        // (only `values` differing between them), not repin versus that
        // placeholder.
        $fingerprintOne = $this->usageFingerprint($draft, 1, [$placement->uuid]);
        $first = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-values-only-1',
            1,
            $draft->version,
            $draft->lock_version,
            $fingerprintOne,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'First'], 'provider_contract' => []],
        );
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $first->status);
        $hashAfterFirst = $first->draft->registry_contract_hash;

        $placement->refresh();
        $fingerprintTwo = $this->usageFingerprint($first->draft, 2, [$placement->uuid]);
        $second = $this->orchestrator()->handle(
            self::PROJECT,
            $layoutUuid,
            $instance->uuid,
            (int) $user->id,
            'repin-values-only-2',
            2,
            $first->draft->version,
            $first->draft->lock_version,
            $fingerprintTwo,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Second - only the label changed'], 'provider_contract' => []],
        );

        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $second->status);
        $this->assertSame($hashAfterFirst, $second->draft->registry_contract_hash, 'A values-only change between two repins must never move the registry hash (same slot/provider reference set).');
    }

    // -----------------------------------------------------------------
    // Standalone PATCH does not repin; Draft PUT does not mutate Instance
    // (existing Task 16/17 invariants, proven again from this suite's own
    // fixtures per the plan's explicit DoD line).
    // -----------------------------------------------------------------

    public function test_standalone_instance_update_never_touches_any_placement(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);

        app(WidgetInstanceService::class)->update(
            self::PROJECT,
            $instance->uuid,
            (int) $user->id,
            'standalone-only-key',
            1,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['title' => 'Standalone Only'], 'provider_contract' => []],
        );

        $placement->refresh();
        $this->assertSame($instance->current_version_id, $placement->widget_instance_version_id, 'Standalone PATCH Instance must never repin any Draft.');
    }
}
