<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Direct negative integrity proof for the project-scoped composite foreign
 * keys this schema introduces (plan Chunk 2 Task 13a; ADR
 * docs/architecture/adr-widget-instance-scope-schema.md decision 6;
 * docs/architecture/adr-widget-database-portability.md decision 6).
 *
 * Every write here goes through raw `DB::table()` insert/update, bypassing
 * application validation entirely, so a pass proves the DATABASE rejects
 * cross-project and same-project/wrong-owner writes -- not merely that
 * service-layer code happens to check first (D-037: "Application checks
 * remain defense in depth", implying the database check is primary).
 *
 * DRIVER SPLIT (explicit, honest, per this task's design notes): this
 * codebase has never used composite `foreign([...])` keys before this
 * schema (verified absence cited in both ADRs). SQLite's schema grammar
 * compiles a `Schema::table()->foreign()` command to NO SQL at all
 * (`SQLiteGrammar::compileForeign()` is a documented no-op for ALTER-added
 * foreign keys -- verified by reading
 * vendor/laravel/framework/.../Schema/Grammars/SQLiteGrammar.php:450-453).
 * Because the deferred migration in this schema adds every composite FK via
 * `Schema::table()` (never inline `Schema::create()`), sqlite has NEITHER a
 * declared NOR an enforced constraint for any of them. On sqlite these
 * rejection tests therefore SKIP with a message naming the
 * `widget-site-layout-db-matrix` job (adr-widget-database-portability.md) as
 * the actual dual-engine enforcement point. On mysql/pgsql the rejection is
 * real and asserted for real.
 */
final class WidgetDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() for the
     * full explanation: the Widget module stays disabled by design, so its
     * migration path must be registered manually before RefreshDatabase's
     * migrate:fresh runs.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * See WidgetSiteLayoutSchemaTest::migrateFreshUsing() for the full
     * explanation: scope migrate:fresh to only this module's migrations.
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
     * See WidgetSiteLayoutSchemaTest::tearDownAfterClass() for the full
     * explanation: reset the process-wide RefreshDatabaseState::$migrated
     * flag so this module-scoped migrate:fresh never leaks into whichever
     * unrelated test class runs next in the same process.
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    // ---- Manifest -> Blob -------------------------------------------------

    public function test_manifest_rejects_cross_project_blob_reference(): void
    {
        $blobOther = $this->insertBlob('ProjectB', 'blob-other');

        $this->assertRejected(function () use ($blobOther): void {
            $this->insertManifest('ProjectA', $blobOther, 'manifest-cross');
        });
    }

    // ---- Instance Version -> Instance / Manifest ---------------------------

    public function test_instance_version_rejects_cross_project_instance_reference(): void
    {
        $instanceOther = $this->insertInstance('ProjectB');
        $blob = $this->insertBlob('ProjectA', 'blob-iv-1');
        $manifest = $this->insertManifest('ProjectA', $blob, 'manifest-iv-1');

        $this->assertRejected(function () use ($instanceOther, $manifest): void {
            $this->insertInstanceVersion('ProjectA', $instanceOther, $manifest, 1);
        });
    }

    public function test_instance_version_rejects_cross_project_manifest_reference(): void
    {
        $instance = $this->insertInstance('ProjectA');
        $blobOther = $this->insertBlob('ProjectB', 'blob-iv-2');
        $manifestOther = $this->insertManifest('ProjectB', $blobOther, 'manifest-iv-2');

        $this->assertRejected(function () use ($instance, $manifestOther): void {
            $this->insertInstanceVersion('ProjectA', $instance, $manifestOther, 1);
        });
    }

    // ---- Layout Version -> Layout ------------------------------------------

    public function test_layout_version_rejects_cross_project_layout_reference(): void
    {
        $layoutOther = $this->insertLayout('ProjectB', 'site');

        $this->assertRejected(function () use ($layoutOther): void {
            $this->insertLayoutVersion('ProjectA', $layoutOther, 1, 'draft');
        });
    }

    // ---- Placement -> Layout Version / Instance Version --------------------

    public function test_placement_rejects_cross_project_layout_version_reference(): void
    {
        $layoutVersionOther = $this->insertLayoutVersion('ProjectB', $this->insertLayout('ProjectB', 'site'), 1, 'draft');
        $blob = $this->insertBlob('ProjectA', 'blob-pl-1');
        $manifest = $this->insertManifest('ProjectA', $blob, 'manifest-pl-1');
        $instanceVersion = $this->insertInstanceVersion('ProjectA', $this->insertInstance('ProjectA'), $manifest, 1);

        $this->assertRejected(function () use ($layoutVersionOther, $instanceVersion): void {
            $this->insertPlacement('ProjectA', $layoutVersionOther, $instanceVersion);
        });
    }

    public function test_placement_rejects_cross_project_instance_version_reference(): void
    {
        $layoutVersion = $this->insertLayoutVersion('ProjectA', $this->insertLayout('ProjectA', 'site'), 1, 'draft');
        $blobOther = $this->insertBlob('ProjectB', 'blob-pl-2');
        $manifestOther = $this->insertManifest('ProjectB', $blobOther, 'manifest-pl-2');
        $instanceVersionOther = $this->insertInstanceVersion('ProjectB', $this->insertInstance('ProjectB'), $manifestOther, 1);

        $this->assertRejected(function () use ($layoutVersion, $instanceVersionOther): void {
            $this->insertPlacement('ProjectA', $layoutVersion, $instanceVersionOther);
        });
    }

    // ---- Instance current-version pointer (id, current_version_id, project_name) -> (instance_id, id, project_name)

    public function test_instance_current_version_pointer_rejects_cross_project_version(): void
    {
        $instanceA = $this->insertInstance('ProjectA');
        $blobOther = $this->insertBlob('ProjectB', 'blob-ptr-1');
        $manifestOther = $this->insertManifest('ProjectB', $blobOther, 'manifest-ptr-1');
        $versionInOtherProject = $this->insertInstanceVersion('ProjectB', $this->insertInstance('ProjectB'), $manifestOther, 1);

        $this->assertRejected(function () use ($instanceA, $versionInOtherProject): void {
            DB::table(WidgetTableConstant::INSTANCE)->where('id', $instanceA)->update(['current_version_id' => $versionInOtherProject]);
        });
    }

    public function test_instance_current_version_pointer_rejects_same_project_wrong_owner_version(): void
    {
        $instanceA = $this->insertInstance('ProjectA');
        $instanceB = $this->insertInstance('ProjectA');
        $blob = $this->insertBlob('ProjectA', 'blob-ptr-2');
        $manifest = $this->insertManifest('ProjectA', $blob, 'manifest-ptr-2');
        $versionOfB = $this->insertInstanceVersion('ProjectA', $instanceB, $manifest, 1);

        $this->assertRejected(function () use ($instanceA, $versionOfB): void {
            DB::table(WidgetTableConstant::INSTANCE)->where('id', $instanceA)->update(['current_version_id' => $versionOfB]);
        });
    }

    // ---- Layout draft/published pointers (id, pointer_id, project_name) -> (layout_id, id, project_name)

    public function test_layout_current_draft_pointer_rejects_cross_project_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $versionInOtherProject = $this->insertLayoutVersion('ProjectB', $this->insertLayout('ProjectB', 'site'), 1, 'draft');

        $this->assertRejected(function () use ($layoutA, $versionInOtherProject): void {
            DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutA)->update(['current_draft_version_id' => $versionInOtherProject]);
        });
    }

    public function test_layout_current_draft_pointer_rejects_same_project_wrong_owner_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $layoutB = $this->insertLayout('ProjectA', 'checkout');
        $versionOfB = $this->insertLayoutVersion('ProjectA', $layoutB, 1, 'draft');

        $this->assertRejected(function () use ($layoutA, $versionOfB): void {
            DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutA)->update(['current_draft_version_id' => $versionOfB]);
        });
    }

    public function test_layout_current_published_pointer_rejects_cross_project_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $versionInOtherProject = $this->insertLayoutVersion('ProjectB', $this->insertLayout('ProjectB', 'site'), 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionInOtherProject): void {
            DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutA)->update(['current_published_version_id' => $versionInOtherProject]);
        });
    }

    public function test_layout_current_published_pointer_rejects_same_project_wrong_owner_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $layoutB = $this->insertLayout('ProjectA', 'checkout');
        $versionOfB = $this->insertLayoutVersion('ProjectA', $layoutB, 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionOfB): void {
            DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutA)->update(['current_published_version_id' => $versionOfB]);
        });
    }

    // ---- Layout Version lineage: based_on_version_id / rollback_of_version_id, same (layout_id, project_name) shape
    //
    // KNOWN ENGINE FINDING (2026-07-17): these four lineage tests FAIL on
    // Homebrew MySQL 9.6.0 -- that build registers a SELF-referencing
    // composite FK whose child/parent lists share a column (`layout_id`) in
    // metadata but does not enforce it on INSERT (reproduced minimally with
    // raw SQL outside Laravel; single-column self-refs and every
    // non-self-referencing composite FK in this schema enforce correctly on
    // the same instance). The tests assert the INTENDED contract and must
    // not be weakened; the widget-site-layout-db-matrix job on mariadb:10 +
    // postgres:16 (the schema's actual target engines) is the arbiter of
    // real-engine behavior. If they fail there too, that reopens the
    // instance-scope-schema ADR's composite-FK design -- see the deferred
    // migration 2026_07_17_150008's matching breadcrumb.

    public function test_layout_version_based_on_rejects_cross_project_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $versionInOtherProject = $this->insertLayoutVersion('ProjectB', $this->insertLayout('ProjectB', 'site'), 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionInOtherProject): void {
            $this->insertLayoutVersion('ProjectA', $layoutA, 2, 'draft', ['based_on_version_id' => $versionInOtherProject]);
        });
    }

    public function test_layout_version_based_on_rejects_same_project_wrong_owner_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $layoutB = $this->insertLayout('ProjectA', 'checkout');
        $versionOfB = $this->insertLayoutVersion('ProjectA', $layoutB, 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionOfB): void {
            $this->insertLayoutVersion('ProjectA', $layoutA, 2, 'draft', ['based_on_version_id' => $versionOfB]);
        });
    }

    public function test_layout_version_rollback_of_rejects_cross_project_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $versionInOtherProject = $this->insertLayoutVersion('ProjectB', $this->insertLayout('ProjectB', 'site'), 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionInOtherProject): void {
            $this->insertLayoutVersion('ProjectA', $layoutA, 2, 'release', ['rollback_of_version_id' => $versionInOtherProject]);
        });
    }

    public function test_layout_version_rollback_of_rejects_same_project_wrong_owner_version(): void
    {
        $layoutA = $this->insertLayout('ProjectA', 'site');
        $layoutB = $this->insertLayout('ProjectA', 'checkout');
        $versionOfB = $this->insertLayoutVersion('ProjectA', $layoutB, 1, 'release');

        $this->assertRejected(function () use ($layoutA, $versionOfB): void {
            $this->insertLayoutVersion('ProjectA', $layoutA, 2, 'release', ['rollback_of_version_id' => $versionOfB]);
        });
    }

    // ---- Restrictive delete: no destructive cascade can destroy retained history

    public function test_deleting_a_referenced_blob_is_restricted(): void
    {
        $blob = $this->insertBlob('ProjectA', 'blob-restrict');
        $this->insertManifest('ProjectA', $blob, 'manifest-restrict');

        $this->assertRejected(function () use ($blob): void {
            DB::table(WidgetTableConstant::ARTIFACT_BLOB)->where('id', $blob)->delete();
        });
    }

    public function test_deleting_a_referenced_instance_is_restricted(): void
    {
        $instance = $this->insertInstance('ProjectA');
        $blob = $this->insertBlob('ProjectA', 'blob-restrict-2');
        $manifest = $this->insertManifest('ProjectA', $blob, 'manifest-restrict-2');
        $this->insertInstanceVersion('ProjectA', $instance, $manifest, 1);

        $this->assertRejected(function () use ($instance): void {
            DB::table(WidgetTableConstant::INSTANCE)->where('id', $instance)->delete();
        });
    }

    public function test_deleting_a_referenced_layout_is_restricted(): void
    {
        $layout = $this->insertLayout('ProjectA', 'site');
        $this->insertLayoutVersion('ProjectA', $layout, 1, 'draft');

        $this->assertRejected(function () use ($layout): void {
            DB::table(WidgetTableConstant::LAYOUT)->where('id', $layout)->delete();
        });
    }

    // ---- Helpers ------------------------------------------------------------

    private function assertRejected(\Closure $attempt): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'sqlite compiles Schema::table()->foreign() (ALTER-added composite FKs) to no SQL at all '
                . '(SQLiteGrammar::compileForeign is a documented no-op) -- neither declared nor enforced. '
                . 'Dual-engine enforcement is proven by the widget-site-layout-db-matrix job '
                . '(mysql + pgsql entries, adr-widget-database-portability.md). Run this suite with '
                . '-c phpunit.mysql.xml or -c phpunit.pgsql.xml for real rejection proof.'
            );
        }

        try {
            $attempt();
            $this->fail('Expected the database to reject this write via a restrictive composite foreign key.');
        } catch (QueryException $e) {
            $sqlState = is_array($e->errorInfo ?? null) ? (string) ($e->errorInfo[0] ?? '') : '';
            if ($sqlState === '') {
                $sqlState = (string) $e->getCode();
            }

            $this->assertStringStartsWith(
                '23',
                $sqlState,
                "Expected a SQLSTATE integrity-constraint-violation class (23xxx). Got [{$sqlState}]: " . $e->getMessage()
            );
        }
    }

    private function insertBlob(string $project, string $hash): int
    {
        return DB::table(WidgetTableConstant::ARTIFACT_BLOB)->insertGetId([
            'project_name' => $project,
            'artifact_hash' => str_pad(substr($hash, 0, 64), 64, '0'),
            'storage_disk' => 'local',
            'storage_key' => "generated_widgets/{$project}/{$hash}",
            'byte_size' => 1024,
            'media_type' => 'text/x-blade',
            'checksum' => str_pad(substr($hash, 0, 64), 64, '0'),
            'created_at' => now(),
        ]);
    }

    private function insertManifest(string $project, int $blobId, string $hash, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->insertGetId(array_merge([
            'project_name' => $project,
            'manifest_hash' => str_pad(substr($hash, 0, 64), 64, '0'),
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
        ], $overrides));
    }

    private function insertInstance(string $project, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::INSTANCE)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'name' => 'Header banner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertInstanceVersion(string $project, int $instanceId, int $manifestId, int $version, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::INSTANCE_VERSION)->insertGetId(array_merge([
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
            'provider_refs' => json_encode([]),
            'artifact_manifest_id' => $manifestId,
            'created_at' => now(),
        ], $overrides));
    }

    private function insertLayout(string $project, string $key, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::LAYOUT)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'key' => $key . '-' . Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertLayoutVersion(string $project, int $layoutId, int $version, string $state, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::LAYOUT_VERSION)->insertGetId(array_merge([
            'layout_id' => $layoutId,
            'project_name' => $project,
            'version' => $version,
            'state' => $state,
            'lock_version' => 0,
            'registry_contracts' => json_encode(['slots' => []]),
            'registry_contract_hash' => str_repeat('e', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertPlacement(string $project, int $layoutVersionId, int $instanceVersionId, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::PLACEMENT)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'layout_version_id' => $layoutVersionId,
            'widget_instance_version_id' => $instanceVersionId,
            'slot_key' => 'site.header.before',
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
