<?php

namespace Cms\Content\Services\Cache;

use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Cms\Core\Models\TagData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Caches public tag listings.
 *
 * @api Content public API; signature changes follow the module notes.
 */
class ContentPublicTagCacheService
{
    private const TTL_SECONDS = 3600;

    public function __construct(private readonly ContentCacheVersionService $versions)
    {
    }

    public function rememberIndex(callable $loader): mixed
    {
        return Cache::remember($this->indexKey(), self::TTL_SECONDS, $loader);
    }

    public function rememberTag(string $slug, callable $loader): mixed
    {
        $cached = Cache::remember(
            $this->tagKey($slug),
            self::TTL_SECONDS,
            static fn (): array => ['value' => $loader()],
        );

        return is_array($cached) ? ($cached['value'] ?? null) : null;
    }

    public function rememberPosts(int $tagId, int $page, int $pageSize): LengthAwarePaginatorContract
    {
        $snapshot = Cache::remember(
            $this->postsKey($tagId, $page, $pageSize),
            self::TTL_SECONDS,
            static function () use ($tagId, $page, $pageSize): array {
                $paginator = self::publishedPosts($tagId, $page, $pageSize);
                return [
                    'items' => $paginator->items(),
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'options' => method_exists($paginator, 'getOptions') ? $paginator->getOptions() : [],
                ];
            },
        );

        $query = request()->query();
        unset($query['page']);
        $options = array_replace((array) ($snapshot['options'] ?? []), [
            'path' => request()->url(),
            'query' => $query,
        ]);

        return new LengthAwarePaginator(
            $snapshot['items'] ?? [],
            max(0, (int) ($snapshot['total'] ?? 0)),
            max(1, (int) ($snapshot['per_page'] ?? $pageSize)),
            max(1, (int) ($snapshot['current_page'] ?? $page)),
            $options,
        );
    }

    public function indexKey(): string
    {
        return $this->versions->versionedKey('content:public:v3:tags:index:v2', self::indexScope());
    }

    public function invalidateTags(array $slugs): void
    {
        $scopes = [self::indexScope()];
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '') {
                $scopes[] = self::tagScope($slug);
            }
        }

        $this->versions->bumpMany($scopes);
    }

    public function invalidatePosts(array $tagIds): void
    {
        $scopes = [];
        foreach ($tagIds as $tagId) {
            $tagId = (int) $tagId;
            if ($tagId > 0) {
                $scopes[] = self::postsScope($tagId);
            }
        }

        if ($scopes !== []) {
            $this->versions->bumpMany($scopes);
        }
    }

    private function tagKey(string $slug): string
    {
        $slug = trim($slug);

        return $this->versions->versionedKey(
            'content:public:v3:tags:detail:v2:' . sha1($slug),
            [self::indexScope(), self::tagScope($slug)],
        );
    }

    private function postsKey(int $tagId, int $page, int $pageSize): string
    {
        $languageId = lang('id');

        return $this->versions->versionedKey(
            sprintf('content:public:v3:tags:posts:v2:%d:%d:%d', $tagId, $page, $pageSize),
            [
                self::postsScope($tagId),
                ContentPostCacheInvalidationService::listScope('post', $languageId),
                ContentPostCacheInvalidationService::latestScope('post', $languageId),
            ],
        );
    }

    private static function publishedPosts(int $tagId, int $page, int $pageSize): LengthAwarePaginatorContract
    {
        return TagData::query()
            ->where(['target_type' => Post::class, 'tag_id' => $tagId])
            ->with([
                'target' => static function ($query): mixed {
                    $query->where([
                        'type' => 'post',
                        'status' => StatusEnum::PUBLISHED->value,
                        'language_id' => lang('id'),
                    ])->where(static function ($published): void {
                        $published->whereNull('published_at')->orWhere('published_at', '<=', now());
                    });

                    return $query->select([
                        'id', 'uuid', 'name', 'description', 'type', 'status', 'category_id', 'video_url', 'published_at', 'config',
                    ])->with([
                        'images',
                        'slugs:target_id,slug',
                        'seo',
                        'category' => static fn ($category) => $category->select('id', 'name')->withChildrenExists(),
                    ]);
                },
            ])
            ->latest()
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    private static function indexScope(): string
    {
        return 'content:public:tags:index';
    }

    private static function tagScope(string $slug): string
    {
        return 'content:public:tags:detail:' . sha1($slug);
    }

    private static function postsScope(int $tagId): string
    {
        return 'content:public:tags:posts:' . $tagId;
    }
}
