<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Helpers\RedisHelper;

test('redis helper builds keys from mixed arrays', function () {
    $key = RedisHelper::makeKey(['cache', 'posts', 'id' => 42]);

    expect($key)->toBe('cache.posts.id:42');
});

test('redis helper parses keys into parts', function () {
    $parsed = RedisHelper::parseKey('cache.posts.lang:en');

    expect($parsed['group'])->toBe('cache');
    expect($parsed['type'])->toBe('posts');
    expect($parsed['options'])->toBe(['lang:en']);
});

test('redis helper parses simple keys', function () {
    $parsed = RedisHelper::parseKey('group.type.option');

    expect($parsed['group'])->toBe('group');
    expect($parsed['type'])->toBe('type');
    expect($parsed['options'])->toBe(['option']);
});

/**
 * Minimal in-memory stand-in for the default Redis connection: strings, sets
 * and per-key TTLs, with real TTL semantics (-2 missing, -1 no expiry).
 */
function redisHelperFakeConnection(): object
{
    return new class {
        public array $values = [];
        public array $sets = [];
        public array $ttls = [];

        public function set(string $key, mixed $value, ...$options): void
        {
            $this->values[$key] = $value;
            unset($this->ttls[$key]);
            if (($options[0] ?? null) === 'EX') {
                $this->ttls[$key] = (int) $options[1];
            }
        }

        public function sadd(string $key, string $member): void
        {
            $this->sets[$key][$member] = true;
        }

        public function ttl(string $key): int
        {
            if (!isset($this->values[$key]) && !isset($this->sets[$key])) {
                return -2;
            }

            return $this->ttls[$key] ?? -1;
        }

        public function expire(string $key, int $seconds): void
        {
            $this->ttls[$key] = $seconds;
        }

        public function persist(string $key): void
        {
            unset($this->ttls[$key]);
        }
    };
}

function redisHelperUseFakeRedis(): object
{
    config(['database.redis.enable' => true]);
    $redis = redisHelperFakeConnection();
    \Illuminate\Support\Facades\Redis::shouldReceive('connection')->andReturn($redis);

    return $redis;
}

test('a TTL write gives a new tag set the same TTL so it cannot outlive its members', function () {
    $redis = redisHelperUseFakeRedis();

    RedisHelper::set('cache:post:slug:xe-moi', ['id' => 1], 3600);

    expect($redis->ttl('cache:post:slug:xe-moi'))->toBe(3600);
    foreach (['cache', 'post', 'slug'] as $tag) {
        expect($redis->sets[$tag])->toHaveKey('cache:post:slug:xe-moi');
        expect($redis->ttl($tag))->toBe(3600);
    }
});

test('a TTL write extends a shorter-lived tag set but never shortens a longer one', function () {
    $redis = redisHelperUseFakeRedis();
    $redis->sets['cache'] = ['cache:other' => true];
    $redis->ttls['cache'] = 60;
    $redis->sets['post'] = ['cache:post:latest:x' => true];
    $redis->ttls['post'] = 86400;

    RedisHelper::set('cache:post:slug:xe-moi', ['id' => 1], 3600);

    expect($redis->ttl('cache'))->toBe(3600);
    expect($redis->ttl('post'))->toBe(86400);
});

test('a TTL write leaves a persistent tag set persistent', function () {
    $redis = redisHelperUseFakeRedis();
    $redis->sets['slug'] = ['cache:post:slug:legacy' => true];

    RedisHelper::set('cache:post:slug:xe-moi', ['id' => 1], 3600);

    expect($redis->ttl('slug'))->toBe(-1);
    expect($redis->sets['slug'])->toHaveKey('cache:post:slug:xe-moi');
});

test('a write without TTL keeps its tag sets persistent so delByTags still finds it', function () {
    $redis = redisHelperUseFakeRedis();
    $redis->sets['posts'] = ['posts.other' => true];
    $redis->ttls['posts'] = 60;

    RedisHelper::set(['posts', 'public_posts', 'vi'], ['id' => 1]);

    expect($redis->ttl('posts.public_posts.vi'))->toBe(-1);
    expect($redis->ttl('posts'))->toBe(-1);
    expect($redis->ttl('public_posts'))->toBe(-1);
    expect($redis->sets['posts'])->toHaveKey('posts.public_posts.vi');
});
