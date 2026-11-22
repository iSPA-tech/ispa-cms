<?php

namespace Tests\Unit\Services;

use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ContentPostCacheInvalidationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_versioned_key_moves_to_a_new_namespace_after_scope_bump(): void
    {
        $versions = new ContentCacheVersionService();
        $scope = ContentPostCacheInvalidationService::latestScope('post', 1);
        $baseKey = 'cache:post:latest:5:lang:1';

        $oldKey = $versions->versionedKey($baseKey, [$scope]);
        Cache::put($oldKey, 'old-list', 3600);

        $versions->bump($scope);

        $newKey = $versions->versionedKey($baseKey, [$scope]);

        $this->assertNotSame($oldKey, $newKey);
        $this->assertSame('old-list', Cache::get($oldKey));
        $this->assertNull(Cache::get($newKey));
    }

    public function test_content_only_update_does_not_bump_list_or_category_scopes(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $before = $this->snapshot([
            'content_hash' => 'old-content',
        ]);
        $after = $this->snapshot([
            'content_hash' => 'new-content',
        ]);

        $scopes = $invalidator->invalidateSnapshots($before, $after);

        $this->assertContains(ContentPostCacheInvalidationService::postScope(10), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::slugScope('old-slug', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::searchScope('post', 1), $scopes);
        $this->assertNotContains(ContentPostCacheInvalidationService::latestScope('post', 1), $scopes);
        $this->assertNotContains(ContentPostCacheInvalidationService::listScope('post', 1), $scopes);
        $this->assertNotContains(ContentPostCacheInvalidationService::categoryScope(5, 'post', 1), $scopes);
    }

    public function test_new_featured_post_bumps_latest_featured_and_category_path_scopes(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $after = $this->snapshot([
            'id' => 33,
            'slugs' => ['new-post'],
            'featured' => true,
            'category_ids' => [7],
            'category_scope_ids' => [2, 5, 7],
        ]);

        $scopes = $invalidator->invalidateSnapshots([], $after);

        $this->assertContains(ContentPostCacheInvalidationService::postScope(33), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::latestScope('post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::listScope('post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::featuredScope('post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(2, 'post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(5, 'post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(7, 'post', 1), $scopes);
    }

    public function test_new_public_post_bumps_featured_category_tree_cache_for_parent_categories(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $scopes = $invalidator->invalidateSnapshots([], $this->snapshot([
            'category_ids' => [7],
            'category_scope_ids' => [2, 5, 7],
        ]));

        $this->assertContains(CategoryCacheInvalidationService::categoryTreeScope(), $scopes);
    }

    public function test_category_change_bumps_old_and_new_category_paths(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $before = $this->snapshot([
            'category_ids' => [7],
            'category_scope_ids' => [2, 5, 7],
        ]);
        $after = $this->snapshot([
            'category_ids' => [8],
            'category_scope_ids' => [2, 6, 8],
        ]);

        $scopes = $invalidator->invalidateSnapshots($before, $after);

        $this->assertContains(ContentPostCacheInvalidationService::latestScope('post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(5, 'post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(7, 'post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(6, 'post', 1), $scopes);
        $this->assertContains(ContentPostCacheInvalidationService::categoryScope(8, 'post', 1), $scopes);
    }

    public function test_type_change_bumps_the_mixed_type_public_list_scope(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $scopes = $invalidator->invalidateSnapshots(
            $this->snapshot(['type' => 'post']),
            $this->snapshot(['type' => 'document'])
        );

        $this->assertContains('content:posts:list:any:lang:1', $scopes);
    }

    public function test_full_page_toggle_invalidates_the_deep_dive_config_list(): void
    {
        $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

        $scopes = $invalidator->invalidateSnapshots(
            $this->snapshot(['full_page' => false]),
            $this->snapshot(['full_page' => true]),
        );

        $this->assertContains(ContentPostCacheInvalidationService::configScope('post', 'is_full_page', 1), $scopes);
        $this->assertNotContains(ContentPostCacheInvalidationService::listScope('post', 1), $scopes);

        $cardScopes = $invalidator->invalidateSnapshots(
            $this->snapshot(['full_page' => true, 'card_hash' => 'old-card']),
            $this->snapshot(['full_page' => true, 'card_hash' => 'new-card']),
        );
        $this->assertContains(ContentPostCacheInvalidationService::configScope('post', 'is_full_page', 1), $cardScopes);
    }

    public function test_legacy_redis_detail_keys_are_deleted_for_old_and_new_slugs(): void
    {
        $previousRedisEnabled = config('database.redis.enable');
        config(['database.redis.enable' => true]);

        $redisConnection = new class {
            public array $deleted = [];

            public function del(string $key): void
            {
                $this->deleted[] = $key;
            }
        };

        Redis::shouldReceive('connection')->andReturn($redisConnection);

        try {
            $invalidator = new ContentPostCacheInvalidationService(new ContentCacheVersionService());

            $invalidator->invalidateSnapshots(
                $this->snapshot(['slugs' => ['old-slug']]),
                $this->snapshot([
                    'slugs' => ['new-slug'],
                    'content_hash' => 'new-content',
                    'search_hash' => 'new-search',
                ])
            );

            $this->assertEqualsCanonicalizing([
                'cache:post:slug:old-slug',
                'cache:about:old-slug',
                'cache:post:slug:new-slug',
                'cache:about:new-slug',
            ], $redisConnection->deleted);
        } finally {
            config(['database.redis.enable' => $previousRedisEnabled]);
        }
    }

    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'id' => 10,
            'type' => 'post',
            'language_id' => 1,
            'public' => true,
            'status' => 'published',
            'published_at' => '2026-06-20 10:00:00',
            'deleted_at' => null,
            'slugs' => ['old-slug'],
            'category_ids' => [5],
            'category_scope_ids' => [2, 5],
            'featured' => false,
            'content_hash' => 'same-content',
            'card_hash' => 'same-card',
            'metadata_hash' => 'same-metadata',
        ], $overrides);
    }
}
