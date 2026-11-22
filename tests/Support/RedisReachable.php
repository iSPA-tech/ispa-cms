<?php

namespace Tests\Support;

use Illuminate\Redis\RedisManager;
use Throwable;

/**
 * Skips a test that needs a live Redis server when the connection cannot be opened: no server, or a password the test
 * environment does not have. A fresh install without Redis then runs the rest of the suite instead of failing here.
 * The probe uses its own manager: resolving the shared `redis` instance here would freeze its connection list before
 * a test adds connections to config.
 */
final class RedisReachable
{
    public static function orSkip(string $connection = 'cache'): void
    {
        $manager = new RedisManager(app(), (string) config('database.redis.client', 'phpredis'), (array) config('database.redis'));

        try {
            $manager->connection($connection)->ping();
        } catch (Throwable $e) {
            test()->markTestSkipped(sprintf('Redis connection [%s] not reachable: %s', $connection, $e->getMessage()));
        } finally {
            $manager->purge($connection);
        }
    }
}
