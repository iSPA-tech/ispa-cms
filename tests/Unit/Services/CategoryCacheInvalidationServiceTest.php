<?php

namespace Tests\Unit\Services;

use Cms\Core\Services\Cache\CacheVersionService;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryCacheInvalidationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_category_mutation_bumps_tree_children_slug_and_content_category_scopes(): void
    {
        $invalidator = new CategoryCacheInvalidationService(new CacheVersionService());

        $before = [
            'id' => 5,
            'type' => 0,
            'language_id' => 1,
            'public' => true,
            'status' => 'published',
            'parent_id' => 2,
            'slugs' => ['old-category'],
            'category_scope_ids' => [2, 5, 7],
        ];
        $after = [
            'id' => 5,
            'type' => 0,
            'language_id' => 1,
            'public' => true,
            'status' => 'published',
            'parent_id' => 3,
            'slugs' => ['new-category'],
            'category_scope_ids' => [3, 5, 8],
        ];

        $scopes = $invalidator->invalidateSnapshots($before, $after);

        $this->assertContains(CategoryCacheInvalidationService::categoryTreeScope(), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::categoryChildrenScope(2), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::categoryChildrenScope(3), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::categorySlugScope('old-category'), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::categorySlugScope('new-category'), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::contentPostCategoryScope(7, 'post', 1), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::contentPostCategoryScope(8, 'post', 1), $scopes);
        $this->assertContains(CategoryCacheInvalidationService::contentPostCategoryScope(8, 'post'), $scopes);
    }

    public function test_category_versioned_key_moves_after_scope_bump(): void
    {
        $versions = new CacheVersionService();
        $scope = CategoryCacheInvalidationService::categoryTreeScope();
        $baseKey = 'cache:category:tree:old-category';

        $oldKey = $versions->versionedKey($baseKey, [$scope]);
        $versions->bump($scope);

        $this->assertNotSame($oldKey, $versions->versionedKey($baseKey, [$scope]));
    }

    public function test_cache_versions_are_memoized_for_the_service_lifetime(): void
    {
        $versions = new CacheVersionService();
        $scope = CategoryCacheInvalidationService::categoryTreeScope();
        $baseKey = 'cache:category:tree:memo';

        $firstKey = $versions->versionedKey($baseKey, [$scope]);
        Cache::forever('cms:cache-version:' . sha1($scope), 99);

        $this->assertSame($firstKey, $versions->versionedKey($baseKey, [$scope]));
        $this->assertNotSame($firstKey, (new CacheVersionService())->versionedKey($baseKey, [$scope]));
    }
}
