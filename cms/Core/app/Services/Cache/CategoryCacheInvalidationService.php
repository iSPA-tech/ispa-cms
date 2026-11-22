<?php

namespace Cms\Core\Services\Cache;

use App\Enums\StatusEnum;
use Cms\Core\Models\Category;

class CategoryCacheInvalidationService
{
    public function __construct(private readonly CacheVersionService $versions)
    {
    }

    public static function categoryScope(int|string $categoryId): string
    {
        return 'core:category:' . $categoryId;
    }

    public static function categorySlugScope(string $slug): string
    {
        return 'core:category-slug:' . $slug;
    }

    public static function categoryListScope(int|string|null $type = null): string
    {
        return 'core:categories:list:type:' . ($type ?? 'any');
    }

    public static function categoryTreeScope(): string
    {
        return 'core:categories:tree';
    }

    public static function categoryChildrenScope(int|string $categoryId): string
    {
        return 'core:category-children:' . $categoryId;
    }

    public static function contentPostCategoryScope(int|string $categoryId, string $type = 'post', int|string|null $languageId = null): string
    {
        $language = $languageId !== null && $languageId !== '' ? 'lang:' . $languageId : 'lang:any';

        return sprintf('content:posts:category:%s:%s:%s', $categoryId, $type, $language);
    }

    public function versionedKey(string $baseKey, array|string $scopes): string
    {
        return $this->versions->versionedKey($baseKey, $scopes);
    }

    /**
     * @return array<int, string>
     */
    public function invalidateSnapshots(array $before, array $after): array
    {
        if ($before === [] && $after === []) {
            return [];
        }

        $scopes = [
            self::categoryTreeScope(),
        ];

        foreach ($this->categoryIds($before, $after) as $categoryId) {
            $scopes[] = self::categoryScope($categoryId);
            $scopes[] = self::categoryChildrenScope($categoryId);
        }

        foreach ($this->slugs($before, $after) as $slug) {
            $scopes[] = self::categorySlugScope($slug);
        }

        foreach ($this->types($before, $after) as $type) {
            $scopes[] = self::categoryListScope($type);
        }
        $scopes[] = self::categoryListScope();

        foreach ($this->contentPostScopeIds($before, $after) as $categoryId) {
            foreach ($this->languages($before, $after) as $languageId) {
                $scopes[] = self::contentPostCategoryScope($categoryId, 'post', $languageId);
            }
        }

        $scopes = $this->uniqueScopes($scopes);
        $this->versions->bumpMany($scopes);

        return $scopes;
    }

    public function snapshot(?Category $category): array
    {
        if (!$category || !$category->exists) {
            return [];
        }

        $category->loadMissing([
            'slugs:id,slug,target_id,target_type,language_id',
        ]);

        $slugs = $category->slugs
            ->pluck('slug')
            ->filter()
            ->map(static fn ($slug): string => (string) $slug)
            ->unique()
            ->values()
            ->all();

        $categoryIds = $this->expandCategoryIdsWithAncestorsAndChildren([(int) $category->getKey()]);

        return [
            'id' => (int) $category->getKey(),
            'type' => $category->type,
            'language_id' => $category->language_id ? (int) $category->language_id : null,
            'public' => $category->status === StatusEnum::PUBLISHED->value && $category->deleted_at === null,
            'status' => (string) $category->status,
            'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            'slugs' => $slugs,
            'category_scope_ids' => $categoryIds,
        ];
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    private function expandCategoryIdsWithAncestorsAndChildren(array $categoryIds): array
    {
        $all = collect($categoryIds)
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $current = $all;
        while ($current->isNotEmpty()) {
            $parents = Category::query()
                ->withTrashed()
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

        $current = $all;
        while ($current->isNotEmpty()) {
            $children = Category::query()
                ->withTrashed()
                ->whereIn('parent_id', $current->all())
                ->pluck('id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->diff($all)
                ->unique()
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $all = $all->merge($children)->unique()->values();
            $current = $children;
        }

        return $all->sort()->values()->all();
    }

    /**
     * @return array<int, int>
     */
    private function categoryIds(array ...$snapshots): array
    {
        $ids = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['category_scope_ids'] ?? [] as $id) {
                $ids[] = (int) $id;
            }

            if (!empty($snapshot['id'])) {
                $ids[] = (int) $snapshot['id'];
            }

            if (!empty($snapshot['parent_id'])) {
                $ids[] = (int) $snapshot['parent_id'];
            }
        }

        return collect($ids)->unique()->sort()->values()->all();
    }

    /**
     * @return array<int, int>
     */
    private function contentPostScopeIds(array ...$snapshots): array
    {
        return $this->categoryIds(...$snapshots);
    }

    /**
     * @return array<int, string>
     */
    private function slugs(array ...$snapshots): array
    {
        return collect($snapshots)
            ->flatMap(static fn (array $snapshot): array => $snapshot['slugs'] ?? [])
            ->filter()
            ->map(static fn ($slug): string => (string) $slug)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int|string|null>
     */
    private function types(array ...$snapshots): array
    {
        return collect($snapshots)
            ->pluck('type')
            ->filter(static fn ($type): bool => $type !== null && $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int|string|null>
     */
    private function languages(array ...$snapshots): array
    {
        return collect($snapshots)
            ->pluck('language_id')
            ->push(null)
            ->push(0)
            ->uniqueStrict()
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
}
