<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Resolution;

use Predis\Client as PredisClient;
use Predis\ClientInterface;
use Throwable;

/**
 * Published-Placement METADATA cache (plan Chunk 2 Task 24, lines 1254-1291,
 * DoD 1277-1278, 1280; architecture.md "Site Layout MVP permanently
 * bypasses full-page/CDN caching..." neighbourhood and the Data Provider
 * Registry's own "cache/context-safety policy" precedent).
 *
 * NEVER caches rendered HTML, provider output, or Input Values -- only the
 * small, stable set of fields needed to re-run {@see WidgetPlacementResolver::resolve()}
 * and re-render fresh on every request (`scope_type`, `scope_key`,
 * `target_type`, `target_id`, `position`, `widget_instance_version_id`).
 * This is a STRUCTURAL guarantee, not a convention: {@see self::put()}'s
 * parameter shape has no channel through which an `html` string could
 * travel (DoD 1277).
 *
 * Keyed by project, locale, Slot, template key, target, and the active
 * Release VERSION NUMBER (DoD 1278, {@see self::buildKey()}) -- publishing a
 * new Release changes the version number embedded in every subsequent
 * lookup's key, so a stale entry for a superseded Release is simply never
 * read again (key-versioning invalidation). {@see self::forgetAllForLayout()}
 * additionally performs ACTIVE cleanup of every key this cache has ever
 * written for one (project, layout uuid) pair, via a companion Redis SET
 * index -- used by {@see \Cms\Widget\Listeners\InvalidateWidgetPlacementCache}
 * so a publish/rollback also proactively frees the superseded entries
 * rather than merely letting them expire on their own TTL.
 *
 * Uses `predis/predis` directly against a DEDICATED connection, mirroring
 * {@see \Cms\Widget\Preview\RedisAtomicWidgetPreviewStore}'s established
 * Task 19 precedent exactly (same reasoning: `database.redis.client` is one
 * GLOBAL driver choice for every named Laravel-managed connection, and this
 * store must not assume or mutate that global setting). DELIBERATELY
 * constructible with a nullable, self-defaulting client argument -- this
 * task's allowed_paths does not include WidgetServiceProvider.php, so there
 * is no container binding this task may add (same zero-provider-wiring
 * reasoning documented on
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard}).
 * Laravel's container auto-resolves this class via reflection with the
 * default (null) argument, which self-constructs a real Predis client from
 * `database.redis.widget_placement_cache` (or standard `REDIS_*` env keys
 * on a dedicated logical database index) -- so every real consumer
 * (WidgetPlacementResolver, WidgetSlotRuntimeService, the invalidation
 * listener) gets a genuinely shared, cross-node-visible store with zero
 * additional wiring, exactly like {@see \Cms\Widget\Preview\AtomicWidgetPreviewStore}'s
 * production binding already does for the preview store.
 */
final class WidgetPlacementCache
{
    public const DEFAULT_TTL_SECONDS = 300;

    private const KEY_PREFIX = 'widget_placement_cache:';

    private const INDEX_PREFIX = 'widget_placement_cache_index:';

    /** Placeholder for a null template key/target dimension in the composite key (DoD 1278: the key must still represent all six dimensions). */
    private const KEY_NULL_MARKER = '-';

