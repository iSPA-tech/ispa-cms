<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Removes the plain API tokens that `POST api/v3/auth/sign-in` and `POST api/v3/me/refresh` used to cache in Redis.
 *
 * Before the token security work those routes stored the whole sign-in payload, plain token included, under `cache:basic_token` (the
 * service account, one key per language variant) or `user:<uuid>`. Nothing reads those keys (decision
 * 2026-11-11), so they are deleted. Dry run by default; `--force` deletes. Only `cache:basic_token` keys and
 * `user:<uuid>` keys whose value has a `token` field are touched: other `user:` keys (the Achievements progress keys
 * use that prefix) and every other key are left alone. Output carries counts only, never a key value.
 *
 * @package Cms\Core\Console\Commands
 */
class TokensForgetCachedCommand extends Command
{
    protected $signature = 'cms:tokens-forget-cached {--force : Delete the keys (without it the command only counts them)}';

    protected $description = 'Remove plain API tokens cached in Redis by the v3 sign-in and refresh routes (dry run by default).';

    /** Keys per SCAN round trip. */
    private const SCAN_COUNT = 500;

    /**
     * List or delete the token-bearing cache keys.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        if (! config('database.redis.enable')) {
            $this->info('Redis is disabled for this application; nothing to remove.');

            return self::SUCCESS;
        }

        $redis = Redis::connection();
        $force = (bool) $this->option('force');

        $basicTokenKeys = array_values(array_filter(
            $this->scanKeys($redis, 'cache:basic_token*'),
            static fn (string $key): bool => preg_match('/^cache:basic_token(:lang:[A-Za-z0-9_-]+)?$/', $key) === 1
        ));

        $userKeys = $this->scanKeys($redis, 'user:*');
        $userKeys = array_values(array_filter(
            $userKeys,
            static fn (string $key): bool => preg_match('/^user:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1
        ));
        $tokenUserKeys = array_values(array_filter($userKeys, fn (string $key): bool => $this->carriesToken($redis->get($key))));

        $targets = array_merge($basicTokenKeys, $tokenUserKeys);

        $this->line($force ? 'Deleting cached plain tokens.' : 'Dry run: nothing is deleted. Re-run with --force to delete.');
        $this->line('cache:basic_token keys with a token: ' . count($basicTokenKeys));
        $this->line('user:<uuid> keys scanned: ' . count($userKeys));
        $this->line('user:<uuid> keys with a token: ' . count($tokenUserKeys));

        if ($force && $targets !== []) {
            foreach (array_chunk($targets, 100) as $chunk) {
                $redis->del(...$chunk);
            }
            $this->info('Deleted ' . count($targets) . ' key(s).');
        }

        return self::SUCCESS;
    }

    /**
     * Whether a cached value is a JSON object with a non-empty `token` field.
     *
     * @param mixed $value Raw Redis value.
     * @return bool
     */
    private function carriesToken(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) && isset($decoded['token']) && is_string($decoded['token']) && $decoded['token'] !== '';
    }

    /**
     * All keys matching a pattern, found with SCAN (never KEYS), without the connection prefix, deduplicated.
     *
     * @param mixed $redis Redis connection.
     * @param string $pattern Glob pattern.
     * @return list<string>
     */
    private function scanKeys(mixed $redis, string $pattern): array
    {
        $prefix = (string) config('database.redis.options.prefix', '');
        $found = [];
        $cursor = 0;

        do {
            $result = $redis->scan($cursor, ['match' => $pattern, 'count' => self::SCAN_COUNT]);
            if ($result === false || ! is_array($result)) {
                break;
            }
            [$cursor, $keys] = $result;
            foreach ((array) $keys as $key) {
                $key = (string) $key;
                $found[$prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key] = true;
            }
        } while ((string) $cursor !== '0');

        return array_keys($found);
    }
}
