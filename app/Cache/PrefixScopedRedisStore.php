<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace App\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;

/**
 * Redis cache store whose flush() removes only this site's keys.
 *
 * Laravel's RedisStore::flush() runs FLUSHDB: it empties the whole Redis database and ignores the cache prefix. On a
 * server where several sites share one cache database (.215: Redis 6380 db 1, about 11 runtimes) every `cache:clear`,
 * `optimize:clear` or `Cache::flush()` on one site emptied the cache of all of them (incidents 2026-11-22 and
 * 2026-11-27). This store deletes only the keys under its own prefix (connection prefix + cache prefix), scanning in
 * batches the way RedisStore::currentTags() does, so it works with and without phpredis SCAN_PREFIX. With no prefix at
 * all the store owns the database and FLUSHDB is kept.
 *
 * Registered as the `redis` cache driver by App\Providers\AppServiceProvider::register().
 *
 * @package App\Cache
 * @author  iSPA Team
 * @since   2026.11 (2026-11-27)
 */
class PrefixScopedRedisStore extends RedisStore
{
    /** Keys asked for per SCAN round and deleted per DEL call. */
    private const CHUNK = 1000;

    /**
     * Remove every item of this store (only keys under its prefix).
     *
     * @return bool
     */
    public function flush()
    {
        $connection = $this->connection();

        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection => (string) $connection->_prefix(''),
            $connection instanceof PredisConnection => (string) ($connection->getOptions()->prefix ?: ''),
            default => '',
        };
        $storePrefix = (string) $this->getPrefix();

        if ($connectionPrefix === '' && $storePrefix === '') {
            return parent::flush();
        }

        $defaultCursor = $connection instanceof PhpRedisConnection && version_compare((string) phpversion('redis'), '6.1.0', '>=')
            ? null
            : '0';

        // PhpRedis adds the connection prefix to the pattern itself when "SCAN_PREFIX" is enabled.
        $scanAddsPrefix = $connection instanceof PhpRedisConnection
            && defined('Redis::SCAN_PREFIX')
            && $connection->client()->getOption(\Redis::OPT_SCAN) === \Redis::SCAN_PREFIX;
        $match = self::globEscape($scanAddsPrefix ? $storePrefix : $connectionPrefix . $storePrefix) . '*';

        $cursor = $defaultCursor;
        do {
            $result = $connection->scan($cursor, ['match' => $match, 'count' => self::CHUNK]);
            if (!is_array($result)) {
                break;
            }

            [$cursor, $keys] = $result;
            if (!is_array($keys) || $keys === []) {
                continue;
            }

            // SCAN returns full key names; the client adds the connection prefix again on DEL.
            $keys = array_map(
                static fn (string $key): string => $connectionPrefix !== '' && str_starts_with($key, $connectionPrefix)
                    ? substr($key, strlen($connectionPrefix))
                    : $key,
                array_values(array_unique($keys))
            );

            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                $connection->del(...$chunk);
            }
        } while (((string) $cursor) !== ((string) $defaultCursor));

        return true;
    }

    /**
     * Escape the glob characters SCAN MATCH understands.
     */
    private static function globEscape(string $value): string
    {
        return (string) preg_replace('/([*?\[\]\\\\])/', '\\\\$1', $value);
    }
}
