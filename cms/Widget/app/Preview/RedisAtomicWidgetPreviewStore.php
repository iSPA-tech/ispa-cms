<?php

declare(strict_types=1);

namespace Cms\Widget\Preview;

use Predis\ClientInterface;

/**
 * Redis-backed {@see AtomicWidgetPreviewStore} (plan Chunk 2 Task 19, DoD
 * 1103; docs/evidence/widget-site-layout-preflight.md gap G3: "Atomic
 * get-delete ... Single-use preview-token semantics need an atomic
 * primitive (Redis GETDEL, a Lua script, or Cache::lock)").
 *
 * Chosen primitive: Redis `GETDEL` (server-side atomic get-and-delete in
 * ONE command, available since Redis 6.2 -- proven live against the
 * coordinator-provided Redis 8.8.0 instance). A Lua `EVAL` transaction was
 * the documented alternative (DoD 1103); GETDEL is preferred here because
 * it needs no script body, no `SCRIPT LOAD`/EVALSHA cache-miss handling,
 * and is exactly one round trip, same as a Lua call would be.
 *
 * Uses `predis/predis` directly (constructor-injected {@see ClientInterface})
 * rather than Laravel's `Redis` facade/`RedisManager`: the manager's
 * `database.redis.client` config key is a single GLOBAL driver choice for
 * every named connection, and this store must not assume or mutate that
 * global setting to force predis specifically for its own dedicated
 * connection. The dedicated `database.redis.widget_preview` connection
 * array is read by {@see \Cms\Widget\Providers\WidgetServiceProvider}'s
 * binding, not by this class, which only depends on the interface.
 *
 * Keys are namespaced under a fixed prefix so this store can never collide
 * with any other cache/lock key sharing the same logical Redis database.
 */
final class RedisAtomicWidgetPreviewStore implements AtomicWidgetPreviewStore
{
    private const KEY_PREFIX = 'widget_preview_session:';

    public function __construct(
        private readonly ClientInterface $client,
    ) {
    }

    public function put(string $token, string $value, int $ttlSeconds): void
    {
        // SET key value EX ttl -- one atomic command, sets the value and its
        // expiry together (no separate EXPIRE call, no window where the key
        // exists without a TTL).
        $this->client->set($this->key($token), $value, 'EX', max(1, $ttlSeconds));
    }

    public function consume(string $token): ?string
    {
        $value = $this->client->getdel($this->key($token));

        return $value === null || $value === false ? null : (string) $value;
    }

    private function key(string $token): string
    {
        return self::KEY_PREFIX . $token;
    }
}
