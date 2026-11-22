<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RuntimeCacheMaintenanceService
{
    /** @return array{cleared: int, preserved: list<string>} */
    public function clearApplicationCache(): array
    {
        $store = Cache::getStore();
        if (! $store instanceof RedisStore) {
            throw new RuntimeException('Application cache maintenance requires the Redis cache store.');
        }

        $cachePrefix = $store->getPrefix();
        if ($cachePrefix === '') {
            throw new RuntimeException('Application cache maintenance requires a non-empty cache prefix.');
        }

        $connection = $store->connection();
        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection => (string) $connection->_prefix(''),
            $connection instanceof PredisConnection => (string) ($connection->getOptions()->prefix ?: ''),
            default => throw new RuntimeException('Unsupported Redis connection for scoped cache maintenance.'),
        };
        $rawPrefix = $connectionPrefix.$cachePrefix;
        $preserved = $this->preservedKeys();
        $preservedRawKeys = array_fill_keys(array_map(
            static fn (string $key): string => $rawPrefix.$key,
            $preserved,
        ), true);

        $cursor = $connection instanceof PhpRedisConnection ? null : '0';
        $cleared = 0;
        do {
            $result = $connection->scan($cursor, ['match' => $rawPrefix.'*', 'count' => 500]);
            if (! is_array($result)) {
                break;
            }

            [$cursor, $rawKeys] = $result;
            $logicalKeys = [];
            foreach (is_array($rawKeys) ? array_unique($rawKeys) : [] as $rawKey) {
                if (! is_string($rawKey) || isset($preservedRawKeys[$rawKey]) || ! str_starts_with($rawKey, $rawPrefix)) {
                    continue;
                }

                $logicalKeys[] = substr($rawKey, strlen($connectionPrefix));
            }

            if ($logicalKeys !== []) {
                $cleared += (int) $connection->del(...$logicalKeys);
            }
        } while ((int) $cursor !== 0);

        return ['cleared' => $cleared, 'preserved' => $preserved];
    }

    public function optimizeApplication(): void
    {
        if (Artisan::call('optimize') !== 0) {
            throw new RuntimeException('Application optimization failed.');
        }
    }

    /** @return list<string> */
    private function preservedKeys(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            (array) config('cache.maintenance_preserve_keys', []),
        ))));
    }
}
