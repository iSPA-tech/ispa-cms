<?php

namespace Cms\Content\Services\Cache;

use App\Constants\CacheConstants;
use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\Category;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Invalidates the public post caches.
 *
 * @api Content public API; signature changes follow the module notes.
 */
class ContentPostCacheInvalidationService
{
    public function __construct(private readonly ContentCacheVersionService $versions)
    {
    }

    public static function postScope(int|string $postId): string
    {
        return 'content:post:' . $postId;
    }

    public static function uuidScope(string $uuid): string
    {
        return 'content:post-uuid:' . $uuid;
    }

    public static function slugScope(string $slug, int|string|null $languageId): string
    {
        return sprintf('content:post-slug:%s:%s', self::languageSegment($languageId), $slug);
    }

    public static function listScope(string $type, int|string|null $languageId): string
    {
        return sprintf('content:posts:list:%s:%s', self::typeSegment($type), self::languageSegment($languageId));
    }

    public static function publicListScope(int|string|null $languageId): string
    {
        return sprintf('content:posts:list:any:%s', self::languageSegment($languageId));
    }

    public static function latestScope(string $type, int|string|null $languageId): string
    {
        return sprintf('content:posts:latest:%s:%s', self::typeSegment($type), self::languageSegment($languageId));
    }

    public static function featuredScope(string $type, int|string|null $languageId): string
    {
        return sprintf('content:posts:featured:%s:%s', self::typeSegment($type), self::languageSegment($languageId));
    }

    public static function configScope(string $type, string $configKey, int|string|null $languageId): string
    {
        return sprintf('content:posts:config:%s:%s:%s', self::typeSegment($type), $configKey, self::languageSegment($languageId));
    }

    public static function searchScope(string $type, int|string|null $languageId): string
    {
        return sprintf('content:posts:search:%s:%s', self::typeSegment($type), self::languageSegment($languageId));
    }

    public static function categoryScope(int|string $categoryId, string $type, int|string|null $languageId): string
    {
        return sprintf('content:posts:category:%s:%s:%s', $categoryId, self::typeSegment($type), self::languageSegment($languageId));
    }

    /**
     * @return array<int, string>
     */
    public function invalidateSnapshots(array $before, array $after): array
    {
        if ($before === [] && $after === []) {
            return [];
        }

        $beforePublic = $this->isPublicSnapshot($before);
        $afterPublic = $this->isPublicSnapshot($after);

        if (!$beforePublic && !$afterPublic) {
            return [];
        }

        $scopes = [];

        foreach ($this->postIds($before, $after) as $postId) {
            $scopes[] = self::postScope($postId);
        }

        foreach ($this->uuidScopes($before, $after) as $scope) {
            $scopes[] = $scope;
        }

        foreach ($this->slugScopes($before, $after) as $scope) {
            $scopes[] = $scope;
        }

        $membershipChanged = $this->membershipHash($before) !== $this->membershipHash($after);
        $cardChanged = ($before['card_hash'] ?? null) !== ($after['card_hash'] ?? null);
        $contentChanged = ($before['content_hash'] ?? null) !== ($after['content_hash'] ?? null);
        $fullPageChanged = (bool) ($before['full_page'] ?? false) !== (bool) ($after['full_page'] ?? false);
        $searchChanged = $contentChanged
            || (($before['search_hash'] ?? null) !== ($after['search_hash'] ?? null))
            || $membershipChanged
            || $beforePublic !== $afterPublic;

        if ($searchChanged) {
            foreach ($this->typeLanguagePairs($before, $after) as [$type, $languageId]) {
                foreach ($this->languageVariants($languageId) as $scopeLanguageId) {
                    $scopes[] = self::searchScope($type, $scopeLanguageId);
                }
            }
        }

        if ($membershipChanged || $cardChanged || $beforePublic !== $afterPublic) {
            foreach ($this->typeLanguagePairs($before, $after) as [$type, $languageId]) {
                foreach ($this->languageVariants($languageId) as $scopeLanguageId) {
                    $scopes[] = self::publicListScope($scopeLanguageId);
                    $scopes[] = self::listScope($type, $scopeLanguageId);
                    $scopes[] = self::latestScope($type, $scopeLanguageId);

                    foreach ($this->categoryScopeIds($before, $after) as $categoryId) {
                        $scopes[] = self::categoryScope($categoryId, $type, $scopeLanguageId);
                    }

                    if ($type === 'post' && $this->categoryScopeIds($before, $after) !== []) {
                        $scopes[] = CategoryCacheInvalidationService::categoryTreeScope();
                    }

                    if ($this->touchesFeatured($before, $after)) {
                        $scopes[] = self::featuredScope($type, $scopeLanguageId);
                        $scopes[] = self::configScope($type, 'featured', $scopeLanguageId);
                    }
                    if (($before['full_page'] ?? false) || ($after['full_page'] ?? false)) {
                        $scopes[] = self::configScope($type, 'is_full_page', $scopeLanguageId);
                    }
                }
            }
        }

        if ($fullPageChanged) {
            foreach ($this->typeLanguagePairs($before, $after) as [$type, $languageId]) {
                foreach ($this->languageVariants($languageId) as $scopeLanguageId) {
                    $scopes[] = self::configScope($type, 'is_full_page', $scopeLanguageId);
                }
            }
        }

        $scopes = $this->uniqueScopes($scopes);
        $this->versions->bumpMany($scopes);
        $this->deleteLegacyRedisDetailKeys($before, $after);
        $this->forgetSlugDetailKeys($before, $after);

        return $scopes;
    }

