<?php

namespace Tests\Unit;

use App\Constants\CacheConstants;
use App\Http\Controllers\V2\Client\AccountController;
use Cms\Content\Http\Controllers\V3\Public\PostController;
use App\Models\User;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Fluent;
use Mockery;
use Tests\TestCase;

/**
 * Legacy payload keys on the default Redis connection must carry a TTL.
 *
 * The shared .215 Redis runs `maxmemory` + `volatile-lru`, which only evicts
 * keys that have an expiry. `cache:post:slug:{slug}` and `users:{uuid}` were
 * written without one, so every post ever requested stayed resident (1,652
 * keys, ~68 MB on 2026-10-24) until Redis ran out of memory.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-10-24)
 */
class LegacyRedisPayloadTtlTest extends TestCase
{
    /** In-memory stand-in for the default Redis connection, with real TTL semantics. */
    private function fakeRedis(): object
    {
        config(['database.redis.enable' => true]);

        $redis = new class {
            public array $values = [];
            public array $ttls = [];
            public array $sets = [];

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

        Redis::shouldReceive('connection')->andReturn($redis);

        return $redis;
    }

    /** The slug detail payload expires, and so do the tag sets that index it. */
    public function test_post_detail_by_slug_cache_expires(): void
    {
        $redis = $this->fakeRedis();
        $this->mock(PostInterface::class, static function ($mock): void {
            $mock->shouldReceive('getPostDetailBySlug')
                ->with('xe-moi')
                ->andReturn(new Fluent(['id' => 7, 'name' => 'Xe mới', 'image' => null]));
        });

        app(PostController::class)->detailBySlug('xe-moi');

        $key = sprintf(CacheConstants::CACHE_POST_BY_SLUG, 'xe-moi');
        $this->assertArrayHasKey($key, $redis->values);
        $this->assertSame(['id' => 7, 'name' => 'Xe mới', 'image' => null], json_decode($redis->values[$key], true));
        $this->assertSame(3600, $redis->ttl($key));
        foreach (['cache', 'post', 'slug'] as $tag) {
            $this->assertSame(3600, $redis->ttl($tag), "tag set {$tag}");
        }
    }

    /** The client account snapshot nothing reads back expires after a day. */
    public function test_client_account_snapshot_expires(): void
    {
        $redis = $this->fakeRedis();
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('load')->andReturnSelf();
        $user->uuid = 'b1f4c2de-0000-4000-8000-000000000001';
        $this->actingAs($user);

        app(AccountController::class)->myAccount();

        $this->assertSame(CacheConstants::CLIENT_USER_SNAPSHOT_TTL, $redis->ttl('users:' . $user->uuid));
        $this->assertSame(86400, CacheConstants::CLIENT_USER_SNAPSHOT_TTL);
    }
}
