<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V3\Public;

use Cms\Core\Responses\AppResponse;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;

use JsonException;
use App\Enums\StatusEnum;
use App\Constants\CacheConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Cms\Core\Helpers\RedisHelper;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Http\Requests\V3\GetLatestPostsRequest;
use Cms\Content\Http\Requests\V3\GetFeaturedPostsRequest;
use Cms\Content\Http\Requests\V3\Post\GetRecommendedPostRequest;
use Throwable;

class PostController extends Controller
{
    use \App\Http\Controllers\V3\Public\Concerns\DecoratesPostPreviewImages;
    use \App\Http\Controllers\V3\Public\Concerns\SerializesPublicPayloadOnce;

    protected const PUBLIC_LIST_IMAGE_APPENDS = [
        'thumbnail_sm',
        'cover_sm',
        'sm',
        'cover_md',
        'cover_lg',
        'cover_xl',
    ];

    /**
     * Everything a list card may see on an image.
     *
     * setAppends() trims the generated URLs but leaves every real column, and
     * those columns were 83% of this endpoint's 393 KB response: `config`
     * alone is 1.4 KB per row of post-level settings that have nothing to do
     * with rendering a thumbnail, next to `custom`, EXIF, storage internals and
     * a row of nulls. the public website reads only the variant URLs, the id, the name,
     * the alt text and the dimensions.
     */
    protected const PUBLIC_LIST_IMAGE_VISIBLE = [
        'id',
        'name',
        'alt_text',
        'dimensions',
        ...self::PUBLIC_LIST_IMAGE_APPENDS,
    ];

    /**
     * Post controller constructor
     *
     * @param PostInterface $post
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 16:22 ICT
     */
    public function __construct(
        protected PostInterface $post,
    )
    {
        parent::__construct();
    }

    /**
     * Recommended posts by limit
     *
     * @param GetRecommendedPostRequest $request
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-09-08, 11:00 ICT
     * @author malayvuong
     */
    public function recommendedPosts(GetRecommendedPostRequest $request)
    {
        $posts = $this->normalizePostListResult(
            $this->post->getPostByTypeAndConfig('post', 'recommended', $request->input('limit', 4))
        );

        //  Set or get cover param to image in every post
        $posts = $posts->map(function ($post): mixed {
            return $this->attachCoverImage($post);
        });

        //  No Redis write: this wrote recommended posts under
        //  CACHE_LATEST_POSTS, the key the public website reads for `post/latest`. Given
        //  the same `limit` on both endpoints, a latest-posts read would have
        //  been answered with recommended rows.
        return ires()->data($posts);
    }

