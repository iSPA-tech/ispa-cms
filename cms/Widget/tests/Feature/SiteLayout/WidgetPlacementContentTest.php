<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetIdempotencyKey;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\TestCase;

/**
 * Unified Widget Catalog Part 2, Task 3: every Site Layout placement owns its content. Adding a
 * widget to an area creates its instance with it, editing content touches only that placement,
 * duplicating copies the instance, and a removed placement's instance is archived on publish.
 */
final class WidgetPlacementContentTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.placement-content.slot';

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

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-placement-content-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->app->make(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.placement-content.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

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
        $this->layout = $this->emptyLayout();
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

    /**
     * Only the layout permissions: placing a widget and editing its content needs no instance
     * or approval permission (spec "Permissions").
     */
    private function layoutEditor(): User
    {
        $user = User::forceCreate([
            'name' => 'Area Editor',
            'email' => 'area-editor-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => UserType::ADMIN,
        ]);

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => 'Area editor ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach (['widget_layout.view', 'widget_layout.update', 'widget_layout.publish'] as $key) {
            [$group, $name] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->where('group', $group)->where('name', $name)->firstOrFail()->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function emptyLayout(): WidgetLayout
    {
        $layout = WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => 'site']);
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);
        $contracts = ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
        $draft = WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
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
     */
    private function addPlacement(array $values, ?int $revision = null, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key ?? 'add-' . uniqid('', true)])
            ->postJson(self::BASE . $this->layout->uuid . '/draft/placements', [
                'slot_key' => self::SLOT,
                'scope_type' => 'global',
                'widget_id' => $this->widget->id,
                'widget_theme_id' => $this->theme->id,
                'values' => $values,
                'expected_draft_revision' => $revision ?? $this->draft()->lock_version,
            ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function editContent(string $placementUuid, array $values, ?int $revision = null): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'edit-' . uniqid('', true)])
            ->patchJson(self::BASE . $this->layout->uuid . '/draft/placements/' . $placementUuid . '/content', [
                'values' => $values,
                'expected_draft_revision' => $revision ?? $this->draft()->lock_version,
            ]);
    }

    private function duplicate(string $placementUuid): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'dup-' . uniqid('', true)])
            ->postJson(self::BASE . $this->layout->uuid . '/draft/placements/' . $placementUuid . '/duplicate', [
                'expected_draft_revision' => $this->draft()->lock_version,
            ]);
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
     * Replaces the draft with only the given placements (the area editor's "remove").
     *
     * @param list<array<string, mixed>> $keep placement payloads from a draft response
     */
    private function saveDraftKeeping(array $keep): void
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
                ], $keep),
            ])
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function placementFor(TestResponse $response, string $instanceUuid): array
    {
        foreach ($response->json('data.placements') as $placement) {
            if ($placement['widget_instance_uuid'] === $instanceUuid) {
                return $placement;
            }
        }

        $this->fail("No placement for instance {$instanceUuid} in the draft response.");
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(string $instanceUuid): array
    {
        return WidgetInstance::query()->where('uuid', $instanceUuid)->firstOrFail()->currentVersion->values;
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    public function test_create_placement_with_content_in_one_call(): void
    {
        $response = $this->addPlacement(['title' => 'Hello']);

        $response->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonCount(1, 'data.placements')
            ->assertJsonPath('data.placements.0.slot_key', self::SLOT)
            ->assertJsonPath('data.placements.0.widget_instance_version', 1)
            ->assertJsonPath('meta.placement_uuid', $response->json('data.placements.0.uuid'));

        $this->assertSame(1, WidgetInstance::query()->count());
        $instance = WidgetInstance::query()->firstOrFail();
        $this->assertSame($response->json('data.placements.0.widget_instance_uuid'), $instance->uuid);
        $this->assertSame(['title' => 'Hello'], $instance->currentVersion->values);
        $this->assertSame((int) $this->widget->id, (int) $instance->currentVersion->widget_id);

        // Replaying the same request adds nothing.
        $replayKey = 'replay-' . uniqid('', true);
        $first = $this->addPlacement(['title' => 'Again'], key: $replayKey)->assertCreated();
        $replay = $this->addPlacement(['title' => 'Again'], $first->json('data.lock_version') - 1, $replayKey);
        $replay->assertSuccessful();
        $this->assertSame(2, WidgetInstance::query()->count());
        $this->assertSame(2, WidgetPlacement::query()->where('layout_version_id', $this->draft()->id)->count());
    }

    /**
     * The add drawer offers the designs Core `GET widget/admin-list?placeable=1` returns for the widget. Every offered
     * design must be accepted by the placement create; a Default design is offered (as on pages), a design of another
     * project is not.
     */
    public function test_every_design_the_add_drawer_offers_is_accepted(): void
    {
        $foreign = WidgetTheme::query()->create([
            'widget_id' => $this->widget->id, 'name' => 'Other site', 'content' => '<div>{title}</div>', 'project_name' => ['OtherProject'],
        ]);
        // Usable on pages here, so a shared area accepts it too.
        $shared = WidgetTheme::query()->create([
            'widget_id' => $this->widget->id, 'name' => 'Shared', 'content' => '<div>{title}</div>', 'project_name' => ['Default'],
        ]);
        $role = $this->user->roles()->firstOrFail();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => 'widget', 'name' => 'viewAny'], ['title' => 'widget viewAny'])->id);

        $rows = $this->actingAs($this->user->fresh(), 'sanctum')->getJson('/api/v2/admin/widget/admin-list?placeable=1')->assertOk()->json('data.data');
        $offered = collect(collect($rows)->firstWhere('id', $this->widget->id)['template'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertNotContains((int) $foreign->id, $offered->all());
        $this->assertContains((int) $shared->id, $offered->all());
        foreach ($offered as $themeId) {
            $this->theme = WidgetTheme::query()->findOrFail($themeId);
            $this->addPlacement(['title' => 'Offered'])->assertCreated();
        }
    }

    /**
     * a shared area accepts every design a page accepts. A Default design (also stored as no
     * project or an empty string) is placed, and its manifest is recorded and approved for this site's project only.
     */
    public function test_area_accepts_a_default_design_and_records_the_manifest_for_this_project(): void
    {
        foreach (['Default list' => ['Default'], 'no project' => null, 'empty string' => '', 'empty entry' => ['']] as $name => $projects) {
            $this->theme = WidgetTheme::query()->create([
                'widget_id' => $this->widget->id, 'name' => $name, 'content' => '<div>{title}</div>', 'project_name' => $projects,
            ]);

            $this->addPlacement(['title' => $name])->assertCreated();

            $manifests = WidgetArtifactManifest::query()->get();
            $this->assertSame([self::PROJECT], $manifests->pluck('project_name')->unique()->values()->all(), "{$name}: manifests stay on this project.");
        }

        $this->assertSame(4, WidgetPlacement::query()->where('layout_version_id', $this->draft()->id)->count());
        $this->assertSame(0, WidgetArtifactManifest::query()->where('project_name', 'Default')->count());
        $this->assertSame(
            [WidgetManifestApprovalService::STATE_APPROVED],
            WidgetArtifactManifest::query()->pluck('eligibility_state')->unique()->values()->all(),
        );
    }

    public function test_area_still_refuses_a_design_of_another_project(): void
    {
        $this->theme = WidgetTheme::query()->create([
            'widget_id' => $this->widget->id, 'name' => 'Other site', 'content' => '<div>{title}</div>', 'project_name' => ['OtherProject'],
        ]);

        $this->addPlacement(['title' => 'Nope'])->assertStatus(422);

        $this->assertSame(0, WidgetPlacement::query()->count());
        $this->assertSame(0, WidgetArtifactManifest::query()->count());
    }

    public function test_area_still_refuses_a_blocked_widget_with_a_default_design(): void
    {
        $this->theme = WidgetTheme::query()->create([
            'widget_id' => $this->widget->id, 'name' => 'Shared', 'content' => '<div>{title}</div>', 'project_name' => ['Default'],
        ]);
        $this->widget->forceFill(['blocked_at' => now()])->save();

        $this->addPlacement(['title' => 'Blocked'])->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_BLOCKED');
    }

    public function test_draft_content_names_the_widget_in_the_request_locale(): void
    {
        $this->widget->forceFill(['name_translations' => ['vi' => 'Tên tiếng Việt']])->save();
        $added = $this->addPlacement(['title' => 'Hello'])->assertCreated();
        $placement = $added->json('data.placements.0');
        $contentKey = $placement['widget_instance_uuid'] . ':' . $placement['widget_instance_version'];

        // This class runs without ApiLocalization: set the request locale directly.
        app()->setLocale('vi');
        $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')
            ->assertOk()
            ->assertJsonPath("data.contents.{$contentKey}.widget_name", (string) $this->widget->name)
            ->assertJsonPath("data.contents.{$contentKey}.widget_display_name", 'Tên tiếng Việt');
        app()->setLocale('en');
        $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')
            ->assertOk()
            ->assertJsonPath("data.contents.{$contentKey}.widget_display_name", (string) $this->widget->name);
    }

    public function test_draft_read_carries_each_placement_content_for_the_area_editor(): void
    {
        $added = $this->addPlacement(['title' => 'Hello'])->assertCreated();
        $placement = $added->json('data.placements.0');
        $contentKey = $placement['widget_instance_uuid'] . ':' . $placement['widget_instance_version'];

        // The add response and the draft read carry the same content map: the area editor needs no Instance route.
        $added->assertJsonPath("data.contents.{$contentKey}.values", ['title' => 'Hello']);

        $read = $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')->assertOk();
        $read->assertJsonPath("data.contents.{$contentKey}.widget_id", (int) $this->widget->id)
            ->assertJsonPath("data.contents.{$contentKey}.widget_theme_id", (int) $this->theme->id)
            ->assertJsonPath("data.contents.{$contentKey}.widget_name", (string) $this->widget->name)
            ->assertJsonPath("data.contents.{$contentKey}.widget_display_name", (string) $this->widget->name)
            ->assertJsonPath("data.contents.{$contentKey}.values", ['title' => 'Hello'])
            ->assertJsonPath("data.contents.{$contentKey}.is_blocked", false);
        $this->assertIsArray($read->json("data.contents.{$contentKey}.schema"));

        $this->widget->forceFill(['blocked_at' => now()])->save();

        $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')
            ->assertOk()
            ->assertJsonPath("data.contents.{$contentKey}.is_blocked", true);
    }

    public function test_content_edit_only_changes_this_placement(): void
    {
        $this->addPlacement(['title' => 'First'])->assertCreated();
        $second = $this->addPlacement(['title' => 'Second'])->assertCreated();

        $first = $second->json('data.placements.0');
        $other = $second->json('data.placements.1');

        $edited = $this->editContent($first['uuid'], ['title' => 'First edited']);

        $edited->assertOk()->assertJsonPath('data.lock_version', 3);
        $this->assertSame(2, WidgetInstance::query()->count(), 'Editing content never creates a new instance for a sole owner.');
        $this->assertSame(['title' => 'First edited'], $this->currentValues($first['widget_instance_uuid']));
        $this->assertSame(2, $this->placementFor($edited, $first['widget_instance_uuid'])['widget_instance_version']);
        $this->assertSame(['title' => 'Second'], $this->currentValues($other['widget_instance_uuid']));
        $this->assertSame(1, $this->placementFor($edited, $other['widget_instance_uuid'])['widget_instance_version']);

        // A legacy instance shared by two placements: editing one forks it for that placement only.
        $shared = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $this->user->id, 'legacy-shared', [
            'name' => 'Legacy shared',
            'widget_id' => $this->widget->id,
            'widget_theme_id' => $this->theme->id,
            'values' => ['title' => 'Shared'],
        ])->instance;
        $current = $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft')->json('data.placements');
        $legacy = ['widget_instance_uuid' => $shared->uuid, 'widget_instance_version' => 1, 'slot_key' => self::SLOT, 'scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'enabled' => true];
        $this->saveDraftKeeping([...$current, $legacy + ['position' => 2], $legacy + ['position' => 3]]);

        $sharedPlacements = WidgetPlacement::query()->where('layout_version_id', $this->draft()->id)->where('widget_instance_version_id', $shared->currentVersion->id)->orderBy('position')->get();
        $this->assertCount(2, $sharedPlacements);

        $forked = $this->editContent($sharedPlacements[0]->uuid, ['title' => 'Forked']);

        $forked->assertOk();
        $this->assertSame(['title' => 'Shared'], $this->currentValues($shared->uuid), 'The other placement keeps the shared content.');
        $this->assertSame(1, WidgetPlacement::query()->where('layout_version_id', $this->draft()->id)->where('widget_instance_version_id', $shared->currentVersion->id)->count());
        $forkedUuid = $forked->json('data.placements.2.widget_instance_uuid');
        $this->assertNotSame($shared->uuid, $forkedUuid);
        $this->assertSame(['title' => 'Forked'], $this->currentValues($forkedUuid));
        $this->assertSame($forked->json('data.placements.2.uuid'), $forked->json('meta.placement_uuid'));
    }

    public function test_duplicate_placement_owns_a_separate_instance(): void
    {
        $added = $this->addPlacement(['title' => 'Original'])->assertCreated();
        $source = $added->json('data.placements.0');

        $duplicated = $this->duplicate($source['uuid']);

        $duplicated->assertCreated()->assertJsonCount(2, 'data.placements');
        $copy = $duplicated->json('data.placements.1');
        $this->assertSame($copy['uuid'], $duplicated->json('meta.placement_uuid'));
        $this->assertNotSame($source['widget_instance_uuid'], $copy['widget_instance_uuid']);
        $this->assertSame(2, WidgetInstance::query()->count());
        $this->assertSame(['title' => 'Original'], $this->currentValues($copy['widget_instance_uuid']));

        // Editing the copy leaves the original alone.
        $this->editContent($copy['uuid'], ['title' => 'Copy edited'])->assertOk();
        $this->assertSame(['title' => 'Original'], $this->currentValues($source['widget_instance_uuid']));
        $this->assertSame(['title' => 'Copy edited'], $this->currentValues($copy['widget_instance_uuid']));
    }

    public function test_removing_a_placement_archives_its_instance_on_publish(): void
    {
        $this->addPlacement(['title' => 'Keep'])->assertCreated();
        $both = $this->addPlacement(['title' => 'Remove after publish'])->assertCreated();
        $keep = $both->json('data.placements.0');
        $removed = $both->json('data.placements.1');
        $this->publish();

        // A placement added and removed before any publish.
        $draftOnly = $this->addPlacement(['title' => 'Never published'])->assertCreated();
        $draftOnlyUuid = $draftOnly->json('data.placements.2.widget_instance_uuid');

        $current = $this->actingAs($this->user, 'sanctum')->getJson(self::BASE . $this->layout->uuid . '/draft');
        $this->saveDraftKeeping([$this->placementFor($current, $keep['widget_instance_uuid'])]);

        $status = static fn (string $uuid): string => (string) WidgetInstance::query()->where('uuid', $uuid)->value('status');
        $this->assertSame('active', $status($removed['widget_instance_uuid']), 'The live release still shows it until publish.');

        $this->publish();

        $this->assertSame('archived', $status($removed['widget_instance_uuid']));
        $this->assertSame('archived', $status($draftOnlyUuid));
        $this->assertSame('active', $status($keep['widget_instance_uuid']));
    }

    public function test_draft_revision_conflict_rejected(): void
    {
        $added = $this->addPlacement(['title' => 'One'])->assertCreated();
        $placement = $added->json('data.placements.0');

        $stale = $this->addPlacement(['title' => 'Stale'], revision: 0);

        $stale->assertStatus(409)->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(1, WidgetInstance::query()->count(), 'A rejected add leaves no instance behind.');
        $this->assertSame(0, WidgetIdempotencyKey::query()->where('state', WidgetIdempotencyKey::STATE_PROCESSING)->count());

        $this->editContent($placement['uuid'], ['title' => 'Stale edit'], revision: 0)
            ->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(['title' => 'One'], $this->currentValues($placement['widget_instance_uuid']));

        $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'dup-stale'])
            ->postJson(self::BASE . $this->layout->uuid . '/draft/placements/' . $placement['uuid'] . '/duplicate', ['expected_draft_revision' => 0])
            ->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_DRAFT_STALE');
        $this->assertSame(1, WidgetInstance::query()->count());
    }
}