    private readonly ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? self::makeDefaultClient();
    }

    /**
     * Canonical composite key: project, locale, Slot, template key, target
     * type/id, and active Release version (DoD 1278, exhaustively). A null
     * template key or target dimension (the current root WidgetSlotRuntime
     * call site cannot supply either -- see WidgetSlotRuntimeService's own
     * docblock) is represented by a fixed marker so the dimension is still
     * PRESENT in the key rather than silently omitted.
     */
    public static function buildKey(
        string $projectName,
        string $locale,
        string $slotKey,
        ?string $templateKey,
        ?string $targetType,
        ?string $targetId,
        int $releaseVersion,
    ): string {
        return self::KEY_PREFIX . implode('|', [
            $projectName,
            $locale,
            $slotKey,
            $templateKey ?? self::KEY_NULL_MARKER,
            $targetType ?? self::KEY_NULL_MARKER,
            $targetId ?? self::KEY_NULL_MARKER,
            (string) $releaseVersion,
        ]);
    }

    /**
     * @param list<array{scope_type: string, scope_key: ?string, target_type: ?string, target_id: ?string, position: int, widget_instance_version_id: int}> $candidates
     *        metadata rows only -- see class docblock. Never rendered HTML.
     */
    public function put(string $key, array $candidates, string $projectName, string $layoutUuid, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        $ttl = max(1, $ttlSeconds);
        $payload = json_encode(['candidates' => $candidates], JSON_THROW_ON_ERROR);

        $this->client->set($key, $payload, 'EX', $ttl);

        // Track this key against its owning (project, layout) so a publish/
        // rollback can proactively purge every entry it ever wrote, not
        // just let old-version keys expire passively.
        $indexKey = self::indexKey($projectName, $layoutUuid);
        $this->client->sadd($indexKey, [$key]);
        $this->client->expire($indexKey, $ttl);
    }

    /**
     * @return list<array{scope_type: string, scope_key: ?string, target_type: ?string, target_id: ?string, position: int, widget_instance_version_id: int}>|null
     *         null on a miss, an unreadable/corrupt stored value, or any
     *         backend failure -- callers always treat null as "recompute",
     *         never as an error to propagate (same fail-soft posture this
     *         module uses throughout the render path).
     */
    public function get(string $key): ?array
    {
        try {
            $raw = $this->client->get($key);
        } catch (Throwable) {
            return null;
        }

        if ($raw === null || $raw === false) {
            return null;
        }

        try {
            /** @var array{candidates?: mixed} $decoded */
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded['candidates'] ?? null) ? $decoded['candidates'] : null;
    }

    public function forget(string $key): void
    {
        $this->client->del([$key]);
    }

    /**
     * Actively purges every key this cache has ever written for one
     * (project, layout uuid) pair -- the invalidation listener's own
     * primitive. Safe to call when nothing was ever cached.
     *
     * @return int number of metadata keys removed (diagnostic only).
     */
    public function forgetAllForLayout(string $projectName, string $layoutUuid): int
    {
        $indexKey = self::indexKey($projectName, $layoutUuid);

        try {
            $members = $this->client->smembers($indexKey);
        } catch (Throwable) {
            return 0;
        }

        if (!is_array($members) || $members === []) {
            return 0;
        }

        $this->client->del($members);
        $this->client->del([$indexKey]);

        return count($members);
    }

    private static function indexKey(string $projectName, string $layoutUuid): string
    {
        return self::INDEX_PREFIX . $projectName . ':' . $layoutUuid;
    }

    /**
     * Dedicated connection array, distinct from the application's own
     * `.env`-driven `database.redis.default`/`cache` connections (disabled/
     * password-protected in this deployment) AND from Task 19's own
     * `database.redis.widget_preview` connection -- separate logical
     * database index so key-scanning/flush operations in either store's
     * test suite can never collide with the other's keys. Absent any
     * override, falls back to the standard REDIS_* environment keys, same
     * pattern as {@see \Cms\Widget\Providers\WidgetServiceProvider}'s own
     * AtomicWidgetPreviewStore factory closure (this class cannot rely on
     * that closure existing -- see class docblock -- so it replicates the
     * identical fallback shape locally).
     */
    private static function makeDefaultClient(): ClientInterface
    {
        $appRedis = (array) config('database.redis.default', []);
        $config = config('database.redis.widget_placement_cache', [
            'host' => (string) ($appRedis['host'] ?? '127.0.0.1'),
            'port' => (int) ($appRedis['port'] ?? 6379),
            'password' => ($appRedis['password'] ?? null) ?: null,
            'database' => 13,
        ]);

        return new PredisClient($config);
    }
}
