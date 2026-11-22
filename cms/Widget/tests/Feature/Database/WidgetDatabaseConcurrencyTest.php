<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Constants\WidgetTableConstant;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: {@see WidgetAggregateLockOrder} primitives proven
 * against real tables (plan Task 13b checklist: "canonical lock-order
 * helper, raw Layout pair allocator, and raw Instance
 * MAX(version)+1 allocator against real tables").
 *
 * SQLite has no real `FOR UPDATE` row contention (single-writer engine), so
 * this file proves what a single process CAN prove honestly on any engine:
 * sequential allocator correctness (no gaps, no duplicates) and that
 * {@see WidgetAggregateLockOrder::withLocks()} actually wraps its callback
 * in a transaction and locks the named rows without error. TRUE concurrent
 * contention (two processes racing for the same lock) is explicitly NOT
 * proven here -- see {@see self::test_true_concurrent_contention_is_out_of_this_tasks_scope()}
 * -- matching the honest driver-split precedent already established by
 * Task 13a's WidgetDatabaseIntegrityTest::assertRejected() (sqlite skip
 * naming the widget-site-layout-db-matrix job as the real arbiter) and this
 * task's own explicit scope boundary: "Move action-specific races to their
 * owner: Instance create/update/clone... Task 16; repin... Task 18;
 * publish... Task 20; rollback... Task 21; aggregate route/race
 * conformance... Task 24b."
 */
