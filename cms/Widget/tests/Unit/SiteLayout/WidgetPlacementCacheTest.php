<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\SiteLayout;

use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Chunk 2 Task 24: placement metadata cache (plan lines 1254-1291, DoD
 * 1277-1278, 1280). Runs against the coordinator-provided ephemeral Redis at
 * 127.0.0.1:6399 (database 13, dedicated logical index -- distinct from
 * Task 19's own widget_preview database 14/test database 15) -- never the
 * application's `.env` production Redis (REDIS_ENABLE=false, password-
 * protected). Mirrors WidgetPreviewSessionTest's own dedicated-predis-
 * connection-in-setUp() pattern (Task 19).
 *
 * Pure store-contract tests only: key building, get/put/forget round trips,
 * TTL, and cross-node (two independent client instances against the same
 * Redis) read-after-write visibility. Production wiring into
 * WidgetSlotRuntimeService/WidgetPlacementResolver is proved by
 * WidgetRuntimeCacheTelemetryIntegrationTest, not here.
 */
final class WidgetPlacementCacheTest extends TestCase
{
    private const HOST = '127.0.0.1';

    private const PORT = 6399;

    private const DATABASE = 13;

    /**
     * Whether the ephemeral test Redis (127.0.0.1:6399) accepts TCP connections.
     * Probed once per PHP process so unreachable-Redis runs skip cheaply instead
     * of building huge Predis error traces.
     */
    private static function ephemeralRedisReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            // A no-op handler keeps the expected connection warning out of the test report.
            set_error_handler(static fn (): bool => true);
            $socket = fsockopen('127.0.0.1', 6399, $errno, $errstr, 0.5);
            restore_error_handler();
            $reachable = $socket !== false;
            if ($socket !== false) {
                fclose($socket);
            }
        }

        return $reachable;
    }

    protected function setUp(): void
    {
        if (! self::ephemeralRedisReachable()) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable');
        }

        parent::setUp();

        config(['database.redis.widget_placement_cache' => [
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => self::DATABASE,
            'timeout' => 2.0,
        ]]);

        $this->rawClient()->flushdb();
    }

    private function rawClient(): PredisClient
    {
        return new PredisClient([
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => self::DATABASE,
        ]);
    }

    private function cache(): WidgetPlacementCache
    {
        return new WidgetPlacementCache($this->rawClient());
    }

    // -----------------------------------------------------------------
    // buildKey(): DoD 1278 -- project, locale, Slot, template key, target,
    // active Release version. Every dimension must produce a distinct key.
    // -----------------------------------------------------------------

    public function test_build_key_differs_when_project_name_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);
        $b = WidgetPlacementCache::buildKey('Default', 'vi', 'site.header.before', null, null, null, 3);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_differs_when_locale_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'en', 'site.header.before', null, null, null, 3);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_differs_when_slot_key_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.footer.before', null, null, null, 3);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_differs_when_template_key_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'page.before_content', 'page.static', null, null, 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'page.before_content', 'site.shell', null, null, 3);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_differs_when_target_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'page.before_content', 'page.static', 'page', 'uuid-1', 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'page.before_content', 'page.static', 'page', 'uuid-2', 3);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_differs_when_release_version_differs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 4);

        $this->assertNotSame($a, $b);
    }

    public function test_build_key_is_deterministic_for_identical_inputs(): void
    {
        $a = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);
        $b = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 3);

        $this->assertSame($a, $b);
    }

    // -----------------------------------------------------------------
    // get/put round trip -- metadata only (DoD 1277).
    // -----------------------------------------------------------------

    public function test_get_returns_null_for_a_missing_key(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);

        $this->assertNull($this->cache()->get($key));
    }

    public function test_put_then_get_round_trips_the_exact_candidate_metadata(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $candidates = [
            ['scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'position' => 0, 'widget_instance_version_id' => 42],
        ];

        $cache = $this->cache();
        $cache->put($key, $candidates, 'ExampleProject', 'layout-uuid-1');

        $this->assertSame($candidates, $cache->get($key));
    }

    public function test_put_never_persists_an_html_field(): void
    {
        // Structural proof of DoD 1277 ("never arbitrary rendered HTML"):
        // put() has no parameter through which an 'html' key could travel,
        // so the raw stored payload can never contain rendered markup --
        // proven here by inspecting the RAW Redis value directly (not just
        // round-tripping through get(), which would only prove decode
        // symmetry).
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $candidates = [
            ['scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'position' => 0, 'widget_instance_version_id' => 42],
        ];

        $this->cache()->put($key, $candidates, 'ExampleProject', 'layout-uuid-1');

        $raw = (string) $this->rawClient()->get($key);
        $this->assertStringNotContainsString('<', $raw, 'Stored payload must never contain markup.');
        $this->assertStringNotContainsString('html', $raw);
    }

    public function test_forget_removes_the_entry(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $cache = $this->cache();
        $cache->put($key, [['scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'position' => 0, 'widget_instance_version_id' => 1]], 'ExampleProject', 'layout-uuid-1');

        $cache->forget($key);

        $this->assertNull($cache->get($key));
    }

    public function test_a_corrupt_stored_value_is_treated_as_a_miss_not_an_error(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $this->rawClient()->set($key, 'not-json{{{', 'EX', 60);

        $this->assertNull($this->cache()->get($key));
    }

    public function test_put_respects_a_bounded_ttl(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $this->cache()->put($key, [], 'ExampleProject', 'layout-uuid-1', 5);

        $ttl = (int) $this->rawClient()->ttl($key);

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(5, $ttl);
    }

    // -----------------------------------------------------------------
    // Cross-node read-after-write: two INDEPENDENT client instances
    // against the SAME Redis 6399, simulating two public nodes.
    // -----------------------------------------------------------------

    public function test_a_value_written_by_one_node_is_immediately_visible_from_a_different_node_instance(): void
    {
        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $candidates = [['scope_type' => 'global', 'scope_key' => null, 'target_type' => null, 'target_id' => null, 'position' => 0, 'widget_instance_version_id' => 7]];

        $writerNode = new WidgetPlacementCache($this->rawClient());
        $readerNode = new WidgetPlacementCache($this->rawClient());

        $writerNode->put($key, $candidates, 'ExampleProject', 'layout-uuid-1');

        $this->assertSame($candidates, $readerNode->get($key), 'A different node instance must observe the write immediately (shared Redis, not per-instance memory).');
    }

    // -----------------------------------------------------------------
    // forgetAllForLayout(): the invalidation listener's own primitive.
    // -----------------------------------------------------------------

    public function test_forget_all_for_layout_removes_every_tracked_key_for_that_project_and_layout(): void
    {
        $cache = $this->cache();
        $keyA = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $keyB = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.footer.before', null, null, null, 1);
        $otherProjectKey = WidgetPlacementCache::buildKey('Default', 'vi', 'site.header.before', null, null, null, 1);

        $cache->put($keyA, [], 'ExampleProject', 'layout-uuid-1');
        $cache->put($keyB, [], 'ExampleProject', 'layout-uuid-1');
        $cache->put($otherProjectKey, [], 'Default', 'layout-uuid-2');

        $removed = $cache->forgetAllForLayout('ExampleProject', 'layout-uuid-1');

        $this->assertSame(2, $removed);
        $this->assertNull($cache->get($keyA));
        $this->assertNull($cache->get($keyB));
        $this->assertNotNull($cache->get($otherProjectKey), 'A different project/layout must be untouched.');
    }

    public function test_forget_all_for_layout_is_safe_to_call_when_nothing_was_ever_cached(): void
    {
        $removed = $this->cache()->forgetAllForLayout('ExampleProject', 'never-cached-layout');

        $this->assertSame(0, $removed);
    }

    // -----------------------------------------------------------------
    // Zero-provider-wiring default construction (mirrors
    // WidgetRuntimeControlTopologyGuard's precedent): constructible with
    // no argument at all, self-configuring from config()/env() so
    // WidgetServiceProvider.php never needs a new binding.
    // -----------------------------------------------------------------

    public function test_is_constructible_with_zero_arguments_using_the_configured_connection(): void
    {
        $cache = new WidgetPlacementCache();

        $key = WidgetPlacementCache::buildKey('ExampleProject', 'vi', 'site.header.before', null, null, null, 1);
        $cache->put($key, [], 'ExampleProject', 'layout-uuid-1');

        $this->assertSame([], $cache->get($key));
    }
}
