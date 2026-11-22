<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Support;

use Cms\Widget\Models\WidgetIdempotencyKey;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: {@see WidgetIdempotencyService} proven against real
 * `widget_idempotency_keys` rows (plan Task 13b checklist: "generic
 * claim-before-mutation, same-key/same-hash processing, bounded wait, safe
 * identity replay, TTL, no body/value retention, different-hash conflict").
 *
 * Directory is `tests/Unit/Support` per the plan's exact allowed_paths file
 * name, but this suite genuinely needs the real `widget_idempotency_keys`
 * table (RefreshDatabase + Task 13a's module-scoped migration path), since
 * a claim-before-mutation primitive is meaningless without a real unique
 * constraint to race against.
 *
 * Wait-bound tests use REAL, tiny bounds (tens of milliseconds) rather than
 * a fake clock -- fast and deterministic without mocking time. The tests
 * that genuinely need to simulate elapsed time (TTL reclaim, the
 * reclaim-stomp fencing scenario) use the injectable clock instead, so they
 * never sleep for real.
 */
final class WidgetIdempotencyServiceTest extends TestCase
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
        // RefreshDatabaseState::$migrated flag set to. This is the file
        // that first surfaced the bug: tests/Unit/Support sorts after every
        // tests/Feature/* directory, so by the time this class ran in a
        // full-module suite, Feature/Security/WidgetPilotAuthorizationContractTest
        // had already set $migrated=true via the app's default (non-Widget)
        // migration path, silently starving this class of its own
        // widget_idempotency_keys table.
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

    // -----------------------------------------------------------------
    // Claim-before-mutation
    // -----------------------------------------------------------------

    public function test_claim_on_a_new_scope_creates_a_processing_row(): void
    {
        $service = new WidgetIdempotencyService();

        $result = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-new', $this->requestHash('A'));

        $this->assertTrue($result->isCreated());

        $row = WidgetIdempotencyKey::query()->find($result->idempotencyKeyId);
        $this->assertNotNull($row);
        $this->assertSame(WidgetIdempotencyKey::STATE_PROCESSING, $row->state);
        $this->assertSame($this->requestHash('A'), $row->request_hash);
        $this->assertNull($row->resource_id, 'A freshly created claim has no committed resource identity yet.');
    }

    public function test_claim_persists_only_the_safe_scoped_columns_never_raw_values_or_bodies(): void
    {
        $service = new WidgetIdempotencyService();

        $result = $service->claim('ExampleProject', 'instance_create', 'scope-x', 1, 'key-safe-columns', $this->requestHash('A'));

        $row = WidgetIdempotencyKey::query()->find($result->idempotencyKeyId);
        $attributes = array_keys($row->getAttributes());

        foreach (['values', 'provider_payload', 'preview_overlay', 'response_body', 'input_values', 'raw_request'] as $banned) {
            $this->assertNotContains($banned, $attributes, "widget_idempotency_keys row must never carry a [{$banned}] attribute.");
        }
    }

    // -----------------------------------------------------------------
    // Safe identity replay (same key + same hash, after commit)
    // -----------------------------------------------------------------

    public function test_same_key_same_hash_after_commit_replays_the_safe_identity(): void
    {
        $service = new WidgetIdempotencyService();

        $created = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-replay', $this->requestHash('Y'));
        $this->assertTrue(
            $service->markCommitted($created->idempotencyKeyId, $this->requestHash('Y'), 201, 'widget_instance', 'uuid-123', '1'),
            'The claim holder committing with its own request hash must succeed.'
        );

        $replay = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-replay', $this->requestHash('Y'));

        $this->assertTrue($replay->isReplay());
        $this->assertSame($created->idempotencyKeyId, $replay->idempotencyKeyId);
        $this->assertSame(201, $replay->httpStatus);
        $this->assertSame('widget_instance', $replay->resourceType);
        $this->assertSame('uuid-123', $replay->resourceId);
        $this->assertSame('1', $replay->resourceVersion);
    }

    // -----------------------------------------------------------------
    // Different-hash conflict (never waits, regardless of state)
    // -----------------------------------------------------------------

    public function test_same_key_different_hash_after_commit_returns_conflict(): void
    {
        $service = new WidgetIdempotencyService();

        $created = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-conflict-committed', $this->requestHash('original'));
        $service->markCommitted($created->idempotencyKeyId, $this->requestHash('original'), 201, 'widget_instance', 'uuid-1', '1');

        $start = microtime(true);
        $conflict = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-conflict-committed', $this->requestHash('DIFFERENT'), waitBoundMs: 5000);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($conflict->isConflict());
        $this->assertLessThan(0.5, $elapsed, 'A different-hash conflict must return immediately, never entering the wait loop.');
    }

    public function test_same_key_different_hash_while_still_processing_returns_conflict_without_waiting(): void
    {
        $service = new WidgetIdempotencyService();

        $service->claim('ExampleProject', 'instance_create', '', 1, 'key-conflict-processing', $this->requestHash('original'));

        $start = microtime(true);
        $conflict = $service->claim('ExampleProject', 'instance_create', '', 2, 'key-conflict-processing', $this->requestHash('DIFFERENT'), waitBoundMs: 5000);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($conflict->isConflict());
        $this->assertLessThan(0.5, $elapsed);
    }

    // -----------------------------------------------------------------
    // Bounded wait -> stable WIDGET_IDEMPOTENCY_IN_PROGRESS
    // -----------------------------------------------------------------

    public function test_same_key_same_hash_while_still_processing_waits_then_reports_in_progress(): void
    {
        $service = new WidgetIdempotencyService();
        $service->claim('ExampleProject', 'instance_create', '', 1, 'key-wait', $this->requestHash('X'));

        $start = microtime(true);
        $second = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-wait', $this->requestHash('X'), waitBoundMs: 60, pollIntervalMs: 15);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($second->isInProgress());
        $this->assertGreaterThanOrEqual(0.05, $elapsed, 'Must actually wait close to the bound before reporting in-progress.');
        $this->assertLessThan(1.0, $elapsed, 'Must never sleep-loop far beyond the configured bound.');
    }

    public function test_waiting_request_observes_a_commit_that_lands_mid_wait(): void
    {
        $service = new WidgetIdempotencyService();
        $created = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-wait-commit', $this->requestHash('X'));

        // Commit happens "mid-wait" from the second caller's perspective --
        // simulated here by committing before calling claim() again, since
        // this is a single-threaded test process; the wait loop's own
        // re-select-each-iteration behavior is what a truly concurrent
        // commit would be observed through.
        $service->markCommitted($created->idempotencyKeyId, $this->requestHash('X'), 201, 'widget_instance', 'uuid-mid-wait', '1');

        $second = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-wait-commit', $this->requestHash('X'), waitBoundMs: 200, pollIntervalMs: 15);

        $this->assertTrue($second->isReplay());
        $this->assertSame('uuid-mid-wait', $second->resourceId);
    }

    // -----------------------------------------------------------------
    // TTL: an abandoned (expired) processing claim is reclaimable
    // -----------------------------------------------------------------

    public function test_expired_processing_claim_is_reclaimed_in_place_by_a_new_request(): void
    {
        $baseTime = new DateTimeImmutable('2026-01-01 00:00:00');
        $currentTime = $baseTime;
        $service = new WidgetIdempotencyService(static function () use (&$currentTime): DateTimeImmutable {
            return $currentTime;
        });

        $first = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-ttl', $this->requestHash('original'), ttlSeconds: 10);
        $this->assertTrue($first->isCreated());

        // Advance the fake clock past the 10-second TTL -- no real sleep.
        $currentTime = $baseTime->modify('+20 seconds');

        $second = $service->claim('ExampleProject', 'instance_create', '', 2, 'key-ttl', $this->requestHash('reclaimer'), ttlSeconds: 10, waitBoundMs: 10, pollIntervalMs: 5);

        $this->assertTrue($second->isCreated(), 'An abandoned (expired) processing claim must be reclaimable, not stuck in_progress forever.');
        $this->assertSame($first->idempotencyKeyId, $second->idempotencyKeyId, 'Reclaim updates the SAME row in place rather than inserting a second one.');

        $row = WidgetIdempotencyKey::query()->find($second->idempotencyKeyId);
        $this->assertSame($this->requestHash('reclaimer'), $row->request_hash);
        $this->assertSame(1, WidgetIdempotencyKey::query()->where('idempotency_key', 'key-ttl')->count(), 'Reclaim must never create a duplicate row for the same scope.');
    }

    public function test_non_expired_processing_claim_is_never_reclaimed(): void
    {
        $currentTime = new DateTimeImmutable('2026-01-01 00:00:00');
        $service = new WidgetIdempotencyService(static function () use (&$currentTime): DateTimeImmutable {
            return $currentTime;
        });

        $first = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-not-expired', $this->requestHash('original'), ttlSeconds: 3600);

        // Same-hash replay attempt while still well within TTL and still
        // processing -- must wait/report in_progress, never silently reclaim.
        $second = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-not-expired', $this->requestHash('original'), ttlSeconds: 3600, waitBoundMs: 10, pollIntervalMs: 5);

        $this->assertTrue($second->isInProgress());
        $this->assertSame($first->idempotencyKeyId, $second->idempotencyKeyId);
    }

    // -----------------------------------------------------------------
    // Rollback releases an uncommitted claim
    // -----------------------------------------------------------------

    public function test_release_deletes_an_uncommitted_processing_claim_allowing_a_fresh_claim(): void
    {
        $service = new WidgetIdempotencyService();
        $created = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-release', $this->requestHash('Z'));

        $this->assertTrue(
            $service->release($created->idempotencyKeyId, $this->requestHash('Z')),
            'The claim holder releasing with its own request hash must succeed.'
        );

        $this->assertNull(WidgetIdempotencyKey::query()->find($created->idempotencyKeyId));

        $fresh = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-release', $this->requestHash('Z-retry'));
        $this->assertTrue($fresh->isCreated());
    }

    public function test_release_never_deletes_an_already_committed_claim(): void
    {
        $service = new WidgetIdempotencyService();
        $created = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-safe-release', $this->requestHash('A'));
        $service->markCommitted($created->idempotencyKeyId, $this->requestHash('A'), 201, 'widget_instance', 'uuid-1', '1');

        $this->assertFalse(
            $service->release($created->idempotencyKeyId, $this->requestHash('A')),
            'release() must report false when there is no processing claim of this caller to release.'
        );

        $row = WidgetIdempotencyKey::query()->find($created->idempotencyKeyId);
        $this->assertNotNull($row, 'release() must never delete a committed row, even if called by mistake.');
        $this->assertSame(WidgetIdempotencyKey::STATE_COMMITTED, $row->state);
    }

    // -----------------------------------------------------------------
    // Fencing: a stalled original claimant cannot stomp a reclaimed row
    // -----------------------------------------------------------------

    public function test_stalled_original_claimant_cannot_stomp_or_release_a_reclaimed_row(): void
    {
        // Scenario the request-hash fence exists for: claimant 1 claims,
        // stalls past its TTL without committing or releasing; claimant 2
        // reclaims the SAME row in place (tryReclaimExpired rewrites
        // request_hash -- that rewrite is exactly what makes the hash a
        // usable fencing token); then the stalled claimant 1 wakes up and
        // tries to finish. State alone would NOT protect here (the
        // reclaimed row is still `processing`), which is why the fence is
        // state AND hash together.
        $baseTime = new DateTimeImmutable('2026-01-01 00:00:00');
        $currentTime = $baseTime;
        $service = new WidgetIdempotencyService(static function () use (&$currentTime): DateTimeImmutable {
            return $currentTime;
        });

        $original = $service->claim('ExampleProject', 'instance_create', '', 1, 'key-stomp', $this->requestHash('stomp-original'), ttlSeconds: 10);
        $this->assertTrue($original->isCreated());

        // Original stalls; its TTL elapses (fake clock, no real sleep).
        $currentTime = $baseTime->modify('+20 seconds');

        $reclaimer = $service->claim('ExampleProject', 'instance_create', '', 2, 'key-stomp', $this->requestHash('stomp-reclaimer'), ttlSeconds: 10, waitBoundMs: 10, pollIntervalMs: 5);
        $this->assertTrue($reclaimer->isCreated());
        $this->assertSame($original->idempotencyKeyId, $reclaimer->idempotencyKeyId, 'Reclaim reuses the same row in place.');

        // The stalled original wakes up and tries to commit its result:
        // fenced out (hash no longer matches), row untouched.
        $this->assertFalse(
            $service->markCommitted($original->idempotencyKeyId, $this->requestHash('stomp-original'), 201, 'widget_instance', 'uuid-stale', '1'),
            'A stalled original claimant must NOT be able to commit over the reclaimer in-flight claim.'
        );

        $row = WidgetIdempotencyKey::query()->find($original->idempotencyKeyId);
        $this->assertSame(WidgetIdempotencyKey::STATE_PROCESSING, $row->state, 'The reclaimer claim must still be processing after the fenced-out commit attempt.');
        $this->assertSame($this->requestHash('stomp-reclaimer'), $row->request_hash);
        $this->assertNull($row->resource_id, 'No stale committed identity may leak onto the reclaimed row.');

        // The stalled original then tries its rollback path: also fenced
        // out -- it must not delete the reclaimer live claim either.
        $this->assertFalse(
            $service->release($original->idempotencyKeyId, $this->requestHash('stomp-original')),
            'A stalled original claimant must NOT be able to release the reclaimer in-flight claim.'
        );
        $this->assertNotNull(WidgetIdempotencyKey::query()->find($original->idempotencyKeyId));

        // The reclaimer itself finishes normally.
        $this->assertTrue(
            $service->markCommitted($reclaimer->idempotencyKeyId, $this->requestHash('stomp-reclaimer'), 201, 'widget_instance', 'uuid-reclaimer', '1')
        );

        $final = WidgetIdempotencyKey::query()->find($reclaimer->idempotencyKeyId);
        $this->assertSame(WidgetIdempotencyKey::STATE_COMMITTED, $final->state);
        $this->assertSame('uuid-reclaimer', $final->resource_id);
    }

    // -----------------------------------------------------------------
    // Cross-engine unique-violation detection path
    // -----------------------------------------------------------------

    public function test_a_pre_existing_row_for_the_same_scope_is_detected_via_the_unique_violation_path(): void
    {
        // Simulates "another process already won the claim race" before our
        // own insert attempt runs -- proves claim() gracefully falls back
        // to a real select-and-compare instead of crashing on the unique
        // constraint violation (AchievementRecorderImpl.php:124-132 pattern).
        WidgetIdempotencyKey::query()->create([
            'project_name' => 'ExampleProject',
            'action' => 'instance_create',
            'resource_scope' => '',
            'idempotency_key' => 'key-race',
            'request_hash' => $this->requestHash('winner'),
            'state' => WidgetIdempotencyKey::STATE_PROCESSING,
            'expires_at' => now()->addHour(),
        ]);

        $service = new WidgetIdempotencyService();
        $result = $service->claim('ExampleProject', 'instance_create', '', 2, 'key-race', $this->requestHash('loser'), waitBoundMs: 10, pollIntervalMs: 5);

        $this->assertTrue($result->isConflict());
    }

    // -----------------------------------------------------------------
    // Scope isolation
    // -----------------------------------------------------------------

    public function test_different_projects_or_actions_or_resource_scopes_are_fully_independent_claims(): void
    {
        $service = new WidgetIdempotencyService();

        $byProject = $service->claim('ProjectA', 'instance_create', '', 1, 'same-key', $this->requestHash('A'));
        $byOtherProject = $service->claim('ProjectB', 'instance_create', '', 1, 'same-key', $this->requestHash('A'));
        $byAction = $service->claim('ProjectA', 'instance_update', '', 1, 'same-key', $this->requestHash('A'));
        $byScope = $service->claim('ProjectA', 'instance_create', 'other-scope', 1, 'same-key', $this->requestHash('A'));

        $ids = [$byProject->idempotencyKeyId, $byOtherProject->idempotencyKeyId, $byAction->idempotencyKeyId, $byScope->idempotencyKeyId];

        $this->assertTrue($byProject->isCreated());
        $this->assertTrue($byOtherProject->isCreated());
        $this->assertTrue($byAction->isCreated());
        $this->assertTrue($byScope->isCreated());
        $this->assertSame(count($ids), count(array_unique($ids)), 'Each distinct scope must claim its own independent row.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Deterministic, realistic 64-character SHA-256 hex digest for a given
     * fixture label -- `request_hash` is a fixed-width `char(64)` column
     * (always a real SHA-256 digest in production). Using a real 64-char
     * value here (rather than a short literal like `'hash-A'`) matters
     * cross-engine: PostgreSQL's `char(n)` does NOT strip trailing spaces on
     * read (unlike MySQL, which trims them back off), so a short literal
     * would come back space-padded to 64 characters on PostgreSQL and fail
     * both direct assertSame() comparisons and this service's own
     * hash_equals() checks -- discovered running the real
     * widget-site-layout-db-matrix pgsql leg.
     */
    private function requestHash(string $label): string
    {
        return hash('sha256', $label);
    }
}