final class WidgetDatabaseConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private WidgetAggregateLockOrder $lockOrder;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockOrder = new WidgetAggregateLockOrder();
    }

    // -----------------------------------------------------------------
    // withLocks(): transactional wrapper, sorted multi-row locking
    // -----------------------------------------------------------------

    public function test_with_locks_runs_the_callback_and_returns_its_result(): void
    {
        $result = $this->lockOrder->withLocks([], [], static fn (): string => 'ran');

        $this->assertSame('ran', $result);
    }

    public function test_with_locks_locks_named_layout_and_instance_rows_without_error(): void
    {
        $layoutId = $this->insertLayout('ExampleProject', 'site');
        $instanceId = $this->insertInstance('ExampleProject');

        $result = $this->lockOrder->withLocks([$layoutId], [$instanceId], static fn (): array => ['ok' => true]);

        $this->assertSame(['ok' => true], $result);
    }

    public function test_with_locks_accepts_multiple_unsorted_ids_on_either_side_without_error(): void
    {
        $layoutA = $this->insertLayout('ExampleProject', 'site');
        $layoutB = $this->insertLayout('ExampleProject', 'checkout');
        $instanceA = $this->insertInstance('ExampleProject');
        $instanceB = $this->insertInstance('ExampleProject');

        // Deliberately unsorted / duplicated input -- withLocks() must sort
        // and dedupe internally (deadlock-avoidance precedent).
        $result = $this->lockOrder->withLocks(
            [$layoutB, $layoutA, $layoutA],
            [$instanceB, $instanceA],
            static fn (): int => 42
        );

        $this->assertSame(42, $result);
    }

    public function test_with_locks_rolls_back_every_change_when_the_callback_throws(): void
    {
        $instanceId = $this->insertInstance('ExampleProject');

        try {
            $this->lockOrder->withLocks([], [$instanceId], function () use ($instanceId): void {
                DB::table(WidgetTableConstant::INSTANCE)->where('id', $instanceId)->update(['name' => 'Changed inside the lock']);

                throw new \RuntimeException('simulated failure after mutation, before commit');
            });
            $this->fail('Expected the callback exception to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated failure after mutation, before commit', $exception->getMessage());
        }

        $row = DB::table(WidgetTableConstant::INSTANCE)->find($instanceId);
        $this->assertNotSame('Changed inside the lock', $row->name, 'A thrown exception inside withLocks() must roll back the whole transaction.');
    }

    // -----------------------------------------------------------------
    // Raw Layout Version pair allocator: MAX+1 / MAX+2
    // -----------------------------------------------------------------

    public function test_allocate_layout_version_pair_starts_at_one_and_two_for_an_empty_layout(): void
    {
        $layoutId = $this->insertLayout('ExampleProject', 'site');

        $pair = $this->lockOrder->allocateLayoutVersionPair($layoutId);

        $this->assertSame(['release' => 1, 'draft' => 2], $pair);
    }

    public function test_allocate_layout_version_pair_continues_from_the_real_max_version(): void
    {
        $layoutId = $this->insertLayout('ExampleProject', 'site');
        $this->insertLayoutVersion('ExampleProject', $layoutId, 1, 'release');
        $this->insertLayoutVersion('ExampleProject', $layoutId, 2, 'draft');

        $pair = $this->lockOrder->allocateLayoutVersionPair($layoutId);

        $this->assertSame(['release' => 3, 'draft' => 4], $pair);
    }

    public function test_allocate_layout_version_pair_is_scoped_per_layout(): void
    {
        $layoutA = $this->insertLayout('ExampleProject', 'site');
        $layoutB = $this->insertLayout('ExampleProject', 'checkout');
        $this->insertLayoutVersion('ExampleProject', $layoutA, 1, 'release');

        $pairA = $this->lockOrder->allocateLayoutVersionPair($layoutA);
        $pairB = $this->lockOrder->allocateLayoutVersionPair($layoutB);

        $this->assertSame(['release' => 2, 'draft' => 3], $pairA);
        $this->assertSame(['release' => 1, 'draft' => 2], $pairB, 'A different Layout with no versions yet must start fresh at 1/2.');
    }

    public function test_sequential_layout_version_pair_allocation_has_no_gaps_or_duplicates_single_process(): void
    {
        $layoutId = $this->insertLayout('ExampleProject', 'site');
        $seenVersions = [];

        for ($i = 0; $i < 5; $i++) {
            $pair = $this->lockOrder->allocateLayoutVersionPair($layoutId);
            $this->insertLayoutVersion('ExampleProject', $layoutId, $pair['release'], 'release');
            $this->insertLayoutVersion('ExampleProject', $layoutId, $pair['draft'], 'draft');

            $seenVersions[] = $pair['release'];
            $seenVersions[] = $pair['draft'];
        }

        $this->assertSame(range(1, 10), $seenVersions, 'Sequential single-process allocation must be gap-free and duplicate-free.');
        $this->assertSame(count($seenVersions), count(array_unique($seenVersions)));
    }

    // -----------------------------------------------------------------
    // Raw Instance Version allocator: MAX(version)+1
    // -----------------------------------------------------------------

    public function test_allocate_next_instance_version_starts_at_one(): void
    {
        $instanceId = $this->insertInstance('ExampleProject');

        $this->assertSame(1, $this->lockOrder->allocateNextInstanceVersion($instanceId));
    }

    public function test_allocate_next_instance_version_continues_from_the_real_max(): void
    {
        $instanceId = $this->insertInstance('ExampleProject');
        $manifestId = $this->insertManifestChain('ExampleProject');
        $this->insertInstanceVersion('ExampleProject', $instanceId, $manifestId, 1);
        $this->insertInstanceVersion('ExampleProject', $instanceId, $manifestId, 2);

        $this->assertSame(3, $this->lockOrder->allocateNextInstanceVersion($instanceId));
    }

    public function test_sequential_instance_version_allocation_has_no_gaps_or_duplicates_single_process(): void
    {
        $instanceId = $this->insertInstance('ExampleProject');
        $manifestId = $this->insertManifestChain('ExampleProject');
        $seenVersions = [];

        for ($i = 0; $i < 6; $i++) {
            $next = $this->lockOrder->allocateNextInstanceVersion($instanceId);
            $this->insertInstanceVersion('ExampleProject', $instanceId, $manifestId, $next);
            $seenVersions[] = $next;
        }

        $this->assertSame(range(1, 6), $seenVersions);
        $this->assertSame(count($seenVersions), count(array_unique($seenVersions)));
    }

    // -----------------------------------------------------------------
    // Honest scope boundary
    // -----------------------------------------------------------------

    public function test_true_concurrent_contention_is_out_of_this_tasks_scope(): void
    {
        $this->markTestSkipped(
            'True multi-process FOR UPDATE contention (two processes racing for the same '
            . 'Layout/Instance lock or the same version-allocation slot) is NOT provable from a '
            . 'single-process sqlite/PHPUnit run on any engine, and is explicitly out of Task 13b '
            . 'scope per the plan checklist: "Move action-specific races to their owner: Instance '
            . 'create/update/clone and different-key allocation in Task 16; repin/standalone '
            . 'contention in Task 18; publish in Task 20; rollback/publish contention in Task 21; '
            . 'aggregate route/race conformance in Task 24b." This test file proves only single-process '
            . 'sequential allocator correctness (no gaps/duplicates) and that withLocks() correctly '
            . 'wraps/rolls back a transaction -- the primitives those later tasks build races on top of.'
        );
    }

    // -----------------------------------------------------------------
    // Helpers (same established pattern as WidgetDatabaseIntegrityTest / WidgetDatabaseLifecycleTest)
    // -----------------------------------------------------------------

    private function insertInstance(string $project): int
    {
        return DB::table(WidgetTableConstant::INSTANCE)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'name' => 'Header banner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLayout(string $project, string $key): int
    {
        return DB::table(WidgetTableConstant::LAYOUT)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'key' => $key . '-' . Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLayoutVersion(string $project, int $layoutId, int $version, string $state): int
    {
        return DB::table(WidgetTableConstant::LAYOUT_VERSION)->insertGetId([
            'layout_id' => $layoutId,
            'project_name' => $project,
            'version' => $version,
            'state' => $state,
            'lock_version' => 0,
            'registry_contracts' => json_encode(['slots' => []]),
            'registry_contract_hash' => str_repeat('e', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertManifestChain(string $project): int
    {
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

        return DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->insertGetId([
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
    }

    private function insertInstanceVersion(string $project, int $instanceId, int $manifestId, int $version): int
    {
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
            'provider_refs' => json_encode([]),
            'artifact_manifest_id' => $manifestId,
            'created_at' => now(),
        ]);
    }
}
