<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
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
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 17: `PUT /layouts/{uuid}/draft` deterministic Draft Save
 * (plan lines 1020-1051; architecture.md "Draft update";
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * `/layouts/{uuid}/draft` route entry).
 *
 * DISCLOSED CONTRACT INTERPRETATION: the frozen `request_fields` name is
 * literally `expected_current_draft_id`, but the Draft/`widget_layout_versions`
 * row carries no uuid of its own (schema test:
 * `WidgetSiteLayoutSchemaTest::test_eight_models_exist_with_expected_table_and_uuid_wiring()`
 * excludes it from the three uuid-bearing models) and the frozen
 * `WidgetLayoutResource.current_draft` nested field list is exactly
 * `["version", "lock_version"]` -- no raw internal row id is EVER exposed to
 * a client anywhere in this frozen contract. This suite therefore submits
 * the Draft's VERSION NUMBER (the only draft-identifying value a client can
 * actually have read from a prior response) as `expected_current_draft_id`,
 * mirroring this module's own established precedent for the identical
 * shape-of-problem: {@see \Cms\Widget\Http\Requests\WidgetInstance\UpdateWidgetInstanceRequest}'s
 * `expected_current_version` is also a VERSION NUMBER, not a raw id, for the
 * exact same reason (`widget_instance_versions` also carries no uuid).
 *
 * Mirrors {@see WidgetManifestMaterializationApiTest}'s minimal storage
 * isolation (a plain `storage_path('framework/testing/...')` root, no
 * class-level exported-seeder-file snapshot machinery) since this suite
 * never touches the Core legacy Widget/Theme HTTP endpoints, only direct
 * Eloquent fixture creation plus the REAL materialize/approve/instance
 * endpoints needed to obtain a genuinely ready Instance Version to pin.
 */
