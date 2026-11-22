<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Events\Widget\WidgetCloned;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetCloneResult;
use Cms\Core\Services\Widget\Catalog\WidgetCloneService;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\TestCase;

/**
 * Unified Widget Catalog Part 2, Task 2: when a hub widget is cloned for the site, every area
 * placement in the current layout drafts that shows the hub widget moves to the clone (same
 * values, mapped theme) through the existing repin path. Live releases stay on the hub widget
 * until "Xuất bản", and the draft revision moves so an open editor tab gets the usual conflict.
 */
final class RepinPlacementsToCloneTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const OTHER_PROJECT = 'OtherProject';

    private const SLOT = 'test.repin-clone.slot';

    private const BASE = '/api/v2/admin/widget/layouts/';

    private string $storageRoot;

    /** @var array<string, string|null> */
    private static array $modulesStatusesSnapshot = [];

    private Widget $hub;

    private WidgetTheme $hubTheme;

    private Widget $other;

    private WidgetTheme $otherTheme;

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

        // Cloning publishes generated templates: keep them (and artifact blobs) out of the tracked tree.
        $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-repin-clone-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->storageRoot . '/app');
        $this->app->useStoragePath($this->storageRoot);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot . '/blobs')
        );

        $this->app->make(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.repin-clone.slot.label',
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

        $this->hub = Widget::query()->forceCreate([
            'widget_key' => 'ispa.area_banner',
            'name' => 'Area banner',
            'description' => '',
            'js' => '',
            'origin' => Widget::ORIGIN_HUB,
            'publisher' => 'ispa',
        ]);
        $this->hubTheme = WidgetTheme::query()->forceCreate([
            'widget_id' => $this->hub->id,
            'theme_key' => 'default',
            'name' => 'Default',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);

        $this->other = Widget::query()->forceCreate([
            'widget_key' => 'ispa.area_other',
            'name' => 'Area other',
            'description' => '',
            'js' => '',
            'origin' => Widget::ORIGIN_HUB,
            'publisher' => 'ispa',
        ]);
        $this->otherTheme = WidgetTheme::query()->forceCreate([
            'widget_id' => $this->other->id,
            'theme_key' => 'default',
            'name' => 'Default',
            'content' => '<p>{title}</p>',
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);

        $this->user = $this->layoutEditor();
        $this->layout = $this->emptyLayout('site');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    private function emptyLayout(string $key, string $project = self::PROJECT): WidgetLayout
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

    private function draft(?WidgetLayout $layout = null): WidgetLayoutVersion
    {
        return WidgetLayoutVersion::query()->findOrFail(($layout ?? $this->layout)->fresh()->current_draft_version_id);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function addPlacement(array $values, ?Widget $widget = null, ?WidgetTheme $theme = null, ?WidgetLayout $layout = null): TestResponse
    {
        $layout ??= $this->layout;

        return $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'add-' . uniqid('', true)])
            ->postJson(self::BASE . $layout->uuid . '/draft/placements', [
                'slot_key' => self::SLOT,
                'scope_type' => 'global',
                'widget_id' => ($widget ?? $this->hub)->id,
                'widget_theme_id' => ($theme ?? $this->hubTheme)->id,
                'values' => $values,
                'expected_draft_revision' => $this->draft($layout)->lock_version,
            ])
            ->assertCreated();
    }

    private function publish(?WidgetLayout $layout = null): void
    {
        $layout ??= $this->layout;
        $draft = $this->draft($layout);
        $this->actingAs($this->user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'publish-' . uniqid('', true)])
            ->postJson(self::BASE . $layout->uuid . '/publish', [
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ])
            ->assertOk();
    }

    private function cloneHub(): WidgetCloneResult
    {
        return app(WidgetCloneService::class)->cloneForSite($this->hub, (int) $this->user->id);
    }

    /**
     * Pinned versions of the placements in a layout version, ordered by position.
     *
     * @return list<WidgetInstanceVersion>
     */
    private function pins(WidgetLayoutVersion $version): array
    {
        return WidgetPlacement::query()
            ->where('layout_version_id', $version->id)
            ->orderBy('position')
            ->with('instanceVersion')
            ->get()
            ->map(static fn (WidgetPlacement $p): WidgetInstanceVersion => $p->instanceVersion)
            ->all();
    }

    /**
     * A hub placement written straight into a layout draft (an instance owned by that placement).
     *
     * @param array<string, mixed> $values
     */
    private function placeDirectly(WidgetLayout $layout, WidgetTheme $theme, array $values, int $position = 0): WidgetPlacement
    {
        $project = (string) $layout->project_name;
        $instance = app(WidgetInstanceService::class)->create($project, (int) $this->user->id, 'direct-' . uniqid('', true), [
            'name' => 'Direct ' . $position,
            'widget_id' => $theme->widget_id,
            'widget_theme_id' => $theme->id,
            'values' => $values,
        ])->instance;
        $this->assertNotNull($instance, 'Fixture instance was created.');

        return WidgetPlacement::query()->create([
            'layout_version_id' => $this->draft($layout)->id,
            'project_name' => $project,
            'slot_key' => self::SLOT,
            'scope_type' => WidgetPlacement::SCOPE_GLOBAL,
            'scope_identity' => WidgetPlacement::SCOPE_GLOBAL,
            'widget_instance_version_id' => $instance->current_version_id,
            'position' => $position,
            'enabled' => true,
        ]);
    }

    private function secondHubTheme(): WidgetTheme
    {
        return WidgetTheme::query()->forceCreate([
            'widget_id' => $this->hub->id,
            'theme_key' => 'dark',
            'name' => 'Dark',
            'content' => '<div class="dark">{title}</div>',
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    public function test_clone_repins_draft_placements_and_leaves_the_live_release_unchanged(): void
    {
        $this->addPlacement(['title' => 'Hub one']);
        $this->addPlacement(['title' => 'Other'], $this->other, $this->otherTheme);
        $this->publish();
        $release = WidgetLayoutVersion::query()->findOrFail($this->layout->fresh()->current_published_version_id);

        // A second layout of the site with its own hub placement, plus a legacy instance shared by two placements.
        $second = $this->emptyLayout('second');
        $this->addPlacement(['title' => 'Hub two'], layout: $second);
        $shared = app(WidgetInstanceService::class)->create(self::PROJECT, (int) $this->user->id, 'legacy-shared', [
            'name' => 'Legacy shared',
            'widget_id' => $this->hub->id,
            'widget_theme_id' => $this->hubTheme->id,
            'values' => ['title' => 'Shared'],
        ])->instance;
        $secondDraft = $this->draft($second);
        foreach ([1, 2] as $position) {
            WidgetPlacement::query()->create([
                'layout_version_id' => $secondDraft->id,
                'project_name' => self::PROJECT,
                'slot_key' => self::SLOT,
                'scope_type' => WidgetPlacement::SCOPE_GLOBAL,
                'scope_identity' => WidgetPlacement::SCOPE_GLOBAL,
                'widget_instance_version_id' => $shared->current_version_id,
                'position' => $position,
                'enabled' => true,
            ]);
        }

        $releasePinsBefore = WidgetPlacement::query()->where('layout_version_id', $release->id)->orderBy('position')->pluck('widget_instance_version_id')->all();

        $result = $this->cloneHub();
        $cloneId = (int) $result->clone->id;
        $cloneThemeId = $result->themeIdMap[$this->hubTheme->id];

        $pins = $this->pins($this->draft());
        $this->assertSame($cloneId, (int) $pins[0]->widget_id, 'The hub placement now shows the clone.');
        $this->assertSame($cloneThemeId, (int) $pins[0]->widget_theme_id);
        $this->assertSame((int) $this->other->id, (int) $pins[1]->widget_id, 'Placements of other widgets are untouched.');

        $secondPins = $this->pins($this->draft($second));
        $this->assertCount(3, $secondPins);
        foreach ($secondPins as $pin) {
            $this->assertSame($cloneId, (int) $pin->widget_id, 'Every layout draft follows the clone.');
            $this->assertSame($cloneThemeId, (int) $pin->widget_theme_id);
        }
        $this->assertCount(3, array_unique(array_map(static fn (WidgetInstanceVersion $v): int => (int) $v->instance_id, $secondPins)), 'A shared legacy instance is forked per placement.');

        // The live release still pins the hub widget until "Xuất bản".
        $this->assertSame($releasePinsBefore, WidgetPlacement::query()->where('layout_version_id', $release->id)->orderBy('position')->pluck('widget_instance_version_id')->all());
        $this->assertSame((int) $this->hub->id, (int) $this->pins($release)[0]->widget_id);
        $this->assertSame($release->id, (int) $this->layout->fresh()->current_published_version_id);
    }

    public function test_values_carried_over_unchanged(): void
    {
        $values = ['title' => 'Chào mừng – "Onni"', 'link' => 'https://x.test/a/b', 'items' => [['n' => 1.5, 'on' => true]]];
        $this->addPlacement($values);

        $result = $this->cloneHub();

        $pin = $this->pins($this->draft())[0];
        $this->assertSame((int) $result->clone->id, (int) $pin->widget_id);
        $this->assertSame($values, $pin->values);
    }

    /**
     * shared areas accept Default designs like pages, so a clone whose mapped design is
     * Default (the hub design was Default and the clone copies its project list) is repinned too, with the clone's
     * manifest recorded for this site's project.
     */
    public function test_clone_whose_mapped_design_is_default_is_repinned(): void
    {
        $this->addPlacement(['title' => 'Hub']);
        $this->hubTheme->forceFill(['project_name' => ['Default']])->save();

        $result = $this->cloneHub();
        $cloneThemeId = $result->themeIdMap[$this->hubTheme->id];
        $this->assertSame(['Default'], WidgetTheme::query()->findOrFail($cloneThemeId)->project_name);

        $pin = $this->pins($this->draft())[0];
        $this->assertSame((int) $result->clone->id, (int) $pin->widget_id);
        $this->assertSame($cloneThemeId, (int) $pin->widget_theme_id);
        $this->assertSame(self::PROJECT, (string) WidgetArtifactManifest::query()->findOrFail($pin->artifact_manifest_id)->project_name);
    }

    public function test_stale_draft_save_after_clone_repin_is_rejected_with_the_existing_draft_conflict(): void
    {
        $added = $this->addPlacement(['title' => 'Open in a tab']);
        $placement = $added->json('data.placements.0');
        $staleDraft = $this->draft();

        $this->cloneHub();

        $this->assertGreaterThan($staleDraft->lock_version, $this->draft()->lock_version, 'The repin moves the draft revision.');

        $this->actingAs($this->user, 'sanctum')
            ->putJson(self::BASE . $this->layout->uuid . '/draft', [
                'expected_current_draft_id' => $staleDraft->version,
                'expected_lock_version' => $staleDraft->lock_version,
                'placements' => [[
                    'slot_key' => $placement['slot_key'],
                    'scope_type' => $placement['scope_type'],
                    'scope_key' => $placement['scope_key'],
                    'target_type' => $placement['target_type'],
                    'target_id' => $placement['target_id'],
                    'widget_instance_uuid' => $placement['widget_instance_uuid'],
                    'widget_instance_version' => $placement['widget_instance_version'],
                    'position' => $placement['position'],
                    'enabled' => $placement['enabled'],
                    'rules' => [],
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_DRAFT_STALE');

        $this->assertNotSame((int) $this->hub->id, (int) $this->pins($this->draft())[0]->widget_id, 'The stale tab did not undo the repin.');
    }

    public function test_no_layout_or_no_matching_placement_is_a_no_op(): void
    {
        // A layout with only another widget's placement.
        $this->addPlacement(['title' => 'Other'], $this->other, $this->otherTheme);
        $before = $this->draft();
        $pinBefore = $this->pins($before)[0]->id;

        $this->cloneHub();

        $after = $this->draft();
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->lock_version, $after->lock_version);
        $this->assertSame($pinBefore, $this->pins($after)[0]->id);

        // No layout at all.
        WidgetPlacement::query()->delete();
        WidgetLayout::query()->update(['current_draft_version_id' => null, 'current_published_version_id' => null]);
        WidgetLayoutVersion::query()->delete();
        WidgetLayout::query()->delete();

        $result = $this->cloneHub();

        $this->assertSame(Widget::ORIGIN_SITE, $result->clone->origin);
        $this->assertSame(0, WidgetLayoutVersion::query()->count());
    }

    public function test_layouts_of_another_project_stay_untouched(): void
    {
        $this->hubTheme->forceFill(['project_name' => [self::PROJECT, self::OTHER_PROJECT]])->save();
        $this->addPlacement(['title' => 'Mine']);

        $other = $this->emptyLayout('site', self::OTHER_PROJECT);
        $this->placeDirectly($other, $this->hubTheme, ['title' => 'Theirs']);
        $otherDraft = $this->draft($other);
        $otherPin = $this->pins($otherDraft)[0]->id;
        $otherManifests = WidgetArtifactManifest::query()->where('project_name', self::OTHER_PROJECT)->orderBy('id')->get(['id', 'eligibility_state'])->toArray();

        $result = $this->cloneHub();

        $this->assertSame((int) $result->clone->id, (int) $this->pins($this->draft())[0]->widget_id, 'The cloning project follows the clone.');
        $after = $this->draft($other);
        $this->assertSame($otherDraft->id, $after->id);
        $this->assertSame($otherDraft->lock_version, $after->lock_version);
        $this->assertSame($otherPin, $this->pins($after)[0]->id);
        $this->assertSame((int) $this->hub->id, (int) $this->pins($after)[0]->widget_id);
        $this->assertSame($otherManifests, WidgetArtifactManifest::query()->where('project_name', self::OTHER_PROJECT)->orderBy('id')->get(['id', 'eligibility_state'])->toArray());
    }

    public function test_theme_without_a_map_entry_stays_on_the_hub_widget(): void
    {
        $dark = $this->secondHubTheme();
        $result = $this->cloneHub();
        $this->placeDirectly($this->layout, $this->hubTheme, ['title' => 'Mapped'], 0);
        $this->placeDirectly($this->layout, $dark, ['title' => 'Unmapped'], 1);

        event(new WidgetCloned((string) $this->hub->widget_key, (int) $result->clone->id, [
            (int) $this->hubTheme->id => (int) $result->themeIdMap[$this->hubTheme->id],
        ], (int) $this->user->id));

        $pins = $this->pins($this->draft());
        $this->assertSame((int) $result->clone->id, (int) $pins[0]->widget_id);
        $this->assertSame((int) $this->hub->id, (int) $pins[1]->widget_id, 'No clone theme to map to: the placement keeps the hub widget.');
        $this->assertSame((int) $dark->id, (int) $pins[1]->widget_theme_id);
    }

    public function test_a_failure_on_one_layout_does_not_stop_the_next(): void
    {
        $dark = $this->secondHubTheme();
        $result = $this->cloneHub();
        // First layout: its theme maps to a theme of another widget, so the repin is refused.
        $this->placeDirectly($this->layout, $dark, ['title' => 'Broken map']);
        $second = $this->emptyLayout('second');
        $this->placeDirectly($second, $this->hubTheme, ['title' => 'Fine']);

        event(new WidgetCloned((string) $this->hub->widget_key, (int) $result->clone->id, [
            (int) $dark->id => (int) $this->otherTheme->id,
            (int) $this->hubTheme->id => (int) $result->themeIdMap[$this->hubTheme->id],
        ], (int) $this->user->id));

        $this->assertSame((int) $this->hub->id, (int) $this->pins($this->draft())[0]->widget_id);
        $this->assertSame((int) $result->clone->id, (int) $this->pins($this->draft($second))[0]->widget_id);
    }

    public function test_a_draft_saved_between_two_repins_is_retried_once(): void
    {
        $this->addPlacement(['title' => 'One']);
        $this->addPlacement(['title' => 'Two']);
        $start = $this->draft()->lock_version;

        // Simulates another editor saving the draft right after the first repin: outside any repin
        // transaction, the content service reads the repinned draft first and the listener re-reads
        // it second; on that second read the stored revision moves on underneath the listener.
        $armed = true;
        $reads = 0;
        $baseLevel = DB::transactionLevel();
        WidgetLayoutVersion::retrieved(static function (WidgetLayoutVersion $version) use (&$armed, &$reads, $start, $baseLevel): void {
            if (!$armed || DB::transactionLevel() !== $baseLevel || $version->state !== WidgetLayoutVersion::STATE_DRAFT || (int) $version->lock_version !== $start + 1) {
                return;
            }
            if (++$reads === 2) {
                $armed = false;
                DB::table('widget_layout_versions')->where('id', $version->id)->increment('lock_version');
            }
        });

        $result = $this->cloneHub();

        $this->assertFalse($armed, 'The concurrent save was simulated.');
        foreach ($this->pins($this->draft()) as $pin) {
            $this->assertSame((int) $result->clone->id, (int) $pin->widget_id, 'Both placements follow the clone after one retry.');
        }
    }
}
