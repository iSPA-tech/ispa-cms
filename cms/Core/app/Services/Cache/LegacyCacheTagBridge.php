<?php

namespace Cms\Core\Services\Cache;

use App\Constants\Constant;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;

class LegacyCacheTagBridge
{
    /** @var array<int, string> */
    private const POST_TYPES = [
        'post',
        'audio',
        'video',
        'document',
        'notification',
        'admission',
        'event',
        'events',
        'page',
    ];

    /** @var array<int, int|string|null> */
    private const LANGUAGE_SCOPE_VARIANTS = [null, 0, 1, 2];

    /**
     * Bridge removed tag tracking to the new versioned cache namespaces.
     *
     * @return array<int, string>
     */
    public function invalidate(string ...$tags): array
    {
        $tags = $this->normalizeTags($tags);

        if ($tags === []) {
            return [];
        }

        $contentScopes = [];
        $categoryScopes = [];

        foreach ($tags as $tag) {
            if ($this->isPostTag($tag)) {
                foreach ($this->postTypesForTag($tag) as $type) {
                    foreach (self::LANGUAGE_SCOPE_VARIANTS as $languageId) {
                        $contentScopes[] = ContentPostCacheInvalidationService::listScope($type, $languageId);
                        $contentScopes[] = ContentPostCacheInvalidationService::latestScope($type, $languageId);
                        $contentScopes[] = ContentPostCacheInvalidationService::searchScope($type, $languageId);
                        $contentScopes[] = ContentPostCacheInvalidationService::featuredScope($type, $languageId);
                        $contentScopes[] = ContentPostCacheInvalidationService::configScope($type, 'featured', $languageId);
                    }
                }
            }

            if ($this->isCategoryTag($tag)) {
                $categoryScopes[] = CategoryCacheInvalidationService::categoryTreeScope();
                $categoryScopes[] = CategoryCacheInvalidationService::categoryListScope();

                foreach ([0, 1, 2] as $type) {
                    $categoryScopes[] = CategoryCacheInvalidationService::categoryListScope($type);
                }
            }
        }

        $contentScopes = $this->uniqueScopes($contentScopes);
        $categoryScopes = $this->uniqueScopes($categoryScopes);

        if ($contentScopes !== []) {
            app(ContentCacheVersionService::class)->bumpMany($contentScopes);
        }

        if ($categoryScopes !== []) {
            app(CacheVersionService::class)->bumpMany($categoryScopes);
        }

        return $this->uniqueScopes([...$contentScopes, ...$categoryScopes]);
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    private function normalizeTags(array $tags): array
    {
        return $this->uniqueScopes(array_map(
            static fn ($tag): string => trim(strtolower((string) $tag)),
            $tags
        ));
    }

    private function isPostTag(string $tag): bool
    {
        return $tag === 'post'
            || $tag === Constant::CACHE_BY_POSTS
            || in_array($tag, [
                Constant::NEW_BY_LIMIT,
                Constant::LATEST_WITH_AUDIO,
                Constant::LATEST_WITH_VIDEO,
                Constant::LATEST_WITH_DOCUMENT,
                Constant::LATEST_WITH_NOTIFICATION,
                Constant::LATEST_WITH_POST,
                Constant::LATEST_WITH_ADMISSION,
                Constant::LATEST_WITH_EVENT,
                Constant::LATEST_WITH_PAGE,
                Constant::RELATED_POSTS,
                Constant::SEARCH_BY_TYPE,
                Constant::POSTS_BY_CATEGORIES_IDS,
                Constant::SEARCH,
                Constant::POSTS_BY_REQUIRED_LOGIN,
                Constant::ADVANCED_SEARCH,
            ], true);
    }

    private function isCategoryTag(string $tag): bool
    {
        return $tag === 'category'
            || $tag === 'categories'
            || $tag === Constant::CACHE_BY_CATEGORIES
            || in_array($tag, [
                Constant::CATEGORY_DETAIL_BY_ID,
                Constant::ALL_WITH_POST,
                Constant::ALL_WITH_VIDEO,
                Constant::ALL_WITH_AUDIO,
                Constant::ALL_WITH_DOCUMENT,
                Constant::AlL_TREE_BY_SLUG,
                Constant::FEATURED_CATEGORY,
            ], true);
    }

    /**
     * @return array<int, string>
     */
    private function postTypesForTag(string $tag): array
    {
        return match ($tag) {
            Constant::LATEST_WITH_AUDIO => ['audio'],
            Constant::LATEST_WITH_VIDEO => ['video'],
            Constant::LATEST_WITH_DOCUMENT => ['document'],
            Constant::LATEST_WITH_NOTIFICATION => ['notification'],
            Constant::LATEST_WITH_ADMISSION => ['admission'],
            Constant::LATEST_WITH_EVENT => ['event', 'events'],
            Constant::LATEST_WITH_PAGE => ['page'],
            Constant::LATEST_WITH_POST, Constant::NEW_BY_LIMIT, 'post' => ['post'],
            default => self::POST_TYPES,
        };
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function uniqueScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes)));
        sort($scopes, SORT_NATURAL);

        return $scopes;
    }
}
