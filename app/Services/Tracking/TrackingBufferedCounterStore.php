<?php

namespace App\Services\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use JsonException;
use RuntimeException;

class TrackingBufferedCounterStore
{
    public const RESULT_BUFFERED = 'buffered';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_FALLBACK = 'fallback';

    private const RECORD_SCRIPT = <<<'LUA'
local driver = redis.call('HGET', KEYS[1], 'driver')
local epoch = redis.call('HGET', KEYS[1], 'epoch')
local generation = redis.call('HGET', KEYS[1], 'write_generation')
if driver ~= 'redis' or not epoch or not generation then
    return 'fallback'
end

local bucket_id = ARGV[2] .. ':e' .. epoch
local bucket = ARGV[1] .. ':bucket:' .. bucket_id
local targets = bucket .. ':targets'
local metadata = bucket .. ':metadata'
local events = bucket .. ':events'
local registry = ARGV[1] .. ':buckets'

local status = redis.call('HGET', bucket, '__status')
if status and status ~= 'open' then
    return 'fallback'
end

if redis.call('SADD', events, ARGV[3]) == 0 then
    return 'duplicate|' .. bucket_id
end

redis.call('HSET', bucket, '__status', 'open', '__date', ARGV[4], '__hour', ARGV[5], '__epoch', epoch, '__generation', generation)
local target_key = ARGV[6]
if target_key ~= '' then
    redis.call('HSETNX', metadata, target_key, ARGV[7])
end

local index = 9
while index < #ARGV do
    local metric = ARGV[index]
    local value = tonumber(ARGV[index + 1]) or 0
    if value ~= 0 then
        redis.call('HINCRBY', bucket, metric, value)
        if target_key ~= '' then
            redis.call('HINCRBY', targets, metric .. '|' .. target_key, value)
        end
    end
    index = index + 2
end

redis.call('ZADD', registry, ARGV[8], bucket_id)
local ttl = tonumber(ARGV[#ARGV]) or 259200
redis.call('EXPIRE', bucket, ttl)
redis.call('EXPIRE', targets, ttl)
redis.call('EXPIRE', metadata, ttl)
redis.call('EXPIRE', events, ttl)
redis.call('EXPIRE', registry, ttl)
return 'buffered|' .. bucket_id
LUA;

    /** @param array<string, mixed> $payload */
    public function record(array $payload): string
    {
        $occurredAt = $payload['occurred_at'] ?? null;
        if (!$occurredAt instanceof CarbonImmutable) {
            throw new RuntimeException('Buffered tracking requires an immutable occurrence time.');
        }

        $local = $occurredAt->setTimezone((string) config('tracking.analytics_timezone'));
        $metadata = json_encode([
            'target_type' => $payload['target_type'] ?? null,
            'target_id' => $payload['target_id'] ?? null,
            'target_kind' => $payload['target_kind'] ?? null,
            'display_label' => $payload['display_label'] ?? null,
            'public_path' => $payload['public_path'] ?? null,
            'is_rankable' => (bool) ($payload['is_rankable'] ?? false),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $deltas = array_filter(
            (array) ($payload['deltas'] ?? []),
            static fn ($value, $metric): bool => is_string($metric) && is_int($value),
            ARRAY_FILTER_USE_BOTH
        );
        ksort($deltas);
        $arguments = [
            $this->scriptPrefix(),
            $local->format('Y-m-d\TH'),
            (string) ($payload['event_id'] ?? ''),
            $local->toDateString(),
            $local->format('H'),
            (string) ($payload['target_key'] ?? ''),
            $metadata,
            (string) $local->getTimestamp(),
        ];
        foreach ($deltas as $metric => $value) {
            $arguments[] = $metric;
            $arguments[] = (string) $value;
        }
        $arguments[] = (string) config('tracking.buffer.retention_seconds', 259200);

        $result = (string) $this->connection()->eval(
            self::RECORD_SCRIPT,
            1,
            $this->controlKey(),
            ...$arguments
        );

        return explode('|', $result, 2)[0];
    }

    public function activateRedis(int $epoch, int $writeGeneration = 1): void
    {
        $this->connection()->hmset($this->controlKey(), [
            'driver' => 'redis',
            'epoch' => max(1, $epoch),
            'write_generation' => max(1, $writeGeneration),
        ]);
    }

    public function activateReceipt(): void
    {
        $this->connection()->hset($this->controlKey(), 'driver', 'receipt');
    }

    public function seal(string $bucketId): void
    {
        $this->connection()->hset($this->bucketKey($bucketId), '__status', 'sealed');
    }

    /** @return list<string> */
    public function sealAll(): array
    {
        $buckets = $this->registeredBuckets();
        foreach ($buckets as $bucket) {
            $this->seal($bucket);
        }

        return $buckets;
    }

    /** @return list<string> */
    public function sealDue(CarbonImmutable $cutoff): array
    {
        $due = array_values(array_filter(
            $this->registeredBuckets(),
            static function (string $bucketId) use ($cutoff): bool {
                $hour = strstr($bucketId, ':e', true);

                return is_string($hour)
                    && CarbonImmutable::createFromFormat('Y-m-d\TH', $hour, $cutoff->getTimezone())
                        ->lessThanOrEqualTo($cutoff);
            }
        ));
        foreach ($due as $bucket) {
            $this->seal($bucket);
        }

        return $due;
    }

    /** @return list<string> */
    public function registeredBuckets(): array
    {
        return array_values(array_map(
            'strval',
            $this->connection()->zrange($this->registryKey(), 0, -1)
        ));
    }

    /** @return array<string, mixed> */
    public function snapshot(string $bucketId): array
    {
        $bucket = $this->connection()->hgetall($this->bucketKey($bucketId));
        if ($bucket === []) {
            throw new RuntimeException("Tracking buffer bucket [{$bucketId}] is missing.");
        }
        $metadata = $this->connection()->hgetall($this->bucketKey($bucketId) . ':metadata');
        $targets = [];
        foreach ($this->connection()->hgetall($this->bucketKey($bucketId) . ':targets') as $field => $value) {
            [$metric, $targetKey] = array_pad(explode('|', (string) $field, 2), 2, null);
            if ($metric === null || $targetKey === null) {
                throw new RuntimeException("Malformed tracking buffer field [{$field}].");
            }
            $targets[$targetKey]['deltas'][$metric] = (int) $value;
        }
        foreach ($targets as $targetKey => &$target) {
            try {
                $target = [
                    ...((array) json_decode((string) ($metadata[$targetKey] ?? '{}'), true, flags: JSON_THROW_ON_ERROR)),
                    'deltas' => $target['deltas'],
                ];
            } catch (JsonException $exception) {
                throw new RuntimeException("Malformed tracking metadata for [{$targetKey}].", 0, $exception);
            }
        }
        unset($target);

        $site = [];
        foreach ($bucket as $field => $value) {
            if (!str_starts_with((string) $field, '__')) {
                $site[(string) $field] = (int) $value;
            }
        }
        ksort($site);
        ksort($targets);

        return [
            'bucket_id' => $bucketId,
            'date' => (string) ($bucket['__date'] ?? ''),
            'status' => (string) ($bucket['__status'] ?? ''),
            'write_generation' => (int) ($bucket['__generation'] ?? 0),
            'site' => $site,
            'targets' => $targets,
        ];
    }

    public function delete(string $bucketId): void
    {
        $bucket = $this->bucketKey($bucketId);
        $this->connection()->del([
            $bucket,
            $bucket . ':targets',
            $bucket . ':metadata',
            $bucket . ':events',
        ]);
        $this->connection()->zrem($this->registryKey(), $bucketId);
    }

    public function ping(): bool
    {
        return strtoupper((string) $this->connection()->ping()) === 'PONG';
    }

    public function durable(): bool
    {
        if (!$this->ping()) {
            return false;
        }
        if (!config('tracking.buffer.require_aof', true)) {
            return true;
        }

        $info = (array) $this->connection()->info('persistence');
        $persistence = (array) ($info['Persistence'] ?? $info);

        return (string) ($persistence['aof_enabled'] ?? '0') === '1'
            && strtolower((string) ($persistence['aof_last_write_status'] ?? '')) === 'ok'
            && (string) ($persistence['loading'] ?? '1') === '0';
    }

    public function usesDedicatedDatabase(): bool
    {
        $connection = (string) config('tracking.buffer.connection', 'tracking');
        $tracking = (array) config('database.redis.' . $connection, []);
        $cache = (array) config('database.redis.cache', []);

        if ($connection === 'cache' || $tracking === [] || $cache === []) {
            return false;
        }

        $identity = static fn (array $config): array => array_map(
            'strval',
            array_intersect_key($config, array_flip(['url', 'host', 'port', 'database']))
        );

        return $identity($tracking) !== $identity($cache);
    }

    public function controlMatches(int $epoch, int $writeGeneration): bool
    {
        $control = $this->connection()->hgetall($this->controlKey());

        return ($control['driver'] ?? null) === 'redis'
            && (int) ($control['epoch'] ?? 0) === $epoch
            && (int) ($control['write_generation'] ?? 0) === $writeGeneration;
    }

    private function connection(): Connection
    {
        return Redis::connection((string) config('tracking.buffer.connection', 'tracking'));
    }

    private function prefix(): string
    {
        return rtrim((string) config('tracking.buffer.prefix', 'tracking:buffer'), ':');
    }

    private function scriptPrefix(): string
    {
        return (string) config('database.redis.options.prefix', '') . $this->prefix();
    }

    private function controlKey(): string
    {
        return $this->prefix() . ':control';
    }

    private function registryKey(): string
    {
        return $this->prefix() . ':buckets';
    }

    private function bucketKey(string $bucketId): string
    {
        return $this->prefix() . ':bucket:' . $bucketId;
    }
}