    /**
     * Get all post with category slug
     *
     * @param Request $request
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-05-04, 10:03 ICT
     * @author malayvuong
     */
    public function getAll(Request $request): mixed
    {
        $limit = (int)$request->input('pageSize', $request->input('limit', 100));
        $page = (int)$request->input('page', 1);

        // Align API contract: frontend uses `pageSize`, while repository paging
        // is driven by limit-style value. Normalize to integer and fallback safely.
        $limit = min(100, max(1, $limit));
        $page = max(1, $page);

        $keyword = trim((string)$request->input('keyword', ''));
        $category = $request->input('category');
        $tag = trim((string)$request->input('tag', ''));
        $pageSize = $limit;

        $categoryIds = [];
        $rawCategoryIds = $request->input('category_ids');

        if (!empty($rawCategoryIds)) {
            if (is_array($rawCategoryIds)) {
                $categoryIds = array_values(array_filter(array_map('intval', $rawCategoryIds), fn($id) => $id > 0));
            } elseif (is_string($rawCategoryIds)) {
                $categoryIds = array_values(
                    array_filter(
                        array_map('intval', preg_split('/,/', $rawCategoryIds)),
                        fn($id) => $id > 0
                    )
                );
            }
        }

        if ($keyword !== '' || $tag !== '' || !empty($category) || !empty($categoryIds)) {
            $filter = [
                'type' => $request->input('type', 'post'),
                'page' => $page,
                'pageSize' => $pageSize,
            ];

            if ($keyword !== '') {
                $filter['keyword'] = $keyword;
            }

            if (!empty($categoryIds)) {
                $filter['category_ids'] = $categoryIds;
            } elseif (is_numeric((string)$category)) {
                $filter['category'] = (int)$category;
            }

            if ($tag !== '') {
                $filter['tag'] = $tag;
            }

            $post = $this->post->advancedSearch($filter);
            $post = $this->decoratePostPreviewImages($post);

            return ires()->data($post);
        }

        $post = $this->post->get([
            'select' => ['id', 'name', 'description', 'created_at', 'updated_at', 'status', 'category_id', 'published_at', 'video_url'],
            'where' => [
                'status' => StatusEnum::PUBLISHED->value,
                function ($q): mixed {
                    return $q->where('published_at', '<=', now())
                        ->orWhereNull('published_at');
                },
            ],
            'with' => [
                //  The card prints the category, and `has_children` is
                //  appended, so resolve it here or the accessor runs one EXISTS
                //  per post at serialization time.
                'category' => fn($q) => $q->select('id', 'name')
                    ->withChildrenExists()
                    ->with('slugs:slug,target_id'),
                //  No `slugs`: the slug accessor reads the projected alias and
                //  falls back to `latestSlug`, never the full collection, so
                //  eager loading it bought one extra query and 8 KB of
                //  duplicate slug rows in the response.
                'latestSlug',
                //  No `images` either: withImage() below loads the singular
                //  relation, so preparePostListImage() always takes that branch
                //  and unsets the collection before serializing. Loading it was
                //  one query and a full Upload hydration per row, discarded.
            ],
            'has' => ['categories'],
            //  Batch the R2 ledger probe behind the appended `image`: the
            //  accessor asks the ledger whether each preset derivative was
            //  replicated, and that was 172 of this endpoint's 182 production
            //  queries.
            //
            //  No withTotalView() here — preparePostListImage() narrows the
            //  appends to slug, has_video, published_date and image, so
            //  `total_view` never reaches the response and its batched SUM over
            //  `tracks` would be a query paid for and thrown away.
            'scopes' => ['withImage'],
            'page' => $limit,
            'latest' => true,
        ]);

        $post = $this->preparePostListPayload($post);

        //  No Redis write here on purpose: `cache:post:all:limit=N:page=M` is a
        //  key shape nothing reads. the public website builds its own
        //  `cache:post:all:page=N&pageSize=M` from the query string and fills it
        //  after its own fetch. The write was not free either — RedisHelper
        //  json_encodes the model collection, and that re-fires every appended
        //  accessor, which measured as roughly half of this endpoint's queries.
        return ires()->data($post);
    }

    /**
     * Keep post/all image payload lightweight for large public lists.
     *
     * The full Upload model appends many generated crop URLs on serialization,
     * but public list cards must receive generated variants instead of original
     * image URLs to keep website image payloads small.
     *
     * @param mixed $payload
     *
     * @return mixed
     */
    protected function preparePostListPayload(mixed $payload): mixed
    {
        if (is_object($payload) && method_exists($payload, 'getCollection') && method_exists($payload, 'setCollection')) {
            $payload->setCollection(
                $payload->getCollection()->map(fn($post) => $this->preparePostListImage($post))
            );

            return $payload;
        }

        if (is_iterable($payload)) {
            foreach ($payload as $post) {
                $this->preparePostListImage($post);
            }
        }

        return $payload;
    }