final class WidgetLayoutDraftApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const STACK_SLOT = 'test.stack.slot';

    private const SINGLE_SLOT = 'test.single.slot';

    private const TEMPLATE_CONTEXT = 'test.template';

    private const TARGET_TYPE = 'test.target';

    private string $storageRoot;

    /**
     * Class-level snapshot: path => pristine pre-suite content (or null if
     * the file did not exist). Deliberately an ARRAY keyed by path, NOT a
     * nullable scalar -- see setUpBeforeClass()'s docblock for why the
     * scalar shape is an active double-restore-deletion HAZARD this exact
     * array shape avoids (proven-safe precedent:
     * {@see WidgetInstanceApiTest::$sharedFileSnapshots}).
     */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Captures the pristine pre-suite content of the REAL, git-tracked
     * modules_statuses.json exactly once for the whole class -- this suite's
     * Instance-create calls need `setWidgetModuleEnabled(true)` (mirroring
     * {@see WidgetInstanceApiTest}'s own class docblock: the module is not
     * yet formally activated by default, and
     * `WidgetInstanceService::runVersionCreatingTransaction()` rechecks the
     * REAL Nwidart activator), which mutates this shared file on disk. A
     * shutdown function belt-and-suspenders the restore exactly like that
     * precedent (Pest's own tearDownAfterClass() ordering is not always
     * last when several explicit test file paths run in one invocation).
     *
     * HAZARD DISCLOSED (found via this exact class during implementation):
     * an EARLIER version of this method stored the snapshot as a nullable
     * SCALAR (`?string`) and reset it to `null` once consumed by restore.
     * Because BOTH tearDownAfterClass() AND the shutdown function call the
     * same restore path, the shutdown function's redundant SECOND call
     * observed an already-reset `null` and misread it as "the file never
     * existed", deleting the just-correctly-restored real file --
     * PERMANENTLY losing the git-tracked modules_statuses.json from the
     * working tree until `git checkout -- modules_statuses.json` recovered
     * it. {@see WidgetInstanceApiTest::$sharedFileSnapshots} avoids this
     * exact hazard because its restore loop CONSUMES the array down to `[]`
     * -- a second call over an empty array does nothing. This class now
     * uses the identical array shape for the same safety, even though it
     * only ever holds one path.
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

        $this->storageRoot = storage_path('framework/testing/widget-layout-draft-api-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->registerSiteLayoutFixtures();

        // WidgetInstanceService::runVersionCreatingTransaction() rechecks
        // the REAL module-status activator; this suite's fixture Instances
        // must actually create a Version (see class docblock).
        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // PHPUnit runs under the cli SAPI, so runningInConsole() is true for
        // this whole process and WidgetInstanceService::create() routes
        // fixture Instance creation through $this->barrier->transition()
        // rather than the live-HTTP complementary window. The REAL bound
        // default (MaintenanceOnlyModuleLifecycleBarrier) refuses outside
        // genuine maintenance mode, so fixture Instance creation would
        // itself return WIDGET_MODULE_IN_USE without this pass-through
        // double -- identical precedent and reasoning to
        // WidgetInstanceApiTest's own setUp().
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

    /**
     * Registers the code-owned Slot/Template Context/Target Context
     * fixtures this suite exercises. No pilot Slot wiring exists anywhere
     * in this codebase yet (Task 23 owns that) -- every Widget module test
     * that needs a registered Slot registers its own, mirroring
     * {@see \Cms\Widget\Tests\Unit\SiteLayout\WidgetTargetContextRegistryTest}'s
     * pattern, resolved against the REAL container singletons
     * WidgetServiceProvider::boot() just bound above.
     */
    private function registerSiteLayoutFixtures(): void
    {
        $this->app->singleton(FakeWidgetTargetContextResolver::class);

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: self::STACK_SLOT,
            labelKey: 'test.stack.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));
        $slots->register(new WidgetSlotDeclaration(
            key: self::SINGLE_SLOT,
            labelKey: 'test.single.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_SINGLE,
        ));

        $templateContexts = $this->app->make(WidgetTemplateContextRegistry::class);
        $templateContexts->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::STACK_SLOT, self::SINGLE_SLOT]);

        $targetContexts = $this->app->make(WidgetTargetContextRegistry::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'test.target.label',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));
    }

    // -----------------------------------------------------------------
    // User / permission fixtures
    // -----------------------------------------------------------------

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Draft Tester',
            'email' => 'draft-tester-' . uniqid('', true) . '@test.local',
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
            'widget_instance.viewAny',
            'widget_instance.view',
            'widget_instance.create',
            'widget_template.approve',
        ]);
    }

    // -----------------------------------------------------------------
    // Layout / Instance fixtures
    // -----------------------------------------------------------------

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
        $manifestHash = $response->json('data.manifest_hash');

        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $approver->id, 'test approval');
    }

    /**
     * @return array{instance_uuid: string, version: int}
     */
    private function createReadyInstanceVersion(User $user, string $name = 'Placement Instance'): array
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $this->approveManifestFor($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'instance-key-' . uniqid('', true)])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => $name,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);
        $response->assertCreated();

        return [
            'instance_uuid' => $response->json('data.uuid'),
            'version' => $response->json('data.current_version.version'),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function globalPlacement(array $instanceRef, string $slotKey = self::STACK_SLOT, int $position = 0, array $overrides = []): array
    {
        return array_merge([
            'slot_key' => $slotKey,
            'scope_type' => 'global',
            'scope_key' => null,
            'target_type' => null,
            'target_id' => null,
            'widget_instance_uuid' => $instanceRef['instance_uuid'],
            'widget_instance_version' => $instanceRef['version'],
            'position' => $position,
            'enabled' => true,
            'rules' => [],
        ], $overrides);
    }

    private function saveDraft(User $user, string $layoutUuid, int $expectedDraftId, int $expectedLockVersion, array $placements)
    {
        return $this->actingAs($user, 'sanctum')->putJson("/api/v2/admin/widget/layouts/{$layoutUuid}/draft", [
            'expected_current_draft_id' => $expectedDraftId,
            'expected_lock_version' => $expectedLockVersion,
            'placements' => $placements,
        ]);
    }

    // -----------------------------------------------------------------
    // Happy path / full-document save
    // -----------------------------------------------------------------

    public function test_save_persists_a_full_document_and_returns_the_normalized_draft(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(self::STACK_SLOT, $response->json('data.0.slot_key'));
        $this->assertSame('global', $response->json('data.0.scope_type'));
        $this->assertSame(0, $response->json('data.0.position'));
        $this->assertTrue($response->json('data.0.enabled'));
        $this->assertSame([], $response->json('data.0.rules'));

        $this->assertSame(1, WidgetPlacement::query()->count());

        $draft = WidgetLayoutVersion::query()->firstOrFail();
        $this->assertSame(1, $draft->lock_version, 'A successful save must increment lock_version exactly once.');
    }

    public function test_save_denies_a_user_without_permission(): void
    {
        $owner = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($owner);
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->saveDraft($unprivileged, $layout['uuid'], $layout['version'], $layout['lock_version'], []);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_save_returns_404_for_an_unknown_layout_uuid(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->saveDraft($user, '00000000-0000-0000-0000-000000000000', 1, 0, []);

        $response->assertStatus(404);
    }

    public function test_save_replaces_the_entire_placement_set_not_a_partial_merge(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instanceA = $this->createReadyInstanceVersion($user, 'A');
        $instanceB = $this->createReadyInstanceVersion($user, 'B');

        $first = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instanceA),
        ]);
        $first->assertOk();

        $second = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'] + 1, [
            $this->globalPlacement($instanceB, self::SINGLE_SLOT),
        ]);

        $second->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertSame(self::SINGLE_SLOT, $second->json('data.0.slot_key'));
        $this->assertSame(1, WidgetPlacement::query()->count(), 'PUT must fully replace the Draft document, never merge/accumulate.');
    }

    public function test_save_with_an_empty_placement_list_clears_the_draft(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance),
        ])->assertOk();

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'] + 1, []);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    public function test_save_normalizes_positions_to_sequential_order_by_submitted_position(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT, 8),
            $this->globalPlacement($instance, self::STACK_SLOT, 2),
            $this->globalPlacement($instance, self::STACK_SLOT, 5),
        ]);

        $response->assertOk();
        $positions = array_column($response->json('data'), 'position');
        sort($positions);
        $this->assertSame([0, 1, 2], $positions, 'Normalized positions must be sequential starting at 0.');

        // The item originally submitted with position=2 (the lowest) must
        // now be normalized to position=0 (relative order preserved).
        $normalized = collect($response->json('data'))->sortBy('position')->values();
        $this->assertSame(0, $normalized[0]['position']);
        $this->assertSame(1, $normalized[1]['position']);
        $this->assertSame(2, $normalized[2]['position']);
    }

    // -----------------------------------------------------------------
    // Stale lock
    // -----------------------------------------------------------------

    public function test_save_returns_409_draft_stale_for_a_wrong_expected_lock_version_and_changes_nothing(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], 999, [
            $this->globalPlacement($instance),
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(0, WidgetPlacement::query()->count());

        $draft = WidgetLayoutVersion::query()->firstOrFail();
        $this->assertSame(0, $draft->lock_version, 'A stale save must not increment lock_version.');
    }

    public function test_save_returns_409_draft_stale_for_a_wrong_expected_draft_id_and_changes_nothing(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], 999, $layout['lock_version'], [
            $this->globalPlacement($instance),
        ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    // -----------------------------------------------------------------
    // rules = {}
    // -----------------------------------------------------------------

    public function test_save_rejects_non_empty_rules_with_422_and_persists_nothing(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT, 0, ['rules' => ['some' => 'rule']]),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    // -----------------------------------------------------------------
    // Registered keys / cardinality
    // -----------------------------------------------------------------

    public function test_save_rejects_an_unregistered_slot_key_with_422(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, 'never.registered.slot'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    public function test_save_rejects_a_second_single_cardinality_placement_in_the_same_scope_with_422(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instanceA = $this->createReadyInstanceVersion($user, 'A');
        $instanceB = $this->createReadyInstanceVersion($user, 'B');

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instanceA, self::SINGLE_SLOT, 0),
            $this->globalPlacement($instanceB, self::SINGLE_SLOT, 1),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    public function test_save_accepts_multiple_stack_placements_in_the_same_scope(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instanceA = $this->createReadyInstanceVersion($user, 'A');
        $instanceB = $this->createReadyInstanceVersion($user, 'B');

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instanceA, self::STACK_SLOT, 0),
            $this->globalPlacement($instanceB, self::STACK_SLOT, 1),
        ]);

        $response->assertOk();
        $this->assertSame(2, WidgetPlacement::query()->count());
    }

    public function test_save_rejects_an_unknown_widget_instance_version_with_422(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement(['instance_uuid' => '00000000-0000-0000-0000-000000000000', 'version' => 1]),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    // -----------------------------------------------------------------
    // Target scope: project/target access
    // -----------------------------------------------------------------

    public function test_save_returns_403_when_the_referenced_target_is_not_readable(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        app(FakeWidgetTargetContextResolver::class)->withTarget(
            targetId: 'denied-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Denied Target',
            routeName: 'test.target.show',
            readable: false,
        );

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT, 0, [
                'scope_type' => 'target',
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'denied-1',
            ]),
        ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    public function test_save_returns_404_when_the_referenced_target_does_not_exist_in_the_authorized_project(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT, 0, [
                'scope_type' => 'target',
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'never-registered',
            ]),
        ]);

        $response->assertStatus(404);
        $this->assertSame(0, WidgetPlacement::query()->count());
    }

    public function test_save_accepts_a_readable_target_in_the_authorized_project(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        app(FakeWidgetTargetContextResolver::class)->withTarget(
            targetId: 'readable-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Readable Target',
            routeName: 'test.target.show',
        );

        $response = $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT, 0, [
                'scope_type' => 'target',
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'readable-1',
            ]),
        ]);

        $response->assertOk();
        $this->assertSame(1, WidgetPlacement::query()->count());
        $this->assertSame('readable-1', $response->json('data.0.target_id'));
    }

    // -----------------------------------------------------------------
    // One mutable current draft / no Layout Version allocation
    // -----------------------------------------------------------------

    public function test_only_one_mutable_draft_row_ever_exists_after_multiple_saves(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance),
        ])->assertOk();

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'] + 1, [
            $this->globalPlacement($instance, self::SINGLE_SLOT),
        ])->assertOk();

        $this->assertSame(1, WidgetLayoutVersion::query()->count(), 'Draft Save must never allocate a new Layout Version row.');

        $draft = WidgetLayoutVersion::query()->firstOrFail();
        $this->assertSame(WidgetLayoutVersion::STATE_DRAFT, $draft->state, 'The row must remain draft, never superseded.');
        $this->assertSame(2, $draft->lock_version);

        $layoutRow = WidgetLayout::query()->firstOrFail();
        $this->assertSame($layoutRow->current_draft_version_id, $draft->id, 'The current_draft pointer must still point at the SAME row.');
    }

    // -----------------------------------------------------------------
    // Registry contract hash recompute
    // -----------------------------------------------------------------

    public function test_saving_the_same_placements_twice_produces_the_same_registry_contract_hash(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance),
        ])->assertOk();
        $hashAfterFirst = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'] + 1, [
            $this->globalPlacement($instance),
        ])->assertOk();
        $hashAfterSecond = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;

        $this->assertSame($hashAfterFirst, $hashAfterSecond, 'An identical semantic reference set must produce the identical hash (determinism).');
    }

    public function test_adding_a_new_referenced_slot_changes_the_registry_contract_hash(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT),
        ])->assertOk();
        $hashAfterFirst = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'] + 1, [
            $this->globalPlacement($instance, self::STACK_SLOT),
            $this->globalPlacement($instance, self::SINGLE_SLOT),
        ])->assertOk();
        $hashAfterSecond = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;

        $this->assertNotSame($hashAfterFirst, $hashAfterSecond, 'Referencing an additional Slot must refresh (change) the Draft registry hash.');
    }

    public function test_a_stale_save_does_not_change_the_registry_snapshot(): void
    {
        $user = $this->fullAccessUser();
        $layout = $this->bootstrapLayout($user);
        $instance = $this->createReadyInstanceVersion($user);

        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::STACK_SLOT),
        ])->assertOk();
        $hashAfterFirst = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;

        // Stale expected_lock_version (still the pre-save value) plus a
        // structurally DIFFERENT reference set -- if staleness were not
        // checked first, this would change the hash.
        $this->saveDraft($user, $layout['uuid'], $layout['version'], $layout['lock_version'], [
            $this->globalPlacement($instance, self::SINGLE_SLOT),
        ])->assertStatus(409);

        $hashAfterStale = WidgetLayoutVersion::query()->firstOrFail()->registry_contract_hash;
        $this->assertSame($hashAfterFirst, $hashAfterStale, 'A stale/failed save must change neither Placements nor the registry snapshot.');
    }
}
