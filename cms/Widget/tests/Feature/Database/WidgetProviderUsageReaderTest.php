<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Rendering\DataProviders\DatabaseProviderUsageReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: {@see DatabaseProviderUsageReader} proven against real
 * `widget_instance_versions` rows (plan Task 13b checklist / frozen
 * contracts: "retained Instance-Version provider references from real
 * rows"). Rows are built with raw `DB::table()` inserts (the same
 * established pattern as Task 13a's WidgetDatabaseLifecycleTest /
 * WidgetDatabaseIntegrityTest) since no Instance-Version write service
 * exists yet (Task 16+) -- this file's own concern is the READER, not the
 * write path.
 */
final class WidgetProviderUsageReaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() (Task 13a).
     */
    protected function beforeRefreshingDatabase(): void
    {
        // See
        // \Cms\Widget\Tests\Feature\Database\WidgetArtifactBlobRepositoryTest::beforeRefreshingDatabase()
        // for the full explanation: force a real migrate:fresh for THIS
        // class regardless of what an earlier-running, non-Widget-scoped
        // RefreshDatabase test left the process-wide
        // RefreshDatabaseState::$migrated flag set to.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * See WidgetSiteLayoutSchemaTest::migrateFreshUsing() (Task 13a).
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'cms/Widget/database/migrations',
            '--realpath' => false,
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ];
    }

    /**
     * See WidgetSiteLayoutSchemaTest::tearDownAfterClass() (Task 13a).
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_unreferenced_provider_version_reports_false_and_empty_list_on_a_fresh_schema(): void
    {
        $reader = new DatabaseProviderUsageReader();

        $this->assertFalse($reader->isReferenced('post', 1));
        $this->assertSame([], $reader->referencedProviderVersions());
    }

    public function test_isReferenced_is_true_for_a_provider_key_version_present_in_a_retained_row(): void
    {
        $this->insertInstanceVersionWithProviderRefs('ExampleProject', [
            ['key' => 'post', 'version' => 1],
        ]);

        $reader = new DatabaseProviderUsageReader();

        $this->assertTrue($reader->isReferenced('post', 1));
        $this->assertFalse($reader->isReferenced('post', 2), 'A DIFFERENT version of the same key must not be reported as referenced.');
        $this->assertFalse($reader->isReferenced('product', 1), 'A DIFFERENT key must not be reported as referenced.');
    }

    public function test_referencedProviderVersions_returns_a_distinct_list_across_multiple_rows(): void
    {
        $this->insertInstanceVersionWithProviderRefs('ExampleProject', [
            ['key' => 'post', 'version' => 1],
            ['key' => 'menu.by_group', 'version' => 2],
        ]);
        $this->insertInstanceVersionWithProviderRefs('ExampleProject', [
            // Duplicate reference to {post, 1} from a second Instance
            // Version must not produce a duplicate entry in the distinct list.
            ['key' => 'post', 'version' => 1],
            ['key' => 'product', 'version' => 1],
        ]);

        $reader = new DatabaseProviderUsageReader();
        $usages = $reader->referencedProviderVersions();

        $this->assertCount(3, $usages, 'Exactly three DISTINCT {key, version} pairs across both rows.');

        $identities = array_map(static fn (array $usage): string => $usage['key'] . '@' . $usage['version'], $usages);
        sort($identities);
        $this->assertSame(['menu.by_group@2', 'post@1', 'product@1'], $identities);
    }

    public function test_reader_reports_usage_from_every_retained_row_since_instance_versions_are_never_deleted(): void
    {
        // Two Instance Versions of the SAME Instance (version 1 then 2) --
        // both rows are retained (immutable, no UPDATED_AT, no destructive
        // cascade), so a provider referenced only by the OLDER version must
        // still be reported as referenced.
        $project = 'ExampleProject';
        $instanceId = DB::table(WidgetTableConstant::INSTANCE)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'name' => 'Header banner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertInstanceVersionWithProviderRefs($project, [['key' => 'legacy_post', 'version' => 1]], $instanceId, 1);
        $this->insertInstanceVersionWithProviderRefs($project, [['key' => 'legacy_banner', 'version' => 1]], $instanceId, 2);

        $reader = new DatabaseProviderUsageReader();

        $this->assertTrue($reader->isReferenced('legacy_post', 1), 'The OLDER retained version must still count as referenced.');
        $this->assertTrue($reader->isReferenced('legacy_banner', 1));
    }

    public function test_entries_with_a_non_numeric_version_are_skipped_defensively(): void
    {
        $this->insertInstanceVersionWithProviderRefs('ExampleProject', [
            ['key' => 'malformed', 'version' => 'not-a-number'],
            ['key' => 'well_formed', 'version' => 3],
        ]);

        $reader = new DatabaseProviderUsageReader();
        $usages = $reader->referencedProviderVersions();

        $this->assertCount(1, $usages);
        $this->assertSame('well_formed', $usages[0]['key']);
        $this->assertSame(3, $usages[0]['version']);
    }

    /**
     * @param list<array{key: string, version: int|string}> $providerRefs
     */
    private function insertInstanceVersionWithProviderRefs(string $project, array $providerRefs, ?int $instanceId = null, int $version = 1): int
    {
        $instanceId ??= DB::table(WidgetTableConstant::INSTANCE)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'name' => 'Header banner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hash = Str::random(16);
        $blobId = DB::table(WidgetTableConstant::ARTIFACT_BLOB)->insertGetId([
            'project_name' => $project,
            'artifact_hash' => str_pad(substr($hash, 0, 64), 64, '0'),
            'storage_disk' => 'local',
            'storage_key' => "generated_widgets/{$project}/{$hash}",
            'byte_size' => 1024,
            'media_type' => 'text/x-blade',
            'checksum' => str_pad(substr($hash, 0, 64), 64, '0'),
            'created_at' => now(),
        ]);

        $manifestId = DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->insertGetId([
            'project_name' => $project,
            'manifest_hash' => str_pad(substr(Str::random(16), 0, 64), 64, '0'),
            'artifact_blob_id' => $blobId,
            'generator_version' => '1.0.0',
            'renderer_key' => 'legacy_inline',
            'renderer_version' => '1.0.0',
            'applicability' => json_encode([$project]),
            'executable_surface_fingerprint_hash' => str_repeat('b', 64),
            'canonical_schema_hash' => str_repeat('c', 64),
            'provider_contracts' => json_encode([]),
            'trust_policy_version' => '1',
            'trust_lane' => 'legacy',
            'eligibility_state' => 'pending',
            'attestation_version' => 1,
            'control_version' => 1,
            'blocked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table(WidgetTableConstant::INSTANCE_VERSION)->insertGetId([
            'instance_id' => $instanceId,
            'project_name' => $project,
            'version' => $version,
            'widget_id' => 1,
            'widget_theme_id' => 1,
            'renderer_key' => 'legacy_inline',
            'renderer_version' => '1.0.0',
            'trust_classification' => 'legacy',
            'schema' => json_encode(['type' => 'object']),
            'schema_hash' => str_repeat('d', 64),
            'values' => json_encode(['title' => 'Hello']),
            'provider_refs' => json_encode($providerRefs),
            'artifact_manifest_id' => $manifestId,
            'created_at' => now(),
        ]);
    }
}