    /**
     * Collapse eager-loaded image relations to one image with list-safe variants.
     *
     * @param mixed $post
     *
     * @return mixed
     */
    protected function preparePostListImage(mixed $post): mixed
    {
        if (!is_object($post) || !method_exists($post, 'relationLoaded')) {
            return $post;
        }

        try {
            $image = null;

            if ($post->relationLoaded('image')) {
                $image = $post->getRelation('image');
            } elseif ($post->relationLoaded('images')) {
                $images = $post->getRelation('images');
                $image = $images?->firstWhere('pivot.main', true) ?? $images?->first();
            } else {
                $image = $post->image ?? null;
            }

            if ($image !== null) {
                $coverUrl = $this->imageVariantUrl($image, 'post.card', 'cover_lg');
                if ($coverUrl !== null) {
                    $image->cover_lg = $coverUrl;
                }
            }

            if ($image !== null && method_exists($image, 'setAppends')) {
                $image->setAppends(self::PUBLIC_LIST_IMAGE_APPENDS);
            }

            //  setAppends() only governs generated attributes. The real
            //  columns ride along until the model is told what a card may
            //  see, and they were the bulk of this endpoint's payload.
            if ($image !== null && method_exists($image, 'setVisible')) {
                $image->setVisible(self::PUBLIC_LIST_IMAGE_VISIBLE);
            }

            if (method_exists($post, 'setAppends')) {
                $post->setAppends(['slug', 'has_video', 'published_date', 'image']);
            }

            if (method_exists($post, 'setRelation')) {
                $post->setRelation('image', $image);
            }

            if (method_exists($post, 'unsetRelation')) {
                $post->unsetRelation('images');
            }
        } catch (Throwable $exception) {
            logger()->warning('Skip lightweight image normalization for V3 post/all item', [
                'post_id' => data_get($post, 'id'),
                'error' => $exception->getMessage(),
            ]);
        }

        return $post;
    }

