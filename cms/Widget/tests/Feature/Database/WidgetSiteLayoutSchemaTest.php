<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetIdempotencyKey;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shape proof for the eight-table Widget Site Layout schema (plan Chunk 2
 * Task 13a, 
 * lines 724-775; architecture.md "Persistence Model"; ADRs
 * adr-widget-instance-scope-schema.md and adr-widget-database-portability.md).
 *
 * SCOPE (per the three-file split): tables, columns, casts, candidate keys,
 * indexes, absence assertions, and static "no cascade" source proof. Pointer
 * mutation / state-machine / append-only behavior lives in
 * {@see WidgetDatabaseLifecycleTest}. Negative composite-FK rejection lives
 * in {@see WidgetDatabaseIntegrityTest}.
 */
final class WidgetSiteLayoutSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Widget module stays disabled by design (modules_statuses.json has
     * no "Widget" entry -- module enabling is an explicit non-goal of this
     * task; see cms/Widget/tests/Feature/WidgetModuleBoundaryTest.php).
     * Nwidart\Modules\ModuleManifest::getModulesData() filters module
     * discovery to Module::allEnabled() only, so WidgetServiceProvider is
     * never auto-registered and its migrations are never auto-discovered.
     *
     * RefreshDatabase::refreshDatabase() calls this hook (an intentionally
     * empty override point on the trait) BEFORE migrate:fresh runs, so
     * registering the migration path here -- not the full service provider,
     * avoiding any route/auth/event side effect -- makes this schema
     * reachable for tests without enabling the module.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Scope migrate:fresh to ONLY this module's own migrations. None of
     * these three test files reference any other module's tables, and
     * restricting the path here (a) keeps this suite fast, and (b) avoids
     * unrelated pre-existing full-app migration issues on engines other
     * than sqlite (observed: an unrelated cross-module migration ordering
     * failure surfaces when the entire application schema is fresh-migrated
     * against a live MySQL instance -- out of this task's scope to fix; the
     * schema this task owns is fully provable in isolation).
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
     * RefreshDatabaseState::$migrated is a process-wide static flag, not
     * scoped to this class: once set, EVERY later test class in the same
     * run trusts it and skips its own migrate:fresh. Because this class
     * restricts migrate:fresh to only this module's path (above), leaving
     * that flag set true would leak this module's narrow schema into
     * whichever unrelated test class happens to run next in the same
     * process (observed: an unrelated pre-existing test failed with
     * "no such table: enumerates" purely from ordering, not from anything
     * wrong in that test). Resetting here forces the next test class,
     * whichever it is, to always run its own full migrate:fresh.
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_exactly_eight_module_tables_exist(): void
    {
        $this->assertCount(8, WidgetTableConstant::all(), 'WidgetTableConstant::all() itself must list exactly eight tables.');

        foreach (WidgetTableConstant::all() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected module table [{$table}] to exist.");
        }

        // cms/Core owns pre-existing legacy tables that also start with
        // "widget_" (widget_themes, widget_inputs -- see
        // cms/Core/database/migrations/2025_03_16_193358_create_widgets_feature_tables.php)
        // plus the bare "widgets" table (no trailing underscore, not caught
        // by this prefix anyway). Those are Core-owned, not part of this
        // module's eight-table count, and must be excluded explicitly.
        $legacyCoreOwnedTables = ['widget_themes', 'widget_inputs'];

        $widgetTables = array_values(array_filter(
            Schema::getTableListing(null, false),
            static fn (string $name): bool => str_starts_with($name, 'widget_') && ! in_array($name, $legacyCoreOwnedTables, true),
        ));

        sort($widgetTables);
        $expected = WidgetTableConstant::all();
        sort($expected);

        $this->assertSame($expected, $widgetTables, 'Exactly eight widget_* tables must exist -- no ninth table, no missing table.');
    }

    public function test_widget_instances_is_identity_only_and_widget_id_lives_on_the_version(): void
    {
        $this->assertFalse(
            Schema::hasColumn(WidgetTableConstant::INSTANCE, 'widget_id'),
            'widget_instances must not contain widget_id -- identity only (D-007).'
        );
        $this->assertFalse(Schema::hasColumn(WidgetTableConstant::INSTANCE, 'widget_theme_id'));
        $this->assertFalse(Schema::hasColumn(WidgetTableConstant::INSTANCE, 'schema'));
        $this->assertFalse(Schema::hasColumn(WidgetTableConstant::INSTANCE, 'values'));
        $this->assertFalse(Schema::hasColumn(WidgetTableConstant::INSTANCE, 'artifact_manifest_id'));

        foreach (['id', 'uuid', 'project_name', 'name', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::INSTANCE, $column), "widget_instances must have column [{$column}].");
        }

        // current_version_id is added by the deferred (second) migration step.
        $this->assertTrue(
            Schema::hasColumn(WidgetTableConstant::INSTANCE, 'current_version_id'),
            'widget_instances.current_version_id must exist after the deferred migration runs.'
        );

        $this->assertTrue(
            Schema::hasColumn(WidgetTableConstant::INSTANCE_VERSION, 'widget_id'),
            'widget_instance_versions must pin widget_id (D-007).'
        );
        $this->assertTrue(Schema::hasColumn(WidgetTableConstant::INSTANCE_VERSION, 'widget_theme_id'));
    }

    public function test_widget_instance_versions_has_no_mutable_artifact_status(): void
    {
        $this->assertFalse(
            Schema::hasColumn(WidgetTableConstant::INSTANCE_VERSION, 'artifact_status'),
            'widget_instance_versions must never have a mutable artifact_status column (D-042).'
        );

        foreach (
            [
                'id', 'instance_id', 'project_name', 'version', 'widget_id', 'widget_theme_id',
                'renderer_key', 'renderer_version', 'trust_classification', 'schema', 'schema_hash',
                'values', 'provider_refs', 'artifact_manifest_id', 'created_by', 'created_at',
            ] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::INSTANCE_VERSION, $column), "widget_instance_versions must have column [{$column}].");
        }

        // Immutable revision: no updated_at (only created_at).
        $this->assertFalse(
            Schema::hasColumn(WidgetTableConstant::INSTANCE_VERSION, 'updated_at'),
            'widget_instance_versions is an immutable revision and must not carry updated_at.'
        );
    }

    public function test_widget_artifact_blobs_is_immutable_project_scoped_byte_metadata(): void
    {
        foreach (
            ['id', 'project_name', 'artifact_hash', 'storage_disk', 'storage_key', 'byte_size', 'media_type', 'checksum', 'created_at'] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::ARTIFACT_BLOB, $column), "widget_artifact_blobs must have column [{$column}].");
        }

        $this->assertFalse(Schema::hasColumn(WidgetTableConstant::ARTIFACT_BLOB, 'updated_at'), 'Blob rows are immutable -- no updated_at.');

        foreach (['manifest_hash', 'provider_contracts', 'source_html', 'label', 'secret_value'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn(WidgetTableConstant::ARTIFACT_BLOB, $forbidden),
                "widget_artifact_blobs must never carry manifest/provider/ownership/label/source/secret metadata [{$forbidden}]."
            );
        }
    }

    public function test_widget_artifact_manifests_has_provenance_plus_attestation_and_control_metadata(): void
    {
        foreach (
            [
                'id', 'project_name', 'manifest_hash', 'artifact_blob_id', 'generator_version', 'renderer_key',
                'renderer_version', 'applicability', 'executable_surface_fingerprint_hash', 'canonical_schema_hash',
                'provider_contracts', 'trust_policy_version', 'trust_lane',
                // monotonic eligibility attestation
                'eligibility_state', 'attestation_version', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at', 'eligibility_reason',
                // independent emergency runtime control
                'control_version', 'blocked', 'blocked_by', 'blocked_at', 'cleared_by', 'cleared_at', 'control_reason',
                'created_at', 'updated_at',
            ] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::ARTIFACT_MANIFEST, $column), "widget_artifact_manifests must have column [{$column}].");
        }
    }

    public function test_widget_layouts_has_deferred_draft_and_published_pointers(): void
    {
        foreach (['id', 'uuid', 'project_name', 'key', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::LAYOUT, $column), "widget_layouts must have column [{$column}].");
        }

        foreach (['current_draft_version_id', 'current_published_version_id'] as $column) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::LAYOUT, $column), "widget_layouts.{$column} must exist after the deferred migration runs.");
        }
    }

    public function test_widget_layout_versions_freezes_state_and_carries_registry_contracts(): void
    {
        foreach (
            [
                'id', 'layout_id', 'project_name', 'version', 'state', 'based_on_version_id', 'rollback_of_version_id',
                'lock_version', 'registry_contracts', 'registry_contract_hash', 'eligibility_snapshot', 'note',
                'actor_id', 'published_at', 'published_by', 'created_at', 'updated_at',
            ] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::LAYOUT_VERSION, $column), "widget_layout_versions must have column [{$column}].");
        }

        // No DB enum: `state` is a plain string column. Functional proof (any
        // string value is accepted by the database) lives in the Lifecycle
        // test; here we only prove the column is not a native DB enum type.
        $type = Schema::getColumnType(WidgetTableConstant::LAYOUT_VERSION, 'state');
        $this->assertIsString($type);
        $this->assertStringNotContainsStringIgnoringCase('enum', $type, 'layout_versions.state must not be a native database ENUM.');
    }

    public function test_widget_placements_has_target_columns_and_non_null_scope_identity(): void
    {
        foreach (
            [
                'id', 'uuid', 'project_name', 'layout_version_id', 'widget_instance_version_id', 'slot_key',
                'scope_type', 'scope_identity', 'scope_key', 'target_type', 'target_id', 'position', 'enabled',
                'rules', 'created_at', 'updated_at',
            ] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::PLACEMENT, $column), "widget_placements must have column [{$column}].");
        }

        // target is never encoded into scope_key -- both target_type and
        // target_id are their own columns (already asserted above); this
        // test additionally proves scope_identity is declared NOT NULL.
        $columns = collect(Schema::getColumns(WidgetTableConstant::PLACEMENT))->keyBy('name');
        $this->assertFalse((bool) $columns->get('scope_identity')['nullable'], 'widget_placements.scope_identity must be NOT NULL.');
        $this->assertTrue((bool) $columns->get('scope_key')['nullable']);
        $this->assertTrue((bool) $columns->get('target_type')['nullable']);
        $this->assertTrue((bool) $columns->get('target_id')['nullable']);
    }

    public function test_widget_idempotency_keys_stores_only_safe_scoped_identity(): void
    {
        foreach (
            [
                'id', 'project_name', 'action', 'resource_scope', 'actor_id', 'idempotency_key', 'request_hash',
                'state', 'http_status', 'resource_type', 'resource_id', 'resource_version', 'expires_at',
                'created_at', 'updated_at',
            ] as $column
        ) {
            $this->assertTrue(Schema::hasColumn(WidgetTableConstant::IDEMPOTENCY_KEY, $column), "widget_idempotency_keys must have column [{$column}].");
        }

        foreach (['values', 'provider_payload', 'preview_overlay', 'response_body', 'input_values', 'raw_request'] as $banned) {
            $this->assertFalse(
                Schema::hasColumn(WidgetTableConstant::IDEMPOTENCY_KEY, $banned),
                "widget_idempotency_keys must never store [{$banned}] (frozen contract banned_retained_fields)."
            );
        }
    }

    /**
     * Candidate keys architecture.md requires for composite-FK targets:
     * (id, project_name) on Blob/Manifest/Instance/Layout;
     * (instance_id, id, project_name) on Instance Version;
     * (layout_id, id, project_name) on Layout Version.
     */
    public function test_project_scoped_candidate_keys_exist_for_every_fk_target(): void
    {
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::ARTIFACT_BLOB, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::ARTIFACT_BLOB, ['project_name', 'artifact_hash']);

        $this->assertHasUniqueIndexCovering(WidgetTableConstant::ARTIFACT_MANIFEST, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::ARTIFACT_MANIFEST, ['project_name', 'manifest_hash']);

        $this->assertHasUniqueIndexCovering(WidgetTableConstant::INSTANCE, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::INSTANCE, ['project_name', 'uuid']);

        $this->assertHasUniqueIndexCovering(WidgetTableConstant::INSTANCE_VERSION, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::INSTANCE_VERSION, ['instance_id', 'id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::INSTANCE_VERSION, ['instance_id', 'version']);

        $this->assertHasUniqueIndexCovering(WidgetTableConstant::LAYOUT, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::LAYOUT, ['project_name', 'key']);

        $this->assertHasUniqueIndexCovering(WidgetTableConstant::LAYOUT_VERSION, ['id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::LAYOUT_VERSION, ['layout_id', 'id', 'project_name']);
        $this->assertHasUniqueIndexCovering(WidgetTableConstant::LAYOUT_VERSION, ['layout_id', 'version']);
    }

    /**
     * Static proof (migration source scan) that no migration in this module
     * ever declares a destructive cascade. Behavioral (runtime) restrictive
     * rejection is proven in {@see WidgetDatabaseIntegrityTest}.
     */
    public function test_no_migration_declares_cascade_on_delete(): void
    {
        $migrationsPath = base_path('cms/Widget/database/migrations');
        $this->assertDirectoryExists($migrationsPath);

        $files = glob($migrationsPath . '/*.php');
        $this->assertNotEmpty($files, 'Expected migration files to exist.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'cascadeOnDelete',
                $contents,
                basename($file) . ' must not declare cascadeOnDelete -- restrictive-only FKs, no destructive cascade.'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                "onDelete('cascade')",
                $contents,
                basename($file) . ' must not declare an equivalent string-form cascade delete.'
            );
        }
    }

    public function test_eight_models_exist_with_expected_table_and_uuid_wiring(): void
    {
        $this->assertSame(WidgetTableConstant::ARTIFACT_BLOB, (new WidgetArtifactBlob())->getTable());
        $this->assertSame(WidgetTableConstant::ARTIFACT_MANIFEST, (new WidgetArtifactManifest())->getTable());
        $this->assertSame(WidgetTableConstant::INSTANCE, (new WidgetInstance())->getTable());
        $this->assertSame(WidgetTableConstant::INSTANCE_VERSION, (new WidgetInstanceVersion())->getTable());
        $this->assertSame(WidgetTableConstant::LAYOUT, (new WidgetLayout())->getTable());
        $this->assertSame(WidgetTableConstant::LAYOUT_VERSION, (new WidgetLayoutVersion())->getTable());
        $this->assertSame(WidgetTableConstant::PLACEMENT, (new WidgetPlacement())->getTable());
        $this->assertSame(WidgetTableConstant::IDEMPOTENCY_KEY, (new WidgetIdempotencyKey())->getTable());

        // uuid columns live on instances/layouts/placements (architecture.md);
        // those three models must use the manual HasUuid convention, not
        // Laravel's built-in HasUuids trait (adr-widget-instance-scope-schema.md
        // context, HasUuid.php:10-27 precedent).
        $uuidBearing = [WidgetInstance::class, WidgetLayout::class, WidgetPlacement::class];
        foreach ($uuidBearing as $class) {
            $traits = class_uses_recursive($class);
            $this->assertContains(
                \Cms\Widget\Models\Traits\HasUuid::class,
                $traits,
                "{$class} must use the module's manual HasUuid trait."
            );
            $this->assertNotContains(
                \Illuminate\Database\Eloquent\Concerns\HasUuids::class,
                $traits,
                "{$class} must NOT use Laravel's built-in HasUuids trait (this codebase's convention is the manual trait)."
            );
        }

        $this->assertContains(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(WidgetInstance::class));
    }

    public function test_immutable_models_disable_updated_at(): void
    {
        $blob = new WidgetArtifactBlob();
        $this->assertNull($blob::UPDATED_AT, 'WidgetArtifactBlob is immutable and must disable UPDATED_AT.');

        $version = new WidgetInstanceVersion();
        $this->assertNull($version::UPDATED_AT, 'WidgetInstanceVersion is an immutable revision and must disable UPDATED_AT.');
    }

    public function test_json_columns_are_cast_to_array_on_relevant_models(): void
    {
        $instanceVersion = new WidgetInstanceVersion();
        foreach (['schema', 'values', 'provider_refs'] as $attribute) {
            $this->assertSame('array', $instanceVersion->getCasts()[$attribute] ?? null, "WidgetInstanceVersion must cast {$attribute} to array.");
        }

        $manifest = new WidgetArtifactManifest();
        foreach (['applicability', 'provider_contracts'] as $attribute) {
            $this->assertSame('array', $manifest->getCasts()[$attribute] ?? null, "WidgetArtifactManifest must cast {$attribute} to array.");
        }

        $layoutVersion = new WidgetLayoutVersion();
        foreach (['registry_contracts', 'eligibility_snapshot'] as $attribute) {
            $this->assertSame('array', $layoutVersion->getCasts()[$attribute] ?? null, "WidgetLayoutVersion must cast {$attribute} to array.");
        }

        $placement = new WidgetPlacement();
        $this->assertSame('array', $placement->getCasts()['rules'] ?? null, 'WidgetPlacement must cast rules to array.');
    }

    /**
     * @param list<string> $columns
     */
    private function assertHasUniqueIndexCovering(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);
        $normalizedExpected = $columns;

        foreach ($indexes as $index) {
            if (! $index['unique']) {
                continue;
            }

            if ($index['columns'] === $normalizedExpected) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail(sprintf(
            'Expected a unique index covering exactly [%s] on table [%s]. Found indexes: %s',
            implode(', ', $columns),
            $table,
            json_encode($indexes),
        ));
    }
}
