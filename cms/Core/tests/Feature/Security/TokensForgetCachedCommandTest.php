<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * `cms:tokens-forget-cached` lists (dry run) or removes (--force) the plain tokens that the v3 sign-in and
 * refresh used to cache in Redis, and leaves every other key alone.
 */

use Illuminate\Support\Facades\Redis;

uses(Cms\Core\Tests\TestCase::class);

/** In-memory stand-in for the default Redis connection: strings, SCAN with MATCH, DEL. */
function p10t05FakeRedis(array $values): object
{
    return new class ($values) {
        public array $deleted = [];

        public function __construct(public array $values)
        {
        }

        public function scan($cursor, array $options = [])
        {
            $pattern = '/^' . str_replace('\*', '.*', preg_quote((string) ($options['match'] ?? '*'), '/')) . '$/';
            $keys = array_values(array_filter(array_keys($this->values), static fn ($key) => preg_match($pattern, $key) === 1));

            return [0, $keys];
        }

        public function get(string $key)
        {
            return $this->values[$key] ?? null;
        }

        public function del(string ...$keys): int
        {
            foreach ($keys as $key) {
                $this->deleted[] = $key;
                unset($this->values[$key]);
            }

            return count($keys);
        }
    };
}

/** Seed: three token-bearing keys and several keys that must survive. */
function p10t05Seed(): array
{
    $uuid = '8f5c6a62-1c1e-4b7a-9d1b-0a6f5b0c2d11';
    $other = '2b4f1f0e-77f6-4a38-9a39-5f1e0c9d7a22';

    return [
        'cache:basic_token' => json_encode(['token' => '1|plainsecret', 'expired_at' => 1, 'type' => 'Bearer']),
        'cache:basic_token:lang:1' => json_encode(['token' => '2|plainsecret', 'type' => 'Bearer']),
        'user:' . $uuid => json_encode(['token' => '3|plainsecret', 'user' => ['uuid' => $uuid]]),
        // Must survive: Achievements progress under the same prefix, a snapshot without a token, other keys.
        'user:' . $other => json_encode(['points' => 40, 'level' => 2]),
        'user:not-a-uuid' => json_encode(['token' => 'x']),
        'user:123' => 'plain string value',
        'users:' . $uuid => json_encode(['uuid' => $uuid, 'name' => 'snapshot without token']),
        'cache:basic_token_other' => json_encode(['token' => 'x']),
        'cache:settings:lang:1' => '{}',
    ];
}

beforeEach(function (): void {
    config(['database.redis.enable' => true, 'database.redis.options.prefix' => '']);
});

test('dry run lists counts only and deletes nothing', function (): void {
    $fake = p10t05FakeRedis(p10t05Seed());
    Redis::shouldReceive('connection')->andReturn($fake);

    $this->artisan('cms:tokens-forget-cached')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('cache:basic_token keys with a token: 2')
        ->expectsOutputToContain('user:<uuid> keys with a token: 1')
        ->doesntExpectOutputToContain('plainsecret')
        ->assertSuccessful();

    expect($fake->deleted)->toBe([])->and(count($fake->values))->toBe(count(p10t05Seed()));
});

test('force deletes exactly the token-bearing keys and no other key', function (): void {
    $fake = p10t05FakeRedis(p10t05Seed());
    Redis::shouldReceive('connection')->andReturn($fake);

    $this->artisan('cms:tokens-forget-cached', ['--force' => true])
        ->expectsOutputToContain('Deleted 3 key(s)')
        ->doesntExpectOutputToContain('plainsecret')
        ->assertSuccessful();

    expect($fake->deleted)->toEqualCanonicalizing([
        'cache:basic_token',
        'cache:basic_token:lang:1',
        'user:8f5c6a62-1c1e-4b7a-9d1b-0a6f5b0c2d11',
    ]);
    expect(array_keys($fake->values))->toEqualCanonicalizing([
        'user:2b4f1f0e-77f6-4a38-9a39-5f1e0c9d7a22',
        'user:not-a-uuid',
        'user:123',
        'users:8f5c6a62-1c1e-4b7a-9d1b-0a6f5b0c2d11',
        'cache:basic_token_other',
        'cache:settings:lang:1',
    ]);
});

test('with Redis disabled the command does nothing and succeeds', function (): void {
    config(['database.redis.enable' => false]);
    Redis::shouldReceive('connection')->never();

    $this->artisan('cms:tokens-forget-cached', ['--force' => true])->expectsOutputToContain('Redis is disabled')->assertSuccessful();
});
