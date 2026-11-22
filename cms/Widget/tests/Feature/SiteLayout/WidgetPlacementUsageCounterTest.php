<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Contracts\Widget\WidgetAreaUsageCounter;
use Cms\Widget\Constants\WidgetTableConstant as T;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\SiteLayout\WidgetPlacementUsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unified Widget Catalog Part 2, Task 4: the widget library's area count. Distinct placements of the current
 * project's layouts in the current draft and live release whose pinned instance version shows the widget; a
 * placement present in both counts once.
 */
final class WidgetPlacementUsageCounterTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const WIDGET = 501;

    private const OTHER_WIDGET = 502;

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
    }

    private function layout(string $project, string $key = 'site'): int
    {
        return (int) DB::table(T::LAYOUT)->insertGetId([
            'uuid' => (string) Str::uuid(), 'project_name' => $project, 'key' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function version(int $layoutId, string $project, int $version, string $state): int
    {
        return (int) DB::table(T::LAYOUT_VERSION)->insertGetId([
            'layout_id' => $layoutId, 'project_name' => $project, 'version' => $version, 'state' => $state,
            'registry_contracts' => '{}', 'registry_contract_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function point(int $layoutId, ?int $draftId, ?int $publishedId): void
    {
        DB::table(T::LAYOUT)->where('id', $layoutId)->update([
            'current_draft_version_id' => $draftId, 'current_published_version_id' => $publishedId,
        ]);
    }

    /** @var array<string, int> project => manifest id */
    private array $manifests = [];

    /** One artifact manifest per project: instance versions reference it (composite foreign key). */
    private function manifest(string $project): int
    {
        if (isset($this->manifests[$project])) {
            return $this->manifests[$project];
        }
        $blob = (int) DB::table(T::ARTIFACT_BLOB)->insertGetId([
            'project_name' => $project, 'artifact_hash' => hash('sha256', $project), 'storage_disk' => 'local',
            'storage_key' => 'k-' . $project, 'byte_size' => 1, 'media_type' => 'text/html', 'checksum' => 'x',
        ]);

        return $this->manifests[$project] = (int) DB::table(T::ARTIFACT_MANIFEST)->insertGetId([
            'project_name' => $project, 'manifest_hash' => hash('sha256', 'm' . $project), 'artifact_blob_id' => $blob,
            'generator_version' => '1', 'renderer_key' => 'legacy', 'renderer_version' => '1', 'applicability' => '{}',
            'executable_surface_fingerprint_hash' => str_repeat('0', 64), 'canonical_schema_hash' => str_repeat('0', 64),
            'provider_contracts' => '[]', 'trust_policy_version' => '1', 'trust_lane' => 'legacy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: int} instance id, instance version id */
    private function makeInstance(string $project, int $widgetId, ?int $instanceId = null, int $version = 1, int $themeId = 1): array
    {
        $instanceId ??= (int) DB::table(T::INSTANCE)->insertGetId([
            'uuid' => (string) Str::uuid(), 'project_name' => $project, 'name' => 'i', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = (int) DB::table(T::INSTANCE_VERSION)->insertGetId([
            'instance_id' => $instanceId, 'project_name' => $project, 'version' => $version, 'widget_id' => $widgetId,
            'widget_theme_id' => $themeId, 'renderer_key' => 'legacy', 'renderer_version' => '1', 'trust_classification' => 'trusted',
            'schema' => '{}', 'schema_hash' => str_repeat('0', 64), 'values' => '{}', 'provider_refs' => '[]',
            'artifact_manifest_id' => $this->manifest($project),
        ]);

        return [$instanceId, $versionId];
    }

    private function place(int $layoutVersionId, string $project, int $instanceVersionId, string $slot = 'site.header', int $position = 0): void
    {
        DB::table(T::PLACEMENT)->insert([
            'uuid' => (string) Str::uuid(), 'project_name' => $project, 'layout_version_id' => $layoutVersionId,
            'widget_instance_version_id' => $instanceVersionId, 'slot_key' => $slot, 'scope_type' => 'global',
            'scope_identity' => 'global', 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_counts_draft_and_live_placements_once_in_the_current_project_only(): void
    {
        $layout = $this->layout(self::PROJECT);
        $release = $this->version($layout, self::PROJECT, 1, 'release');
        $draft = $this->version($layout, self::PROJECT, 2, 'draft');
        $old = $this->version($layout, self::PROJECT, 0, 'superseded');
        $this->point($layout, $draft, $release);

        // Placement A: in the release and copied into the draft (moved to position 3) -> once.
        [$instanceA, $a1] = $this->makeInstance(self::PROJECT, self::WIDGET);
        $this->place($release, self::PROJECT, $a1);
        [, $a2] = $this->makeInstance(self::PROJECT, self::WIDGET, $instanceA, 2);
        $this->place($draft, self::PROJECT, $a2, 'site.header', 3);
        // Placement B: new in the draft only.
        [, $b] = $this->makeInstance(self::PROJECT, self::WIDGET);
        $this->place($draft, self::PROJECT, $b, 'site.footer');
        // Placement C: live only (removed from the draft, still on the site until publish).
        [, $c] = $this->makeInstance(self::PROJECT, self::WIDGET);
        $this->place($release, self::PROJECT, $c, 'site.sidebar');
        // Not counted: a superseded version, another widget.
        [, $d] = $this->makeInstance(self::PROJECT, self::WIDGET);
        $this->place($old, self::PROJECT, $d);
        [, $e] = $this->makeInstance(self::PROJECT, self::OTHER_WIDGET);
        $this->place($draft, self::PROJECT, $e);

        // Another project's layout using the same widget is never counted.
        $otherLayout = $this->layout('OtherProject');
        $otherDraft = $this->version($otherLayout, 'OtherProject', 1, 'draft');
        $this->point($otherLayout, $otherDraft, null);
        [, $f] = $this->makeInstance('OtherProject', self::WIDGET);
        $this->place($otherDraft, 'OtherProject', $f);

        $counter = app(WidgetPlacementUsageCounter::class);

        $this->assertSame(3, $counter->count(self::WIDGET));
        $this->assertSame([self::WIDGET => 3, self::OTHER_WIDGET => 1, 999 => 0], $counter->countMany([self::WIDGET, self::OTHER_WIDGET, 999]));

        config(['ispa-cms.folder_name' => 'OtherProject']);
        $this->assertSame(1, $counter->count(self::WIDGET));
    }

    public function test_theme_ids_in_use_lists_current_placements_only(): void
    {
        $layout = $this->layout(self::PROJECT);
        $release = $this->version($layout, self::PROJECT, 1, 'release');
        $draft = $this->version($layout, self::PROJECT, 2, 'draft');
        $old = $this->version($layout, self::PROJECT, 0, 'superseded');
        $this->point($layout, $draft, $release);

        [, $a] = $this->makeInstance(self::PROJECT, self::WIDGET, themeId: 7);
        $this->place($release, self::PROJECT, $a);
        [, $b] = $this->makeInstance(self::PROJECT, self::WIDGET, themeId: 3);
        $this->place($draft, self::PROJECT, $b, 'site.footer');
        [, $c] = $this->makeInstance(self::PROJECT, self::WIDGET, themeId: 7);
        $this->place($draft, self::PROJECT, $c, 'site.sidebar');
        // Not in use: a superseded version, another widget.
        [, $d] = $this->makeInstance(self::PROJECT, self::WIDGET, themeId: 9);
        $this->place($old, self::PROJECT, $d);
        [, $e] = $this->makeInstance(self::PROJECT, self::OTHER_WIDGET, themeId: 11);
        $this->place($draft, self::PROJECT, $e);

        $counter = app(WidgetPlacementUsageCounter::class);
        $this->assertSame([3, 7], $counter->themeIdsInUse(self::WIDGET));
        $this->assertSame([], $counter->themeIdsInUse(999));
    }

    public function test_no_layouts_counts_zero(): void
    {
        $this->assertSame([self::WIDGET => 0], app(WidgetPlacementUsageCounter::class)->countMany([self::WIDGET]));
        $this->assertSame([], app(WidgetPlacementUsageCounter::class)->countMany([]));
    }

    public function test_module_binds_the_root_counter(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertInstanceOf(WidgetPlacementUsageCounter::class, app(WidgetAreaUsageCounter::class));
    }
}
