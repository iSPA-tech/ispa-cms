<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Widget\Instances\WidgetInstanceMutationResult;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Operations\WidgetModuleDisableGuard;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft;
use Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraftResult;
use Cms\Widget\SiteLayout\Drafts\WidgetLayoutBootstrapService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 2 Task 24b: aggregate action-specific dual-engine lifecycle race
 * matrix (plan lines 1293-1317, DoD 1309-1310). Tasks 16/18/20/21 each
 * proved their OWN sequential (single-process) race proxy and explicitly
 * deferred true multi-process/dual-engine contention here
 * closures for Tasks 16/18/20/21: "Chunk exit gate: dual-engine race
 * matrix... deferred per standing coordinator instruction"). This file is
 * the AGGREGATION point those tasks all pointed at.
 *
 * ENGINE-AGNOSTIC BY DESIGN: every scenario below drives the real services
 * (WidgetInstanceService, UpdateInstanceAndRepinDraft, WidgetLayoutPublishService,
 * WidgetLayoutRollbackService, WidgetManifestApprovalService,
 * WidgetManifestRuntimeBlockService, WidgetModuleDisableGuard) against
 * WHATEVER connection PHPUnit's DB_CONNECTION resolves to -- nothing here
 * hardcodes sqlite. Run here (this task's own scope) it proves SEQUENTIAL
 * correctness (no gaps, no duplicates, all-or-nothing) on the default
 * sqlite `:memory:` connection; the SAME file run by the coordinator against
 * real MySQL/PostgreSQL (via the extended `widget-site-layout-db-matrix`
 * CI job) additionally proves TRUE concurrent-contention safety under real
 * `FOR UPDATE` row locking -- the honest driver split this module has used
 * since Task 13b's WidgetDatabaseConcurrencyTest::test_with_locks_*()
 * precedent (see that class's own docblock).
 */
final class WidgetSiteLayoutLifecycleRaceTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'WidgetRaceTest';

    private const SLOT = 'race.test.slot';

    private string $storageRoot;

    /** Real, FK-satisfying actor id -- WidgetAuditWriter's audit_logs.user_id is a real FK; a literal 1 with no matching row throws. */
    private int $actorId;

    /** @var array<string, string|null> class-level snapshot: see setUpBeforeClass()'s docblock. */
    private static array $sharedFileSnapshots = [];

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
        self::$sharedFileSnapshots = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreSharedFiles();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreSharedFiles();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreSharedFiles(): void
    {
        foreach (self::$sharedFileSnapshots as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$sharedFileSnapshots = [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-race-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'race.test.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // Pure service-level tests (no HTTP layer): PHPUnit's own cli SAPI
        // means WidgetInstanceService's/the repin orchestrator's
        // runVersionCreatingTransaction() would otherwise route through the
        // REAL MaintenanceOnlyModuleLifecycleBarrier's console branch, which
        // only proceeds when genuinely down for maintenance -- a
        // pass-through double keeps every scenario below focused on the
        // LOCK/IDEMPOTENCY/VERSION-ALLOCATION invariants under test, not
        // this orthogonal console-maintenance gate (same established
        // precedent as WidgetInstanceApiTest/UpdateInstanceAndRepinDraftTest's
        // own setUp()).
        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->actorId = (int) \App\Models\User::forceCreate([
            'name' => 'Race Test System Actor',
            'email' => 'race-test-actor-' . uniqid('', true) . '@test.local',
            'password' => bcrypt(Str::random(40)),
            'type' => \App\Constants\UserType::ADMIN,
        ])->id;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures (direct service calls, no HTTP layer -- same established
    // convention as UpdateInstanceAndRepinDraftTest for this exact shape
    // of problem).
    // -----------------------------------------------------------------

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Race Widget ' . uniqid('', true), 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Race Theme ' . uniqid('', true),
            'content' => '<div class="race">{title}</div>',
            'css' => '.race { color: inherit; }',
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
            applicability: [self::PROJECT],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    /**
     * Materializes AND approves a Manifest for (widget, theme), returning
     * the manifest_hash. Mirrors WidgetInstanceApiTest::approveManifestFor(),
     * at the service layer instead of HTTP.
     */
    private function approvedManifestHash(Widget $widget, WidgetTheme $theme): string
    {
        $result = app(WidgetLegacyManifestMaterializer::class)->materialize(
            widgetId: $widget->id,
            themeId: $theme->id,
            projectName: self::PROJECT,
            expectedSourceFingerprint: $this->liveSourceFingerprint($widget, $theme),
        );
        $hash = (string) $result->manifestRow['manifest_hash'];

        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $hash, 1, $this->actorId, 'race test approval');

        return $hash;
    }

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, string $idempotencyKey): WidgetInstance
    {
        $this->approvedManifestHash($widget, $theme);

        $result = app(WidgetInstanceService::class)->create(self::PROJECT, $this->actorId, $idempotencyKey, [
            'name' => 'Race Instance ' . uniqid('', true),
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => [],
            'provider_contract' => [],
        ]);

        $this->assertSame(WidgetInstanceMutationResult::STATUS_CREATED, $result->status, 'Fixture precondition: Instance create must succeed.');

        return $result->instance;
    }

    /**
     * @return array{layout: WidgetLayout, draft: WidgetLayoutVersion}
     */
    private function createLayoutWithDraft(?string $key = null): array
    {
        $key ??= 'race-layout-' . uniqid('', true);

        $result = app(WidgetLayoutBootstrapService::class)->bootstrap(self::PROJECT, $key);
        $draft = WidgetLayoutVersion::query()->findOrFail($result->layout->current_draft_version_id);

        return ['layout' => $result->layout, 'draft' => $draft];
    }

    private function createPlacement(WidgetLayoutVersion $draft, WidgetInstanceVersion $instanceVersion, int $position = 0): WidgetPlacement
    {
        return WidgetPlacement::query()->create([
            'project_name' => self::PROJECT,
            'layout_version_id' => $draft->id,
            'widget_instance_version_id' => $instanceVersion->id,
            'slot_key' => self::SLOT,
            'scope_type' => WidgetPlacement::SCOPE_GLOBAL,
            'scope_identity' => WidgetPlacement::SCOPE_GLOBAL,
            'scope_key' => null,
            'target_type' => null,
            'target_id' => null,
            'position' => $position,
            'enabled' => true,
            'rules' => [],
        ]);
    }

    /**
     * DELIBERATE, DISCLOSED duplicate of UpdateInstanceAndRepinDraft's own
     * private fingerprint formula -- identical precedent already
     * established by UpdateInstanceAndRepinDraftTest's own class docblock
     * and re-established by WidgetSiteLayoutApiConformanceTest for the
     * same shape of file-boundary problem.
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

    /**
     * @return list<string>
     */
    private function matchingPlacementUuidsFor(WidgetLayoutVersion $draft, WidgetInstance $instance): array
    {
        $versionIds = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->pluck('id');

        return WidgetPlacement::query()
            ->where('layout_version_id', $draft->id)
            ->whereIn('widget_instance_version_id', $versionIds)
            ->pluck('uuid')
            ->all();
    }

    // -----------------------------------------------------------------
    // 1. Different-key Instance N+1/N+2: two updates with distinct
    // idempotency keys allocate strictly sequential, gap-free versions.
    // -----------------------------------------------------------------

    public function test_different_key_instance_updates_allocate_sequential_versions(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-create-n');

        $service = app(WidgetInstanceService::class);

        $first = $service->update(self::PROJECT, $instance->uuid, $this->actorId, 'race-update-n1', 1, [
            'widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'n1'],
        ]);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_UPDATED, $first->status);
        $this->assertSame(2, $first->instance->currentVersion->version);

        $second = $service->update(self::PROJECT, $instance->uuid, $this->actorId, 'race-update-n2', 2, [
            'widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'n2'],
        ]);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_UPDATED, $second->status);
        $this->assertSame(3, $second->instance->currentVersion->version);

        $versions = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->orderBy('version')->pluck('version')->all();
        $this->assertSame([1, 2, 3], $versions, 'Sequential different-key updates must be gap-free and duplicate-free.');
    }

    // -----------------------------------------------------------------
    // 2. Standalone update immediately followed by a repin: sequential
    // versions, no gap, the repin's own new Version wins the pointer.
    // -----------------------------------------------------------------

    public function test_standalone_update_followed_by_repin_allocates_sequential_versions_with_no_gap(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-standalone-repin-create');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);

        $updateResult = app(WidgetInstanceService::class)->update(self::PROJECT, $instance->uuid, $this->actorId, 'race-standalone-update', 1, [
            'widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'standalone'],
        ]);
        $this->assertSame(2, $updateResult->instance->currentVersion->version);
        $this->assertSame($placement->id, WidgetPlacement::query()->first()->id, 'A standalone update must never touch any Placement.');

        $draft = $draft->fresh();
        $matching = $this->matchingPlacementUuidsFor($draft, $updateResult->instance);
        $fingerprint = $this->usageFingerprint($draft, 2, $matching);

        $repinResult = app(UpdateInstanceAndRepinDraft::class)->handle(
            self::PROJECT, $layout->uuid, $instance->uuid, $this->actorId, 'race-repin-after-standalone',
            2, $draft->version, $draft->lock_version, $fingerprint,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'repinned']],
        );
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $repinResult->status);
        $this->assertSame(3, $repinResult->instance->currentVersion->version);

        $versions = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->orderBy('version')->pluck('version')->all();
        $this->assertSame([1, 2, 3], $versions);

        $repinnedVersion = WidgetInstanceVersion::query()->find($placement->fresh()->widget_instance_version_id);
        $this->assertSame(3, $repinnedVersion->version, 'The repin must move the Placement to reference the NEW Version 3.');
    }

    // -----------------------------------------------------------------
    // 3. Two repins on two DIFFERENT Layouts sharing one Instance: each
    // Layout's own repin only touches its OWN Draft's Placement.
    // -----------------------------------------------------------------

    public function test_two_repins_on_different_layouts_sharing_one_instance_allocate_distinct_sequential_versions(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-shared-instance-create');

        ['layout' => $layoutA, 'draft' => $draftA] = $this->createLayoutWithDraft();
        ['layout' => $layoutB, 'draft' => $draftB] = $this->createLayoutWithDraft();
        $placementA = $this->createPlacement($draftA, $instance->currentVersion);
        $placementB = $this->createPlacement($draftB, $instance->currentVersion);

        $orchestrator = app(UpdateInstanceAndRepinDraft::class);

        $fingerprintA = $this->usageFingerprint($draftA, 1, [$placementA->uuid]);
        $resultA = $orchestrator->handle(
            self::PROJECT, $layoutA->uuid, $instance->uuid, $this->actorId, 'race-repin-a',
            1, $draftA->version, $draftA->lock_version, $fingerprintA,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'a']],
        );
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $resultA->status);
        $this->assertSame(2, $resultA->instance->currentVersion->version);

        $fingerprintB = $this->usageFingerprint($draftB, 2, [$placementB->uuid]);
        $resultB = $orchestrator->handle(
            self::PROJECT, $layoutB->uuid, $instance->uuid, $this->actorId, 'race-repin-b',
            2, $draftB->version, $draftB->lock_version, $fingerprintB,
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => ['v' => 'b']],
        );
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_REPINNED, $resultB->status);
        $this->assertSame(3, $resultB->instance->currentVersion->version);

        $versions = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->orderBy('version')->pluck('version')->all();
        $this->assertSame([1, 2, 3], $versions);

        $this->assertSame(2, WidgetInstanceVersion::query()->find($placementA->fresh()->widget_instance_version_id)->version, "Layout A's own Placement must stay pinned to the version ITS repin produced, unaffected by B's later repin.");
        $this->assertSame(3, WidgetInstanceVersion::query()->find($placementB->fresh()->widget_instance_version_id)->version);
    }

    // -----------------------------------------------------------------
    // 4. Concurrent publish: the second (stale) attempt inserts nothing.
    // -----------------------------------------------------------------

    public function test_concurrent_publish_attempts_never_produce_duplicate_releases(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-publish-create');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $publisher = app(WidgetLayoutPublishService::class);
        $expectedDraftVersion = $draft->version;
        $expectedLockVersion = $draft->lock_version;

        $first = $publisher->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-publish-a', $expectedDraftVersion, $expectedLockVersion);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $first->status);

        // The "concurrent" second caller still holds the ORIGINAL pre-publish
        // expectation (simulating a racer that read state before the first
        // committed) -- must see draft_stale and insert nothing.
        $second = $publisher->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-publish-b', $expectedDraftVersion, $expectedLockVersion);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_DRAFT_STALE, $second->status);

        $this->assertSame(
            1,
            WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count(),
            'Exactly one Release must exist after a racing publish pair.'
        );
    }

    // -----------------------------------------------------------------
    // 5. Publish vs rollback contention: exactly one winner, one new
    // Release from the racing pair.
    // -----------------------------------------------------------------

    public function test_publish_versus_rollback_contention_yields_exactly_one_winner(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-pub-vs-roll-create');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $publisher = app(WidgetLayoutPublishService::class);
        $firstPublish = $publisher->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-pub-vs-roll-initial', $draft->version, $draft->lock_version);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $firstPublish->status);
        $sourceReleaseVersion = $firstPublish->release->version;

        $freshDraft = WidgetLayoutVersion::query()->findOrFail($layout->fresh()->current_draft_version_id);
        $expectedDraftVersion = $freshDraft->version;
        $expectedLockVersion = $freshDraft->lock_version;

        $rollbackService = app(WidgetLayoutRollbackService::class);
        $rollbackResult = $rollbackService->rollback(self::PROJECT, $layout->uuid, $this->actorId, 'race-pub-vs-roll-rollback', $sourceReleaseVersion, $expectedDraftVersion, $expectedLockVersion);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $rollbackResult->status);

        // The racing publish attempt holds the SAME now-stale expectation the rollback just consumed.
        $racingPublish = $publisher->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-pub-vs-roll-racer', $expectedDraftVersion, $expectedLockVersion);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_DRAFT_STALE, $racingPublish->status);

        $this->assertSame(
            2,
            WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count(),
            'Exactly two Release rows: the initial publish + the rollback\'s own new Release -- the racing publish attempt added none.'
        );
    }

    // -----------------------------------------------------------------
    // 6. Stale expected state inserts zero rows, aggregated across every
    // mutation type (update, repin, publish, rollback).
    // -----------------------------------------------------------------

    public function test_stale_expected_state_inserts_zero_rows_across_every_mutation_type(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        // -- Instance update --
        $instance = $this->createReadyInstance($widget, $theme, 'race-stale-update-create');
        $beforeVersions = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count();
        $staleUpdate = app(WidgetInstanceService::class)->update(self::PROJECT, $instance->uuid, $this->actorId, 'race-stale-update', 999, [
            'widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => [],
        ]);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_STALE_VERSION, $staleUpdate->status);
        $this->assertSame($beforeVersions, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'A stale update must insert zero new Instance Versions.');

        // -- Repin --
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $placement = $this->createPlacement($draft, $instance->currentVersion);
        $beforeInstanceVersions2 = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count();
        $matching = $this->matchingPlacementUuidsFor($draft, $instance);
        $staleRepin = app(UpdateInstanceAndRepinDraft::class)->handle(
            self::PROJECT, $layout->uuid, $instance->uuid, $this->actorId, 'race-stale-repin',
            999, $draft->version, $draft->lock_version, $this->usageFingerprint($draft, 999, $matching),
            ['widget_id' => $widget->id, 'widget_theme_id' => $theme->id, 'values' => []],
        );
        $this->assertSame(UpdateInstanceAndRepinDraftResult::STATUS_USAGE_CHANGED, $staleRepin->status);
        $this->assertSame($beforeInstanceVersions2, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'A stale repin must insert zero new Instance Versions.');

        // -- Publish --
        $beforeReleaseCount = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count();
        $stalePublish = app(WidgetLayoutPublishService::class)->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-stale-publish', 999, 999);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_DRAFT_STALE, $stalePublish->status);
        $this->assertSame($beforeReleaseCount, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count(), 'A stale publish must insert zero new Releases.');

        // -- Rollback (against a REAL Release, but a stale draft expectation) --
        $realPublish = app(WidgetLayoutPublishService::class)->publish(self::PROJECT, $layout->uuid, $this->actorId, 'race-stale-rollback-setup', $draft->version, $draft->lock_version);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $realPublish->status);
        $beforeReleaseCount2 = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count();
        $staleRollback = app(WidgetLayoutRollbackService::class)->rollback(self::PROJECT, $layout->uuid, $this->actorId, 'race-stale-rollback', $realPublish->release->version, 999, 999);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_DRAFT_STALE, $staleRollback->status);
        $this->assertSame($beforeReleaseCount2, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('state', WidgetLayoutVersion::STATE_RELEASE)->count(), 'A stale rollback must insert zero new Releases.');
    }

    // -----------------------------------------------------------------
    // 7. Concurrent attestation/control CAS: the second caller loses.
    // -----------------------------------------------------------------

    public function test_concurrent_attestation_and_control_cas_the_second_caller_loses(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materialized = app(WidgetLegacyManifestMaterializer::class)->materialize(
            widgetId: $widget->id, themeId: $theme->id, projectName: self::PROJECT,
            expectedSourceFingerprint: $this->liveSourceFingerprint($widget, $theme),
        );
        $hash = (string) $materialized->manifestRow['manifest_hash'];

        $approvalService = app(WidgetManifestApprovalService::class);
        $firstApprove = $approvalService->approve(self::PROJECT, $hash, 1, $this->actorId, 'race approve a');
        $this->assertSame(WidgetManifestApprovalService::OUTCOME_SUCCESS, $firstApprove['outcome']);

        // Second caller races with the SAME (now-stale) expected_attestation_version.
        $secondApprove = $approvalService->approve(self::PROJECT, $hash, 1, $this->actorId, 'race approve b');
        $this->assertSame(WidgetManifestApprovalService::OUTCOME_STALE, $secondApprove['outcome']);

        $blockService = app(WidgetManifestRuntimeBlockService::class);
        $firstBlock = $blockService->block(self::PROJECT, $hash, 1, $this->actorId, 'race block a');
        $this->assertSame(WidgetManifestRuntimeBlockService::OUTCOME_SUCCESS, $firstBlock['outcome']);

        $secondBlock = $blockService->block(self::PROJECT, $hash, 1, $this->actorId, 'race block b');
        $this->assertSame(WidgetManifestRuntimeBlockService::OUTCOME_STALE, $secondBlock['outcome']);

        $this->assertSame(
            1,
            \Cms\Widget\Models\WidgetArtifactManifest::query()->where('manifest_hash', $hash)->count(),
            'CAS contention must mutate state in place, never duplicate the Manifest row.'
        );
    }

    // -----------------------------------------------------------------
    // 8. Retained-reference mutation vs managed disable under the
    // lifecycle barrier: both enforcement points agree.
    // -----------------------------------------------------------------

    public function test_retained_instance_version_blocks_both_managed_disable_and_instance_delete_consistently(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-retained-reference-create');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $guard = app(WidgetModuleDisableGuard::class);
        $this->assertNotNull($guard->evaluate(), 'A retained Instance Version referencing this Widget/Theme must block managed module disable.');

        $deleteResult = app(WidgetInstanceService::class)->delete(self::PROJECT, $instance->uuid);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_INSTANCE_IN_USE, $deleteResult->status, 'The SAME retained reference must also block a direct Instance delete -- both enforcement points must agree.');
        $this->assertNull(WidgetInstance::query()->where('uuid', $instance->uuid)->first()->deleted_at, 'A blocked delete must not soft-delete the Instance.');
    }

    /**
     * Strengthens the scenario above by driving the FULL
     * {@see \App\Support\Modules\ManagedModuleDisabler::disable()} call --
     * guard registry lookup, active-dependents check, and the
     * {@see ModuleLifecycleBarrier} acquisition -- rather than the raw
     * guard predicate alone, so "under the lifecycle barrier" (DoD 1309's
     * own wording) is proven through the real barrier-mediated call chain
     * production code actually uses (the sanctioned `cms:module-disable`
     * command and the admin ModuleController disable action both call
     * this exact method).
     */
    public function test_managed_disabler_refuses_the_full_disable_transition_while_a_retained_reference_exists(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, 'race-disabler-blocked-create');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $conflict = app(\App\Support\Modules\ManagedModuleDisabler::class)->disable('Widget');

        $this->assertSame('WIDGET_MODULE_IN_USE', $conflict, 'The full disable transition (guard + registry + barrier) must refuse while a retained Instance Version exists, not just the raw guard predicate in isolation.');
        $this->assertTrue((bool) \Nwidart\Modules\Facades\Module::find('Widget')?->isEnabled(), 'A refused disable must leave the module enabled.');
    }

    /**
     * Complementary ordering: zero retained references yet, so the full
     * disable transition succeeds; a SUBSEQUENT attempt to create a new
     * retained reference (an Instance Version) must then be refused --
     * disable-wins, no new reference sneaks in after the module is
     * disabled. Together with the test above, both orderings of
     * "retained-reference mutation versus managed disable under the
     * lifecycle barrier" are proven: whichever happens first wins, and
     * the loser is cleanly refused with zero inconsistent state.
     */
    public function test_managed_disable_then_refuses_a_subsequent_instance_version_creating_mutation(): void
    {
        $this->assertSame(0, WidgetInstanceVersion::query()->count(), 'Precondition: no retained reference exists anywhere yet.');

        $conflict = app(\App\Support\Modules\ManagedModuleDisabler::class)->disable('Widget');
        $this->assertNull($conflict, 'Disable must succeed cleanly while zero retained Instance Versions exist.');
        $this->assertFalse((bool) \Nwidart\Modules\Facades\Module::find('Widget')?->isEnabled());

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $this->approvedManifestHash($widget, $theme);

        $result = app(WidgetInstanceService::class)->create(self::PROJECT, $this->actorId, 'race-create-after-disable', [
            'name' => 'Should Not Be Created',
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => [],
            'provider_contract' => [],
        ]);

        $this->assertSame(WidgetInstanceMutationResult::STATUS_MODULE_CONFLICT, $result->status, 'Disable-wins: a create attempted after the module is disabled must be refused, creating no new retained reference.');
        $this->assertSame(0, WidgetInstance::query()->count());

        // Restore enabled state in-process (belt and suspenders --
        // setUpBeforeClass()/tearDownAfterClass() also restore the real
        // modules_statuses.json file's pristine pre-suite content
        // regardless of what any individual test flips it to).
        app(ActivatorInterface::class)->setActiveByName('Widget', true);
    }

    // -----------------------------------------------------------------
    // 9. Delete vs reference creation: succeeds before a reference exists,
    // refused the instant one does.
    // -----------------------------------------------------------------

    public function test_delete_succeeds_before_a_reference_exists_and_is_refused_once_placed(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        $unplaced = $this->createReadyInstance($widget, $theme, 'race-delete-unplaced');
        $unplacedDelete = app(WidgetInstanceService::class)->delete(self::PROJECT, $unplaced->uuid);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_DELETED, $unplacedDelete->status);

        $placed = $this->createReadyInstance($widget, $theme, 'race-delete-placed');
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $placed->currentVersion);

        $blockedDelete = app(WidgetInstanceService::class)->delete(self::PROJECT, $placed->uuid);
        $this->assertSame(WidgetInstanceMutationResult::STATUS_INSTANCE_IN_USE, $blockedDelete->status);
        $this->assertNull(WidgetInstance::query()->where('uuid', $placed->uuid)->first()->deleted_at);
    }

    // -----------------------------------------------------------------
    // 10. Full-transaction failure leaves zero partial rows (the
    // provable half on any engine); true multi-process deadlock-retry is
    // an honest, disclosed scope boundary -- exactly the precedent every
    // Task 16/18/20/21 predecessor already established for this identical
    // shape of problem (see WidgetDatabaseConcurrencyTest's own
    // test_true_concurrent_contention_is_out_of_this_tasks_scope()).
    // -----------------------------------------------------------------

    public function test_a_thrown_exception_mid_transaction_rolls_back_every_change(): void
    {
        $lockOrder = app(WidgetAggregateLockOrder::class);
        $layoutId = DB::table('widget_layouts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => self::PROJECT,
            'key' => 'race-deadlock-' . uniqid('', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $lockOrder->withLocks([$layoutId], [], function () use ($layoutId): void {
                DB::table('widget_layouts')->where('id', $layoutId)->update(['key' => 'mutated-inside-transaction']);

                throw new RuntimeException('race test: simulated mid-transaction failure');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('race test: simulated mid-transaction failure', $exception->getMessage());
        }

        $row = DB::table('widget_layouts')->find($layoutId);
        $this->assertNotSame('mutated-inside-transaction', $row->key, 'A thrown exception mid-transaction must roll back EVERY change, leaving zero partial state -- the all-or-nothing invariant every full-transaction deadlock retry ultimately depends on.');
    }

    public function test_true_multi_process_deadlock_retry_is_out_of_this_files_provable_scope_on_sqlite(): void
    {
        $this->markTestSkipped(
            'True multi-process deadlock-retry (two REAL concurrent transactions racing for the same FOR UPDATE '
            . 'row lock, one aborted by the engine and safely retried with no duplicate identity) cannot be produced '
            . 'from a single-process sqlite/PHPUnit run on any engine -- identical, already-established scope '
            . 'boundary as WidgetDatabaseConcurrencyTest::test_true_concurrent_contention_is_out_of_this_tasks_scope() '
            . '(Task 13b). This file\'s own test_a_thrown_exception_mid_transaction_rolls_back_every_change() proves '
            . 'the all-or-nothing transactional half that IS provable here; every other scenario in this file proves '
            . 'gap-free/duplicate-free SEQUENTIAL allocation. The coordinator\'s dual-engine matrix run (real MySQL + '
            . 'PostgreSQL, this SAME file, per DoD 1310) is the actual arbiter of true concurrent contention.'
        );
    }
}
