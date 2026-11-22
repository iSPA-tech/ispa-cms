<?php

declare(strict_types=1);

namespace Cms\Core\Services\Monitoring;

use Illuminate\Support\Facades\Redis;
use Predis\ClientInterface as PredisClient;
use RuntimeException;
use Throwable;

/**
 * Reads memory facts from a Redis server for alerts: INFO memory, and the biggest keys
 * in a bounded SCAN sample (MEMORY USAGE per key).
 *
 * Commands are sent raw, past the client's key prefix, so keys are reported exactly as
 * stored, with every app's prefix on a shared server (e.g. "ispacms:pro:queues:tracking").
 */
class RedisMemoryProbe
{
    /** Keys requested per SCAN call. */
    private const SCAN_COUNT = 500;

    /**
     * Used memory, configured maxmemory (0 = no limit) and eviction policy.
     *
     * @param string $connection Redis connection name (config/database.php).
     * @return array{used: int, max: int, policy: string}
     */
    public function memory(string $connection): array
    {
        $info = [];
        foreach (preg_split('/\r?\n/', (string) $this->raw($connection, ['INFO', 'memory'])) ?: [] as $line) {
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2) {
                $info[$parts[0]] = $parts[1];
            }
        }

        return [
            'used' => (int) ($info['used_memory'] ?? 0),
            'max' => (int) ($info['maxmemory'] ?? 0),
            'policy' => (string) ($info['maxmemory_policy'] ?? ''),
        ];
    }

    /**
     * Biggest keys among at most $scanLimit scanned keys of the connection's database,
     * plus $alwaysCheck keys that a partial sample may miss (the app's own queue lists).
     *
     * Groups fold digit runs, long hex strings and UUIDs into "*", so millions of small
     * keys of one kind (e.g. unique-job locks) show up as one heavy group.
     *
     * @param string $connection Redis connection name.
     * @param int $scanLimit Maximum number of keys to scan and measure.
     * @param int $top Number of keys and groups to return.
     * @param list<string> $alwaysCheck Full key names to measure in any case.
     * @return array{keys: int, scanned: int, top: list<array{key: string, bytes: int}>, groups: list<array{group: string, keys: int, bytes: int}>}
     */
    public function biggestKeys(string $connection, int $scanLimit, int $top, array $alwaysCheck = []): array
    {
        $names = [];
        $cursor = '0';

        do {
            [$cursor, $keys] = $this->raw($connection, ['SCAN', $cursor, 'COUNT', (string) self::SCAN_COUNT]);
            foreach ((array) $keys as $key) {
                if (count($names) >= $scanLimit) {
                    break 2;
                }
                $names[(string) $key] = true;
            }
        } while ((string) $cursor !== '0');

        $scanned = count($names);
        foreach ($alwaysCheck as $key) {
            $names[$key] = true;
        }

        $sizes = [];
        $groups = [];
        foreach (array_keys($names) as $key) {
            $key = (string) $key;
            $bytes = (int) $this->raw($connection, ['MEMORY', 'USAGE', $key]);
            if ($bytes <= 0) {
                // Missing or expired since the scan.
                continue;
            }

            $sizes[$key] = $bytes;
            $group = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{16,}|\d+/i', '*', $key);
            $groups[$group]['keys'] = ($groups[$group]['keys'] ?? 0) + 1;
            $groups[$group]['bytes'] = ($groups[$group]['bytes'] ?? 0) + $bytes;
        }

        arsort($sizes);
        uasort($groups, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $topKeys = [];
        foreach (array_slice($sizes, 0, $top, true) as $key => $bytes) {
            $topKeys[] = ['key' => (string) $key, 'bytes' => $bytes];
        }

        $topGroups = [];
        foreach ($groups as $group => $stats) {
            // A single-key group repeats the key list.
            if ($stats['keys'] > 1 && count($topGroups) < $top) {
                $topGroups[] = ['group' => (string) $group, 'keys' => $stats['keys'], 'bytes' => $stats['bytes']];
            }
        }

        return [
            'keys' => (int) $this->raw($connection, ['DBSIZE']),
            'scanned' => $scanned,
            'top' => $topKeys,
            'groups' => $topGroups,
        ];
    }

    /**
     * Claim an alert slot shared by every app on this Redis (SET NX EX on an unprefixed key).
     *
     * A failing write (e.g. out of memory) counts as claimed so the alert is still sent.
     *
     * @param string $connection Redis connection name.
     * @param string $key Unprefixed key shared by all apps.
     * @param int $seconds Slot lifetime.
     * @return bool True when this caller should send the alert.
     */
    public function claim(string $connection, string $key, int $seconds): bool
    {
        try {
            $reply = $this->raw($connection, ['SET', $key, '1', 'NX', 'EX', (string) max(1, $seconds)]);
        } catch (Throwable) {
            return true;
        }

        return $reply === true || $reply === 'OK';
    }

    /**
     * Run a raw command, bypassing the client's key prefix.
     *
     * @param string $connection Redis connection name.
     * @param list<string> $command Command name and arguments.
     * @return mixed Raw reply.
     *
     * @throws RuntimeException When Redis answers with an error.
     */
    protected function raw(string $connection, array $command): mixed
    {
        $client = Redis::connection($connection)->client();

        if ($client instanceof PredisClient) {
            $reply = $client->executeRaw($command, $error);
            if ($error) {
                throw new RuntimeException((string) $reply);
            }

            return $reply;
        }

        $client->clearLastError();
        $reply = $client->rawCommand(...$command);
        if ($reply === false && $client->getLastError() !== null) {
            throw new RuntimeException((string) $client->getLastError());
        }

        return $reply;
    }
}