    public function versionedKey(string $baseKey, array|string $scopes): string
    {
        return $this->versions->versionedKey($baseKey, $scopes);
    }

    public function snapshot(?Post $post): array
    {
        if (!$post || !$post->exists) {
            return [];
        }

        $post->loadMissing([
            'slugs:id,slug,target_id,target_type,language_id',
            'categories:id,parent_id,language_id,type,status',
            'images:id',
        ]);

        $slugs = $post->slugs
            ->pluck('slug')
            ->filter()
            ->map(static fn ($slug): string => (string) $slug)
            ->unique()
            ->values()
            ->all();

        $categoryIds = $post->categories
            ->pluck('id')
            ->push($post->category_id)
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $categoryScopeIds = $this->expandCategoryIdsWithAncestors($categoryIds);
        $imageSignature = $post->images
            ->map(static function ($image): string {
                return implode(':', [
                    (int) $image->getKey(),
                    (int) ($image->pivot->main ?? 0),
                    (int) ($image->pivot->order ?? 0),
                ]);
            })
            ->values()
            ->all();

        $config = is_array($post->config) ? $post->config : [];
        $publishedAt = $this->dateString($post->published_at);
        $deletedAt = $this->dateString($post->deleted_at);
        $status = (string) $post->status;

        return [
            'id' => (int) $post->getKey(),
            'uuid' => (string) $post->uuid,
            'type' => (string) ($post->type ?: 'post'),
            'language_id' => $post->language_id ? (int) $post->language_id : null,
            'public' => $this->isPublicState($status, $publishedAt, $deletedAt),
            'status' => $status,
            'published_at' => $publishedAt,
            'deleted_at' => $deletedAt,
            'slugs' => $slugs,
            'category_ids' => $categoryIds,
            'category_scope_ids' => $categoryScopeIds,
            'featured' => (bool) ($config['featured'] ?? false),
            'full_page' => ($config['is_full_page'] ?? false) === true,
            'content_hash' => $this->hashPayload([$post->content]),
            'card_hash' => $this->hashPayload([
                $post->name,
                $post->description,
                $post->video_url,
                $post->category_id,
                $imageSignature,
            ]),
            'search_hash' => $this->hashPayload([
                $post->name,
                $post->description,
                $post->hashtag,
                $post->content,
            ]),
            'metadata_hash' => $this->hashPayload([
                $status,
                $publishedAt,
                $deletedAt,
                $post->type,
                $post->language_id,
                $categoryIds,
                $categoryScopeIds,
                $slugs,
                $config,
            ]),
        ];
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    private function expandCategoryIdsWithAncestors(array $categoryIds): array
    {
        $all = collect($categoryIds)
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $current = $all;

        while ($current->isNotEmpty()) {
            $parents = Category::query()
                ->whereIn('id', $current->all())
                ->pluck('parent_id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->diff($all)
                ->unique()
                ->values();

            if ($parents->isEmpty()) {
                break;
            }

            $all = $all->merge($parents)->unique()->values();
            $current = $parents;
        }

        return $all->sort()->values()->all();
    }

    private function isPublicSnapshot(array $snapshot): bool
    {
        return (bool) ($snapshot['public'] ?? false);
    }

    private function isPublicState(?string $status, mixed $publishedAt, mixed $deletedAt): bool
    {
        if ($deletedAt !== null) {
            return false;
        }

        if ($status !== StatusEnum::PUBLISHED->value) {
            return false;
        }

        if ($publishedAt === null || $publishedAt === '') {
            return true;
        }

        return Carbon::parse($publishedAt)->lte(now());
    }

    private function membershipHash(array $snapshot): string
    {
        if ($snapshot === []) {
            return '';
        }

        return $this->hashPayload([
            $this->isPublicSnapshot($snapshot),
            $snapshot['type'] ?? null,
            $snapshot['language_id'] ?? null,
            $snapshot['status'] ?? null,
            $snapshot['published_at'] ?? null,
            $snapshot['deleted_at'] ?? null,
            $snapshot['category_ids'] ?? [],
            $snapshot['category_scope_ids'] ?? [],
            (bool) ($snapshot['featured'] ?? false),
        ]);
    }

    /**
     * @return array<int, int|string>
     */
    private function postIds(array ...$snapshots): array
    {
        return $this->uniqueValues(array_map(static fn (array $snapshot) => $snapshot['id'] ?? null, $snapshots));
    }

    /**
     * @return array<int, string>
     */
    private function slugScopes(array ...$snapshots): array
    {
        $scopes = [];

        foreach ($snapshots as $snapshot) {
            $languageId = $snapshot['language_id'] ?? null;
            foreach ($snapshot['slugs'] ?? [] as $slug) {
                if ($slug !== null && $slug !== '') {
                    $scopes[] = self::slugScope((string) $slug, $languageId);
                }
            }
        }

        return $this->uniqueScopes($scopes);
    }

    private function deleteLegacyRedisDetailKeys(array ...$snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['slugs'] ?? [] as $slug) {
                if ($slug === null || $slug === '') {
                    continue;
                }

                try {
                    RedisHelper::getInstance()->del(sprintf(CacheConstants::CACHE_POST_BY_SLUG, $slug));
                    RedisHelper::getInstance()->del(sprintf(CacheConstants::CACHE_ABOUT_BY_SLUG, $slug));
                } catch (Throwable) {
                    // Legacy Redis cache is best-effort. Versioned Laravel cache is authoritative.
                }
            }
        }
    }