    /**
     * Cache V3 post payload safely.
     *
     * Cache is opportunistic for this endpoint. If Redis is unavailable or payload
     * serialization fails, the user-facing response is still returned so API traffic
     * remains available.
     *
     * These keys are a shared namespace, not a private one: the public website runs on the
     * same Redis prefix and reads `cache:post:latest:{query}` and
     * `cache:post:slug:{slug}` directly, skipping the HTTP call on a hit. Only add
     * a call here for a key some consumer actually reads — the write is not free,
     * because RedisHelper json_encodes the payload and every appended accessor
     * fires again during that encode.
     *
     * @param string $cacheKey
     * @param mixed $payload
     * @param int $ttl
     * @param array<string, mixed> $debugContext
     *
     * @return void
     */
    protected function cachePublicPayload(string $cacheKey, mixed $payload, ?int $ttl = null, array $debugContext = []): void
    {
        try {
            RedisHelper::getInstance()->set($cacheKey, $payload, $ttl);
        } catch (Throwable $exception) {
            logger()->warning('Skip public V3 post cache write to avoid 500 from cache layer', [
                'cache_key' => $cacheKey,
                'context' => $debugContext,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get latest posts by limit
     *
     * @param GetLatestPostsRequest $request
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-03, 11:15 ICT
     */
    public function latestPosts(GetLatestPostsRequest $request)
    {
        //  Get latest posts
        $posts = $this->normalizePostListResult($this->post->getLatestPosts($request->input('limit', 12)));

        //  Set or get cover param to image in every post
        $posts = $posts->map(function ($post): mixed {
            return $this->attachCoverImage($post);
        });

        //  Parse request
        $requestString = http_build_query($request->all());

        //  Store to Redis cache
        $payload = $this->serializeOnce($posts);
        $this->cachePublicPayload(
            sprintf(CacheConstants::CACHE_LATEST_POSTS, $requestString),
            $payload,
            3600
        );

        return ires()->data($payload);
    }

    /**
     * get featured posts by limit
     *
     * @param GetFeaturedPostsRequest $request
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-03, 10:16 ICT
     */
    public function featuredPosts(GetFeaturedPostsRequest $request)
    {
        //  Get featured posts
        $posts = $this->normalizePostListResult($this->post->getFeaturedPosts($request->input('limit', 12)));

        //  Set or get cover param to image in every post
        $posts = $posts->map(function ($post): mixed {
            return $this->attachCoverImage($post);
        });

        //  No Redis write: CacheKeyHelper::featuredPosts() yields
        //  `cache:post:featured:lang:N:limit:N`, while the public website reads
        //  `cache:post:featured:limit=N`. Nothing consumes the key this endpoint
        //  would write, and writing it re-serializes the whole collection.
        return ires()->data($posts);
    }

    /**
     * Show Post detail by uuid
     *
     * @param string $slug
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-04-21, 10:35 ICT
     * @author malayvuong
     */
    public function about(string $slug): mixed
    {
        //  Get post by UUID
        $post = $this->post->getPostDetailBySlug($slug);

        if (!$post) {
            return ires()->error()->code(404)->data(['about_slug' => $slug])->message(__('mgs.post.not_found'));
        }

        //  No Redis write: this endpoint wrote `cache:about:{slug}`, but
        //  the public website reads `cache:post:about:{slug}` — a shape that has never
        //  existed in Redis. The old key only accumulated entries with no expiry.
        return ires()->data($post);
    }

    /**
     * Show Post detail by uuid
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 10:35 ICT
     */
    public function show(string $uuid): mixed
    {
        //  Get post by UUID
        $post = $this->post->getPostDetailByUuid($uuid);

        if (!$post) {
            return ires()->error()->code(404)->data(['uuid' => $uuid])->message(__('mgs.post.not_found'));
        }

        return ires()->data($post);
    }

    /**
     * Show Post detail by slug
     *
     * @param string $slug
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-04-21, 10:35 ICT
     * @author malayvuong
     */
    public function detailBySlug(string $slug): mixed
    {
        //  Get post by UUID
        $post = $this->post->getPostDetailBySlug($slug);

        if (!$post) {
            return ires()->error()->code(404)->data(['detail_slug' => $slug])->message(__('mgs.post.not_found'));
        }

        //  Load post image by cover, facebook and twitter
        if ($post->image) {
            $post->image->cover_lg = $this->imageVariantUrl($post->image, 'post.cover', 'cover_lg');
            $post->image->cover_xl = $this->imageVariantUrl($post->image, 'post.cover', 'cover_xl');
            $post->image->facebook = $this->imageVariantUrl($post->image, 'post.cover', 'facebook');
            $post->image->twitter = $this->imageVariantUrl($post->image, 'post.cover', 'twitter');
        }

        //  Store to Redis. The TTL matters: on the shared `volatile-lru` Redis a
        //  key without one can never be evicted, and this wrote one per post
        //  forever (1,652 keys, ~68 MB on 2026-10-24). Post saves still delete
        //  the key at once (ContentPostCacheInvalidationService).
        $payload = $this->serializeOnce($post);
        $this->cachePublicPayload(sprintf(CacheConstants::CACHE_POST_BY_SLUG, $slug), $payload, 3600);

        return ires()->data($payload);
    }

    /**
     * Public list endpoints must keep collection shape even when a repository
     * uses the legacy `take=1` path that returns a single model.
     */
    protected function normalizePostListResult(mixed $posts): \Illuminate\Support\Collection
    {
        if ($posts instanceof \Illuminate\Support\Collection) {
            return $posts;
        }

        if ($posts instanceof \Traversable) {
            return collect(iterator_to_array($posts));
        }

        if (is_array($posts)) {
            return collect($posts);
        }

        return $posts === null ? collect() : collect([$posts]);
    }

    /**
     * Attach cropped cover image for a post when image relation exists.
     *
     * @param mixed $post
     *
     * @return mixed
     */
    protected function attachCoverImage(mixed $post): mixed
    {
        if (!is_object($post) || !isset($post->image) || $post->image === null || !is_object($post->image) || !method_exists($post->image, 'cropImage')) {
            return $post;
        }

        $image = $post->image;
        $coverUrl = $this->imageVariantUrl($image, 'post.card', 'cover_lg');
        if ($coverUrl !== null) {
            $image->cover_lg = $coverUrl;
        }

        if (method_exists($image, 'setAppends')) {
            $image->setAppends(self::PUBLIC_LIST_IMAGE_APPENDS);
        }

        if (method_exists($post, 'setRelation')) {
            $post->setRelation('image', $image);
        }

        return $post;
    }

    /**
     * Resolve the expected image variant for a public post context.
     */
    protected function imageVariantUrl(mixed $image, string $context, string $presetName): ?string
    {
        if (!is_object($image)) {
            return null;
        }

        if ($image instanceof Upload) {
            try {
                return app(ImageVariantService::class)->urlForContext($image, $context, $presetName);
            } catch (Throwable $exception) {
                logger()->warning('Skip context-aware V3 post image variant generation', [
                    'upload_id' => $image->id,
                    'context' => $context,
                    'preset' => $presetName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (method_exists($image, 'cropImage')) {
            try {
                return $image->cropImage($presetName);
            } catch (Throwable $exception) {
                logger()->warning('Skip V3 post image variant fallback', [
                    'context' => $context,
                    'preset' => $presetName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }
}
