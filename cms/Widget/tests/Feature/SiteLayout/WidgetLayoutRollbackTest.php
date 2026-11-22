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
use Cms\Widget\Events\WidgetLayoutRolledBack;
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
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackResult;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackService;
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
 * Chunk 2 Task 21: rollback-by-copy (
 * lines 1155-1179; architecture.md "Rollback").
 *
 * Mirrors {@see \Cms\Widget\Tests\Feature\SiteLayout\WidgetLayoutPublishTest}'s
 * established convention exactly (Task 20's own sibling): exercises
 * {@see WidgetLayoutRollbackService} DIRECTLY for the algorithmic/
 * transactional surface, plus a small dedicated HTTP-wiring section at the
 * end proving the controller/route/FormRequest layer, since Task 21's own
 * file list authorizes exactly ONE test file.
 *
 * Historical Release fixtures are built directly against the
 * `widget_layout_versions` table (state=release) rather than always routing
 * through a real {@see WidgetLayoutPublishService} call, mirroring
 * WidgetLayoutPublishTest's own precedent of manipulating a Draft's
 * `registry_contracts` directly for its drift/eligibility tests -- both
 * choices avoid coupling this suite's fixtures to a second service's
 * internal behavior for scenarios that only need "a retained immutable
 * Release row with known content" to exist.
 */
final class WidgetLayoutRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.rollback.slot';

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

        $this->storageRoot = storage_path('framework/testing/widget-rollback-' . bin2hex(random_bytes(6)));

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
            labelKey: 'test.rollback.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // PHPUnit runs under the cli SAPI: WidgetInstanceService's own
        // runVersionCreatingTransaction()-shaped gate (used only by this
        // test's createReadyInstance()/approveManifestFor() FIXTURE helpers,
        // never by WidgetLayoutRollbackService itself, which deliberately
        // does NOT consult this barrier per the coordinator brief -- Layout
        // Release is module-owned) therefore routes through
        // $this->barrier->transition() -- mirrors
        // WidgetLayoutPublishTest::setUp()'s identical precedent.
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
            'name' => 'Rollback Tester',
            'email' => 'rollback-tester-' . uniqid('', true) . '@test.local',
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
            'widget_layout.rollback',
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
     * compatibility checker finds a registered, compatible reference.
     */
    private function realRegistryContracts(): array
    {
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);

        return ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
    }

    /**
     * Directly inserts a RETAINED immutable historical Release row (never
     * routed through {@see WidgetLayoutPublishService}) -- this suite's own
     * fixture precedent, see class docblock. `registry_contracts` defaults
     * to the real, currently-registered SELF::SLOT snapshot so most tests
     * pass the registry-compatibility gate for free; drift tests override it.
     */
    private function fabricateRelease(WidgetLayout $layout, int $version, ?array $registryContracts = null): WidgetLayoutVersion
    {
        $contracts = $registryContracts ?? $this->realRegistryContracts();

        return WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
            'version' => $version,
            'state' => WidgetLayoutVersion::STATE_RELEASE,
            'lock_version' => 0,
            'registry_contracts' => $contracts,
            'registry_contract_hash' => hash('sha256', json_encode($contracts)),
            'eligibility_snapshot' => ['manifests' => []],
            'published_at' => now(),
        ]);
    }

    /**
     * A pending (never-approved) Manifest via the REAL materialize endpoint,
     * with a directly-constructed WidgetInstanceVersion pinned to it.
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

    private function service(): WidgetLayoutRollbackService
    {
        return app(WidgetLayoutRollbackService::class);
    }

    private function publishService(): WidgetLayoutPublishService
    {
        return app(WidgetLayoutPublishService::class);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_rollback_creates_a_new_release_from_the_source_and_a_fresh_draft_and_switches_both_pointers(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $sourcePlacement = $this->createPlacement($sourceRelease, $instance->currentVersion);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-key-1', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(3, $result->release->version);
        $this->assertSame(WidgetLayoutVersion::STATE_RELEASE, $result->release->state);
        $this->assertSame(4, $result->draft->version);
        $this->assertSame(WidgetLayoutVersion::STATE_DRAFT, $result->draft->state);
        $this->assertSame(0, $result->draft->lock_version);

        $layout->refresh();
        $this->assertSame($result->release->id, $layout->current_published_version_id);
        $this->assertSame($result->draft->id, $layout->current_draft_version_id);

        $draft->refresh();
        $this->assertSame(WidgetLayoutVersion::STATE_SUPERSEDED, $draft->state, 'The old draft must be superseded, never deleted.');

        $this->assertSame($sourceRelease->version, $result->release->rollbackOf?->version, 'rollback_of_version_id must point at the source Release.');
        $this->assertSame($sourceRelease->version, $result->release->basedOn?->version, 'The new Release is mechanically cloned FROM the source Release.');

        $this->assertSame(1, $result->release->placements()->count(), 'The new Release must carry a clone of the source Release Placements.');
        $releasedPlacement = $result->release->placements()->first();
        $this->assertSame($sourcePlacement->widget_instance_version_id, $releasedPlacement->widget_instance_version_id);
        $this->assertNotSame($sourcePlacement->uuid, $releasedPlacement->uuid, 'A cloned Placement row is a distinct row, never the same uuid.');

        $this->assertSame(1, $result->draft->placements()->count(), 'The fresh Draft must also start from a clone of the same Placements.');
        $this->assertSame($release = $result->release->id, $result->draft->basedOn?->id, 'The fresh Draft is based on the NEW Release.');

        $this->assertNotEmpty($result->release->eligibility_snapshot['manifests'] ?? null, 'The new Release must carry a FRESH eligibility snapshot.');
    }

    public function test_rollback_on_a_source_release_with_no_placements_still_succeeds(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-empty-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status);
        $this->assertSame(0, $result->release->placements()->count());
    }

    // -----------------------------------------------------------------
    // Not found / stale
    // -----------------------------------------------------------------

    public function test_rollback_returns_layout_not_found_for_an_unknown_layout_uuid(): void
    {
        $user = $this->fullAccessUser();

        $result = $this->service()->rollback(self::PROJECT, '00000000-0000-0000-0000-000000000000', (int) $user->id, 'rollback-unknown-layout', 1, 1, 0);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_LAYOUT_NOT_FOUND, $result->status);
    }

    public function test_rollback_returns_source_release_not_found_when_the_version_does_not_exist(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-no-source', 999, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_SOURCE_RELEASE_NOT_FOUND, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
        $this->assertSame(1, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count(), 'A not-found source must insert no row.');
    }

    public function test_rollback_returns_source_release_not_found_when_the_version_is_not_in_release_state(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        // Points at the current DRAFT's own version number -- a draft is
        // never a valid rollback source identity, only an immutable Release.
        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-draft-as-source', $draft->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_SOURCE_RELEASE_NOT_FOUND, $result->status);
    }

    public function test_rollback_returns_draft_stale_and_mutates_nothing_when_expected_draft_id_is_wrong(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-stale-draft', $sourceRelease->version, $draft->version + 1, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_DRAFT_STALE, $result->status);
        $this->assertSame(2, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count(), 'A stale check must insert no row (draft + fabricated source only).');
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_rollback_returns_draft_stale_and_mutates_nothing_when_expected_lock_version_is_wrong(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-stale-lock', $sourceRelease->version, $draft->version, $draft->lock_version + 1);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_DRAFT_STALE, $result->status);
        $this->assertSame(2, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count());
    }

    // -----------------------------------------------------------------
    // Registry compatibility drift (evaluated against the SOURCE Release's
    // own retained registry_contracts, not the current draft's).
    // -----------------------------------------------------------------

    public function test_rollback_returns_registry_drift_and_mutates_nothing_when_the_source_release_references_an_unregistered_slot(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2, ['slots' => ['no.longer.registered' => ['cardinality' => 'stack', 'supported_scope_types' => ['global'], 'fallback' => 'hide', 'trust_lane' => 'legacy_approved_only', 'availability' => 'enabled']], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []]);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-drift-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_REGISTRY_DRIFT, $result->status);
        $this->assertNotEmpty($result->registryIssues);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
        $this->assertSame(2, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count());
    }

    // -----------------------------------------------------------------
    // Manifest promotion eligibility for THIS new promotion (a revoked
    // source Manifest blocks the rollback without touching the source).
    // -----------------------------------------------------------------

    public function test_rollback_returns_manifest_approval_required_when_a_copied_placements_manifest_is_still_pending(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $pendingVersion = $this->pendingManifestInstanceVersion($instance, 2, $user);
        $this->createPlacement($sourceRelease, $pendingVersion);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-pending-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_MANIFEST_APPROVAL_REQUIRED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_rollback_returns_manifest_revoked_when_a_copied_placements_manifest_has_been_revoked(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $manifest = WidgetArtifactManifest::query()->findOrFail($instance->currentVersion->artifact_manifest_id);
        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $manifest->manifest_hash, 2, (int) $user->id, 'test revoke');

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-revoked-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_MANIFEST_REVOKED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);

        // Revocation blocks this NEW promotion but must not alter the
        // retained source Release itself (architecture.md "Rollback" step 2).
        $sourceRelease->refresh();
        $this->assertSame(WidgetLayoutVersion::STATE_RELEASE, $sourceRelease->state);
        $this->assertSame(1, $sourceRelease->placements()->count());
    }

    public function test_rollback_returns_manifest_blocked_when_a_copied_placements_manifest_is_emergency_blocked(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $manifest = WidgetArtifactManifest::query()->findOrFail($instance->currentVersion->artifact_manifest_id);
        app(WidgetManifestRuntimeBlockService::class)->block(self::PROJECT, $manifest->manifest_hash, 1, (int) $user->id, 'test block');

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-blocked-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_MANIFEST_BLOCKED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_rollback_returns_artifact_integrity_failed_when_the_blob_repository_reports_a_checksum_mismatch(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

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

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-checksum-mismatch-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ARTIFACT_INTEGRITY_FAILED, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    public function test_rollback_returns_provider_unavailable_when_the_artifact_blob_repository_throws(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

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

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-outage-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_PROVIDER_UNAVAILABLE, $result->status);
        $layout->refresh();
        $this->assertNull($layout->current_published_version_id);
    }

    // -----------------------------------------------------------------
    // Never mutate/reactivate the source Release (DoD line 1169).
    // -----------------------------------------------------------------

    public function test_rollback_never_mutates_or_reactivates_the_source_release(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);
        $sourceUpdatedAt = $sourceRelease->updated_at;
        $sourcePlacementIds = $sourceRelease->placements()->pluck('id')->sort()->values()->all();

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-never-mutate-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status);

        $sourceRelease->refresh();
        $this->assertSame(WidgetLayoutVersion::STATE_RELEASE, $sourceRelease->state, 'The source Release must never change state.');
        $this->assertEquals($sourceUpdatedAt, $sourceRelease->updated_at, 'The source Release row must never be written to.');
        $this->assertSame($sourcePlacementIds, $sourceRelease->placements()->pluck('id')->sort()->values()->all(), 'The source Release Placements must never change.');

        $layout->refresh();
        $this->assertNotSame($sourceRelease->id, $layout->current_published_version_id, 'Rollback publishes a NEW Release, never reactivates the source row.');
    }

    // -----------------------------------------------------------------
    // Snapshot identity: rollback restores the SOURCE Release's exact
    // retained snapshot, never "whatever is currently live" (DoD line 1170).
    // -----------------------------------------------------------------

    public function test_rollback_restores_the_exact_released_snapshot_identity_using_a_fixed_provider_fixture_not_arbitrary_current_output(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $oldInstance = $this->createReadyInstance($widget, $theme, $user, 'Old Instance');
        $newInstance = $this->createReadyInstance($widget, $theme, $user, 'New Instance');

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        // The historical, fixed source snapshot this test asserts against.
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $oldInstance->currentVersion, self::SLOT, 0);

        // "Current" state has since drifted to reference a DIFFERENT
        // Instance Version -- proves rollback copies the immutable SOURCE
        // snapshot, never this newer/live state.
        $draft->registry_contracts = $this->realRegistryContracts();
        $draft->registry_contract_hash = hash('sha256', json_encode($draft->registry_contracts));
        $draft->save();
        $this->createPlacement($draft, $newInstance->currentVersion, self::SLOT, 0);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'rollback-snapshot-identity-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status);

        $releasedPlacement = $result->release->placements()->firstOrFail();
        $this->assertSame(
            $oldInstance->currentVersion->id,
            $releasedPlacement->widget_instance_version_id,
            'Rollback must reproduce the SOURCE Release Instance Version reference exactly.'
        );
        $this->assertNotSame(
            $newInstance->currentVersion->id,
            $releasedPlacement->widget_instance_version_id,
            'Rollback must never pick up the newer/live Instance Version that only the superseded draft referenced.'
        );
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_rollback_replays_the_same_release_and_draft_identity_for_a_repeated_idempotency_key(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $first = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'repeat-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);
        $second = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'repeat-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $first->status);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $second->status);
        $this->assertSame($first->release->version, $second->release->version);
        $this->assertSame($first->draft->version, $second->draft->version);
        $this->assertSame(4, WidgetLayoutVersion::query()->where('layout_id', $layout->id)->count(), 'A replay must not allocate a second Release/Draft pair (draft + fabricated source + one Release + one fresh Draft).');
    }

    public function test_rollback_returns_idempotency_mismatch_for_the_same_key_with_a_different_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'conflict-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $second = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'conflict-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version + 999);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_IDEMPOTENCY_CONFLICT, $second->status);
    }

    // -----------------------------------------------------------------
    // Concurrency / allocation (functional proof; dual-engine race matrix
    // is the chunk exit gate's own job, per the coordinator brief -- same
    // precedent Task 20 established).
    // -----------------------------------------------------------------

    public function test_two_rollbacks_with_different_idempotency_keys_allocate_distinct_non_overlapping_version_pairs(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $first = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'distinct-rollback-key-1', $sourceRelease->version, $draft->version, $draft->lock_version);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $first->status);
        $this->assertSame(3, $first->release->version);
        $this->assertSame(4, $first->draft->version);

        $second = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'distinct-rollback-key-2', $sourceRelease->version, $first->draft->version, $first->draft->lock_version);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $second->status);
        $this->assertSame(5, $second->release->version);
        $this->assertSame(6, $second->draft->version);

        $allVersions = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->pluck('version')->all();
        $this->assertSame(range(1, 6), collect($allVersions)->sort()->values()->all(), 'Every version number 1..6 must be allocated exactly once.');
        $this->assertSame(6, count(array_unique($allVersions)), 'No two rows may share a version number.');
    }

    public function test_rollback_and_publish_interleaved_allocate_distinct_non_overlapping_version_pairs(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $draft->registry_contracts = $this->realRegistryContracts();
        $draft->registry_contract_hash = hash('sha256', json_encode($draft->registry_contracts));
        $draft->save();
        $this->createPlacement($draft, $instance->currentVersion);
        $sourceRelease = $this->fabricateRelease($layout, 5);

        $published = $this->publishService()->publish(self::PROJECT, $layout->uuid, (int) $user->id, 'interleave-publish-key', $draft->version, $draft->lock_version);
        $this->assertSame('published', $published->status);
        $this->assertSame(6, $published->release->version);
        $this->assertSame(7, $published->draft->version);

        $rolledBack = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'interleave-rollback-key', $sourceRelease->version, $published->draft->version, $published->draft->lock_version);
        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $rolledBack->status);
        $this->assertSame(8, $rolledBack->release->version);
        $this->assertSame(9, $rolledBack->draft->version);

        // Six rows total: draft v1, fabricated source v5 (a deliberate gap
        // at 2-4 -- the fabricated fixture's own version number is
        // arbitrary), publish's release v6 + fresh draft v7, rollback's
        // release v8 + fresh draft v9.
        $allVersions = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->pluck('version')->all();
        $this->assertSame(6, count($allVersions), 'Sanity: exactly six Layout Version rows exist.');
        $this->assertSame(count($allVersions), count(array_unique($allVersions)), 'Publish and rollback must never allocate overlapping version numbers.');
    }

    // -----------------------------------------------------------------
    // Event dispatch: only after commit, never on a failed/stale attempt,
    // never twice on replay, and a listener failure must not undo success.
    // -----------------------------------------------------------------

    public function test_rollback_dispatches_widget_layout_rolled_back_exactly_once_on_success(): void
    {
        Event::fake([WidgetLayoutRolledBack::class]);

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'event-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        Event::assertDispatched(WidgetLayoutRolledBack::class, function (WidgetLayoutRolledBack $event) use ($layout, $sourceRelease, $result): bool {
            return $event->projectName === self::PROJECT
                && $event->layoutUuid === $layout->uuid
                && $event->sourceReleaseVersion === $sourceRelease->version
                && $event->releaseVersion === $result->release->version
                && $event->freshDraftVersion === $result->draft->version;
        });
        Event::assertDispatchedTimes(WidgetLayoutRolledBack::class, 1);
    }

    public function test_rollback_does_not_dispatch_the_event_when_the_draft_is_stale(): void
    {
        Event::fake([WidgetLayoutRolledBack::class]);

        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'event-stale-rollback-key', $sourceRelease->version, $draft->version + 1, $draft->lock_version);

        Event::assertNotDispatched(WidgetLayoutRolledBack::class);
    }

    public function test_rollback_does_not_dispatch_the_event_again_on_replay(): void
    {
        Event::fake([WidgetLayoutRolledBack::class]);

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'event-replay-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);
        $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'event-replay-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        Event::assertDispatchedTimes(WidgetLayoutRolledBack::class, 1);
    }

    public function test_a_post_commit_listener_failure_does_not_undo_the_committed_rollback(): void
    {
        \Illuminate\Support\Facades\Event::listen(WidgetLayoutRolledBack::class, function (): void {
            throw new RuntimeException('Simulated listener failure.');
        });

        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'listener-failure-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status, 'A post-commit listener exception must not turn the response into a failure.');
        $layout->refresh();
        $this->assertSame($result->release->id, $layout->current_published_version_id, 'The committed pointer switch must survive a listener exception.');
    }

    // -----------------------------------------------------------------
    // Durable audit
    // -----------------------------------------------------------------

    public function test_rollback_writes_a_durable_audit_row_in_the_same_transaction(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $before = AuditLog::query()->count();

        $result = $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'audit-rollback-key', $sourceRelease->version, $draft->version, $draft->lock_version);

        $this->assertSame(WidgetLayoutRollbackResult::STATUS_ROLLED_BACK, $result->status);
        $this->assertSame($before + 1, AuditLog::query()->count());

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_layout.rollback', $row->action);
        $this->assertSame((int) $user->id, $row->user_id);
        $this->assertSame($sourceRelease->version, (int) $row->changed['source_release']);
        $this->assertSame($result->release->version, (int) $row->changed['destination_release']);
    }

    public function test_rollback_writes_no_audit_row_when_the_draft_is_stale(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $before = AuditLog::query()->count();

        $this->service()->rollback(self::PROJECT, $layout->uuid, (int) $user->id, 'audit-stale-rollback-key', $sourceRelease->version, $draft->version + 1, $draft->lock_version);

        $this->assertSame($before, AuditLog::query()->count());
    }

    // -----------------------------------------------------------------
    // HTTP wiring: permission gate, route, and composite response shape.
    // The service-level tests above cover the full transactional/algorithmic
    // surface; this section only proves the controller/route/FormRequest
    // layer that nothing else in this task's file list exercises.
    // -----------------------------------------------------------------

    public function test_the_http_route_denies_rollback_without_the_permission_and_returns_the_frozen_403_envelope(): void
    {
        $user = $this->userOfType(UserType::ADMIN);
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-denied-rollback-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/rollback", [
                'source_release_version' => $sourceRelease->version,
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_the_http_route_rolls_back_successfully_and_returns_the_composite_release_draft_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $instance = $this->createReadyInstance($widget, $theme, $user);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);
        $this->createPlacement($sourceRelease, $instance->currentVersion);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-success-rollback-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/rollback", [
                'source_release_version' => $sourceRelease->version,
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('release.version', 3);
        $response->assertJsonPath('release.rollback_of_version', 2);
        $response->assertJsonPath('draft.version', 4);
        $response->assertJsonPath('draft.lock_version', 0);
        $response->assertJsonPath('sync_status', 'ready');
    }

    public function test_the_http_route_returns_the_frozen_409_envelope_for_a_stale_draft(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $sourceRelease = $this->fabricateRelease($layout, 2);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-stale-rollback-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/rollback", [
                'source_release_version' => $sourceRelease->version,
                'expected_draft_id' => $draft->version + 1,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
    }

    public function test_the_http_route_returns_a_404_envelope_for_an_unknown_source_release(): void
    {
        $user = $this->fullAccessUser();
        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'http-unknown-source-key')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/rollback", [
                'source_release_version' => 999,
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);

        $response->assertStatus(404);
    }
}