    /**
     * Forget the UNVERSIONED slug-detail cache (CacheKeyHelper::slugDetail),
     * written by the dynamic-page lookup in the project HomeControllers
     * (resolveDynamicPage -> CMSCache::rememberRandom, random 1-2h TTL). It is
     * keyed per language and is not covered by the versioned scopes above, so a
     * page (posts type=page) edited in admin would otherwise keep serving the
     * stale page content until that TTL expired. Forget it for every affected
     * slug under the post's language and the current request language.
     */
    private function forgetSlugDetailKeys(array ...$snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $languageId = $snapshot['language_id'] ?? null;

            foreach ($snapshot['slugs'] ?? [] as $slug) {
                if ($slug === null || $slug === '') {
                    continue;
                }

                foreach ($this->slugDetailLanguageIds($languageId) as $langId) {
                    try {
                        CMSCache::forget(CacheKeyHelper::slugDetail((string) $slug, $langId));
                    } catch (Throwable) {
                        // Best-effort; versioned scopes remain authoritative.
                    }
                }
            }
        }
    }

    /**
     * @return array<int, int|string>
     */
    private function slugDetailLanguageIds(int|string|null $languageId): array
    {
        $ids = [];

        if ($languageId !== null && $languageId !== '') {
            $ids[] = (int) $languageId;
        }

        $ids[] = CacheKeyHelper::getLangId();

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, string>
     */
    private function uuidScopes(array ...$snapshots): array
    {
        $scopes = [];

        foreach ($snapshots as $snapshot) {
            if (!empty($snapshot['uuid'])) {
                $scopes[] = self::uuidScope((string) $snapshot['uuid']);
            }
        }

        return $this->uniqueScopes($scopes);
    }

    /**
     * @return array<int, array{0: string, 1: int|string|null}>
     */
    private function typeLanguagePairs(array ...$snapshots): array
    {
        $pairs = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot === [] || !$this->isPublicSnapshot($snapshot)) {
                continue;
            }

            $pairs[] = [
                (string) ($snapshot['type'] ?? 'post'),
                $snapshot['language_id'] ?? null,
            ];
        }

        $seen = [];

        return array_values(array_filter($pairs, static function (array $pair) use (&$seen): bool {
            $key = $pair[0] . ':' . (string) $pair[1];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    /**
     * @return array<int, int>
     */
    private function categoryScopeIds(array ...$snapshots): array
    {
        $ids = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['category_scope_ids'] ?? $snapshot['category_ids'] ?? [] as $id) {
                if ($id !== null && $id !== '') {
                    $ids[] = (int) $id;
                }
            }
        }

        return collect($ids)->unique()->sort()->values()->all();
    }

    private function touchesFeatured(array $before, array $after): bool
    {
        return (bool) ($before['featured'] ?? false)
            || (bool) ($after['featured'] ?? false)
            || (($before['featured'] ?? null) !== ($after['featured'] ?? null));
    }

    /**
     * @return array<int, int|string|null>
     */
    private function languageVariants(int|string|null $languageId): array
    {
        return array_values(array_unique([$languageId, null, 0], SORT_STRING));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, mixed>
     */
    private function uniqueValues(array $values): array
    {
        return collect($values)
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->unique()
            ->values()
            ->all();
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

    private function hashPayload(mixed $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return sha1($encoded !== false ? $encoded : serialize($payload));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    private static function typeSegment(string $type): string
    {
        return $type !== '' ? $type : 'post';
    }

    private static function languageSegment(int|string|null $languageId): string
    {
        return $languageId !== null && $languageId !== '' ? 'lang:' . $languageId : 'lang:any';
    }
}
