<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Events\WidgetLayoutPublished;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobDescriptor;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 2 Task 20: atomic publish (
 * lines 1126-1153; architecture.md "Publish").
 *
 * Exercises {@see WidgetLayoutPublishService} DIRECTLY for the algorithmic/
 * transactional surface (mirrors this module's established
 * Feature/SiteLayout convention -- {@see \Cms\Widget\Tests\Feature\SiteLayout\UpdateInstanceAndRepinDraftTest}),
 * plus a small dedicated HTTP-wiring section at the end proving the
 * controller/route/FormRequest layer, since Task 20's own file list
 * authorizes exactly ONE test file (no separate .../Http/WidgetLayoutPublishApiTest.php).
 */
final class WidgetLayoutPublishTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.publish.slot';

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

        $this->storageRoot = storage_path('framework/testing/widget-publish-' . bin2hex(random_bytes(6)));

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
            labelKey: 'test.publish.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // PHPUnit runs under the cli SAPI: WidgetInstanceService's own
        // runVersionCreatingTransaction()-shaped gate (used only by this
        // test's createReadyInstance()/approveManifestFor() FIXTURE helpers,
        // never by WidgetLayoutPublishService itself, which deliberately
        // does NOT consult this barrier per the coordinator brief) therefore
        // routes through $this->barrier->transition() rather than the live
        // HTTP complementary window -- mirrors
        // UpdateInstanceAndRepinDraftTest::setUp()'s identical precedent.
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

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget, string $suffix = ''): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Test Theme' . $suffix,
            'content' => '<div class="card' . $suffix . '">{title}</div>',
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
            'name' => 'Publish Tester',
            'email' => 'publish-tester-' . uniqid('', true) . '@test.local',
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
            'widget_layout.update',
            'widget_layout.publish',
            'widget_template.approve',
            'widget_template.emergencyDisable',
        ]);
    }

    private function approveManifestFor(Widget $widget, WidgetTheme $theme, User $approver): string
    {
        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();

        $manifestHash = (string) $response->json('data.manifest_hash');

        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $approver->id, 'test approval');

        return $manifestHash;
    }

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user, string $name = 'Instance'): WidgetInstance
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

    private function createPlacement(WidgetLayoutVersion $version, WidgetInstanceVersion $instanceVersion, string $slotKey = self::SLOT, int $position = 0): WidgetPlacement
    {
        return WidgetPlacement::query()->create([
            'project_name' => self::PROJECT,
            'layout_version_id' => $version->id,
            'widget_instance_version_id' => $instanceVersion->id,
            'slot_key' => $slotKey,
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => $position,
            'enabled' => true,
        ]);
    }

    /**
     * Real registry snapshot (slots.* keyed by SELF::SLOT) so the
     * compatibility checker finds a registered, compatible reference --
     * the happy-path/eligibility-focused tests use this; the dedicated
     * drift test below deliberately uses an UNREGISTERED key instead.
     */
    private function realRegistryContracts(): array
    {
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);

        return ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
    }

    /**
     * A pending (never-approved) Manifest via the REAL materialize endpoint,
     * with a directly-constructed WidgetInstanceVersion pinned to it --
     * Instance-Version creation through the real service ALWAYS requires an
     * approved Manifest (gateManifest()'s own precedent), so this is the
     * only way to reach "a placement pins a still-pending Manifest" at
     * publish time.
     */
    private function pendingManifestInstanceVersion(WidgetInstance $instance, int $nextVersion, User $user): WidgetInstanceVersion
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, '-pending-' . uniqid('', true));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
        ]);
        $response->assertSuccessful();

        $manifest = WidgetArtifactManifest::query()
            ->where('project_name', self::PROJECT)
            ->where('manifest_hash', $response->json('data.manifest_hash'))
            ->firstOrFail();

        return WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => self::PROJECT,
            'version' => $nextVersion,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'renderer_key' => WidgetLegacyManifestMaterializer::RENDERER_KEY,
            'renderer_version' => WidgetLegacyManifestMaterializer::RENDERER_VERSION,
            'trust_classification' => (string) $manifest->trust_lane,
            'schema' => [],
            'schema_hash' => hash('sha256', '[]'),
            'values' => [],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifest->id,
        ]);
    }

    private function service(): WidgetLayoutPublishService
    {
        return app(WidgetLayoutPublishService::class);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_publish_creates_a_release_and_a_fresh_draft_and_switches_both_pointers(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $draft->registry_contracts = $this->realRegistryContracts();
        $draft->registry_contract_hash = hash('sha256', json_encode($draft->registry_contracts));
        $draft->save();
        $placement = $this->createPlacement($draft, $instance->currentVersion);

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-key-1', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(2, $result->release->version);
        $this->assertSame(WidgetLayoutVersion::STATE_RELEASE, $result->release->state);
        $this->assertSame(3, $result->draft->version);
        $this->assertSame(WidgetLayoutVersion::STATE_DRAFT, $result->draft->state);
        $this->assertSame(0, $result->draft->lock_version);

        $layout->refresh();
        $this->assertSame($result->release->id, $layout->current_published_version_id);
        $this->assertSame($result->draft->id, $layout->current_draft_version_id);

        $draft->refresh();
        $this->assertSame(WidgetLayoutVersion::STATE_SUPERSEDED, $draft->state, 'The old draft must be superseded, never deleted.');

        $this->assertSame(1, $result->release->placements()->count(), 'The Release must carry a clone of the draft Placements.');
        $releasedPlacement = $result->release->placements()->first();
        $this->assertSame($placement->widget_instance_version_id, $releasedPlacement->widget_instance_version_id);
        $this->assertNotSame($placement->uuid, $releasedPlacement->uuid, 'A cloned Placement row is a distinct row, never the same uuid.');

        $this->assertSame(1, $result->draft->placements()->count(), 'The fresh Draft must also start from a clone of the same Placements.');

        $this->assertSame(1, $draft->version, 'Release.based_on_version must be the SUPERSEDED draft VERSION NUMBER, never a raw id.');
        $this->assertSame($draft->version, $result->release->basedOn?->version);
        $this->assertNull($result->release->rollbackOf, 'Publish never sets rollback_of_version_id.');

        $this->assertNotEmpty($result->release->eligibility_snapshot['manifests'] ?? null, 'The Release must carry an eligibility snapshot of every promoted Manifest.');
    }

    public function test_publish_on_an_empty_draft_with_no_placements_still_succeeds(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-empty-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame(0, $result->release->placements()->count());
    }

    // -----------------------------------------------------------------
    // Not found / stale
    // -----------------------------------------------------------------

    public function test_publish_returns_layout_not_found_for_an_unknown_layout_uuid(): void
    {
        $user = $this->fullAccessUser();

        $result = $this->service()->publish(self::PROJECT, '00000000-0000-0000-0000-000000000000', (int) $user->id, 'publish-unknown-layout', 1, 0);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_LAYOUT_NOT_FOUND, $result->status);
    }

    public function test_publish_returns_draft_stale_and_mutates_nothing_when_expected_draft_id_is_wrong(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-stale-draft', $draft->version + 1, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_DRAFT_STALE, $result->status);
        $this->assertSame(1, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count(), 'A stale check must insert no row.');
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_publish_returns_draft_stale_and_mutates_nothing_when_expected_lock_version_is_wrong(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-stale-lock', $draft->version, $draft->lock_version + 1);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_DRAFT_STALE, $result->status);
        $this->assertSame(1, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count());
    }

    // -----------------------------------------------------------------
    // Registry compatibility drift
    // -----------------------------------------------------------------

    public function test_publish_returns_registry_drift_and_mutates_nothing_when_a_referenced_slot_is_no_longer_registered(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $draft->registry_contracts = ['slots' => ['no.longer.registered' => ['cardinality' => 'stack', 'supported_scope_types' => ['global'], 'fallback' => 'hide', 'trust_lane' => 'legacy_approved_only', 'availability' => 'enabled']], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
        $draft->save();

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-drift-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_REGISTRY_DRIFT, $result->status);
        $this->assertNotEmpty($result->registryIssues);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
        $this->assertSame(1, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count());
    }

    // -----------------------------------------------------------------
    // Manifest promotion eligibility
    // -----------------------------------------------------------------

    public function test_publish_approves_a_still_pending_pinned_manifest_automatically(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $pendingVersion = $this->pendingManifestInstanceVersion($instance, 2, $user);
        $this->createPlacement($draft, $pendingVersion);

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-pending-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame(
            WidgetManifestApprovalService::STATE_APPROVED,
            WidgetArtifactManifest::query()->findOrFail($pendingVersion->artifact_manifest_id)->eligibility_state,
        );
    }

    public function test_publish_without_an_acting_user_returns_manifest_approval_required_when_a_placements_manifest_is_still_pending(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $pendingVersion = $this->pendingManifestInstanceVersion($instance, 2, $user);
        $this->createPlacement($draft, $pendingVersion);

        // No acting user: nothing is approved on anyone's behalf, so the preflight still refuses.
        $result = $this->service()->publish(self::PROJECT, $layout->uuid, null, 'publish-pending-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_MANIFEST_APPROVAL_REQUIRED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_publish_without_an_acting_user_returns_manifest_revoked_when_a_placements_manifest_has_been_revoked(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $manifest = WidgetArtifactManifest::query()->findOrFail($instance->currentVersion->artifact_manifest_id);
        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $manifest->manifest_hash, 2, (int) $user->id, 'test revoke');

        // No acting user: nothing is re-approved on anyone's behalf, so the preflight still refuses.
        $result = $this->service()->publish(self::PROJECT, $layout->uuid, null, 'publish-revoked-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_MANIFEST_REVOKED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_publish_returns_manifest_blocked_when_a_placements_manifest_is_emergency_blocked(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $manifest = WidgetArtifactManifest::query()->findOrFail($instance->currentVersion->artifact_manifest_id);
        app(WidgetManifestRuntimeBlockService::class)->block(self::PROJECT, $manifest->manifest_hash, 1, (int) $user->id, 'test block');

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-blocked-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_MANIFEST_BLOCKED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    /**
     * DISCLOSED: a genuinely MISSING Blob/Manifest row is unreachable
     * through fixture manipulation -- this schema's composite restrictive
     * FKs (widget_manifest_blob_fk, widget_instance_version_manifest_fk;
     * 2026_07_17_150008_add_widget_site_layout_foreign_keys.php) are
     * empirically ENFORCED in this test environment (verified: deleting a
     * referenced Blob row throws a real FK QueryException), contrary to
     * that migration's own docblock predicting SQLite `Schema::table()->
     * foreign()` calls are inert. A checksum MISMATCH via a fake repository
     * proves the same "prove artifact checksum before pointer switch" gate
     * (DoD line 1140) without fighting DB-enforced referential integrity.
     */
    public function test_publish_returns_artifact_integrity_failed_when_the_blob_repository_reports_a_checksum_mismatch(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $this->app->singleton(WidgetArtifactBlobRepository::class, fn () => new class implements WidgetArtifactBlobRepository {
            public function findByProjectAndHash(string $projectName, string $artifactHash): ?WidgetArtifactBlobDescriptor
            {
                return new WidgetArtifactBlobDescriptor(
                    projectName: $projectName,
                    artifactHash: $artifactHash,
                    byteSize: 1,
                    mediaType: 'text/html',
                    checksum: 'corrupted-checksum-does-not-match',
                    storageDisk: 'widget_artifacts_local',
                    storageKey: 'irrelevant/key',
                    createdAt: new \DateTimeImmutable(),
                );
            }

            public function put(string $projectName, string $artifactHash, string $bytes, string $mediaType): WidgetArtifactBlobDescriptor
            {
                throw new RuntimeException('Not exercised by this test.');
            }
        });

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-checksum-mismatch-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_ARTIFACT_INTEGRITY_FAILED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_publish_returns_provider_unavailable_when_the_artifact_blob_repository_throws(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $this->app->singleton(WidgetArtifactBlobRepository::class, fn () => new class implements WidgetArtifactBlobRepository {
            public function findByProjectAndHash(string $projectName, string $artifactHash): ?WidgetArtifactBlobDescriptor
            {
                throw new RuntimeException('Simulated storage outage.');
            }

            public function put(string $projectName, string $artifactHash, string $bytes, string $mediaType): WidgetArtifactBlobDescriptor
            {
                throw new RuntimeException('Simulated storage outage.');
            }
        });

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'publish-outage-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PROVIDER_UNAVAILABLE, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_publish_replays_the_same_release_and_draft_identity_for_a_repeated_idempotency_key(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $first = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'repeat-publish-key', $draft->version, $draft->lock_version);
        $second = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'repeat-publish-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $first->status);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $second->status);
        $this->assertSame($first->release->version, $second->release->version);
        $this->assertSame($first->draft->version, $second->draft->version);
        $this->assertSame(3, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count(), 'A replay must not allocate a second Release/Draft pair (original draft + one Release + one fresh Draft).');
    }

    public function test_publish_returns_idempotency_mismatch_for_the_same_key_with_a_different_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'conflict-publish-key', $draft->version, $draft->lock_version);

        $second = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'conflict-publish-key', $draft->version, $draft->lock_version + 999);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_IDEMPOTENCY_CONFLICT, $second->status);
    }

    // -----------------------------------------------------------------
    // Concurrency / allocation (functional proof; dual-engine race matrix
    // is the chunk exit gate's own job, per the coordinator brief)
    // -----------------------------------------------------------------

    public function test_two_publishes_with_different_idempotency_keys_allocate_distinct_non_overlapping_version_pairs(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $first = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'distinct-key-1', $draft->version, $draft->lock_version);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $first->status);
        $this->assertSame(2, $first->release->version);
        $this->assertSame(3, $first->draft->version);

        $second = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'distinct-key-2', $first->draft->version, $first->draft->lock_version);
        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $second->status);
        $this->assertSame(4, $second->release->version);
        $this->assertSame(5, $second->draft->version);

        $allVersions = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->pluck('version')->all();
        $this->assertSame(range(1, 5), collect($allVersions)->sort()->values()->all(), 'Every version number 1..5 must be allocated exactly once -- no reuse across the two different-key publishes.');
        $this->assertSame(5, count(array_unique($allVersions)), 'No two rows may share a version number.');
    }

    // -----------------------------------------------------------------
    // Event dispatch: only after commit, never on a failed/stale attempt,
    // never twice on replay, and a listener failure must not undo success.
    // -----------------------------------------------------------------

    public function test_publish_dispatches_widget_layout_published_exactly_once_on_success(): void
    {
        Event::fake([WidgetLayoutPublished::class]);

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'event-key', $draft->version, $draft->lock_version);

        Event::assertDispatched(WidgetLayoutPublished::class, function (WidgetLayoutPublished $event) use ($layout, $result): bool {
            return $event->projectName === self::PROJECT
                && $event->layoutUuid === $layout->uuid
                && $event->releaseVersion === $result->release->version
                && $event->freshDraftVersion === $result->draft->version;
        });
        Event::assertDispatchedTimes(WidgetLayoutPublished::class, 1);
    }

    public function test_publish_does_not_dispatch_the_event_when_the_draft_is_stale(): void
    {
        Event::fake([WidgetLayoutPublished::class]);

        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'event-stale-key', $draft->version + 1, $draft->lock_version);

        Event::assertNotDispatched(WidgetLayoutPublished::class);
    }

    public function test_publish_does_not_dispatch_the_event_again_on_replay(): void
    {
        Event::fake([WidgetLayoutPublished::class]);

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'event-replay-key', $draft->version, $draft->lock_version);
        $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'event-replay-key', $draft->version, $draft->lock_version);

        Event::assertDispatchedTimes(WidgetLayoutPublished::class, 1);
    }

    public function test_a_post_commit_listener_failure_does_not_undo_the_committed_publish(): void
    {
        \Illuminate\Support\Facades\Event::listen(WidgetLayoutPublished::class, function (): void {
            throw new RuntimeException('Simulated listener failure.');
        });

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'listener-failure-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status, 'A post-commit listener exception must not turn the response into a failure.');
        $layout->refresh();
        $this->assertSame($result->release->id, $layout->current_published_version_id, 'The committed pointer switch must survive a listener exception.');
    }

    // -----------------------------------------------------------------
    // Durable audit
    // -----------------------------------------------------------------

    public function test_publish_writes_a_durable_audit_row_in_the_same_transaction(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $before = AuditLog::query()->count();

        $result = $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'audit-key', $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutPublishResult::STATUS_PUBLISHED, $result->status);
        $this->assertSame($before + 1, AuditLog::query()->count());

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_layout.publish', $row->action);
        $this->assertSame((int) $user->id, $row->user_id);
    }

    public function test_publish_writes_no_audit_row_when_the_draft_is_stale(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $before = AuditLog::query()->count();

        $this->service()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'audit-stale-key', $draft->version + 1, $draft->lock_version);

        $this->assertSame($before, AuditLog::query()->count());
    }

    // -----------------------------------------------------------------
    // HTTP wiring: permission gate, route, and composite response shape.
    // The service-level tests above cover the full transactional/algorithmic
    // surface; this section only proves the controller/route/FormRequest
    // layer that nothing else in this task's file list exercises.
    // -----------------------------------------------------------------

    public function test_the_http_route_denies_publish_without_the_permission_and_returns_the_frozen_403_envelope(): void
    {
        $user = $this->userOfType(UserType::ADMIN);
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-denied-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/publish", [
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_the_http_route_publishes_successfully_and_returns_the_composite_release_draft_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instance->currentVersion);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-success-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/publish", [
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('release.version', 2);
        $response->assertJsonPath('draft.version', 3);
        $response->assertJsonPath('draft.lock_version', 0);
        $response->assertJsonPath('sync_status', 'ready');
    }

    public function test_the_http_route_returns_the_frozen_409_envelope_for_a_stale_draft(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-stale-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/publish", [
                'expected_draft_id' => $draft->version + 1,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
    }
}
