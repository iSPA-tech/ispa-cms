<?php

namespace Tests\Unit;

use Cms\Content\Http\Controllers\V3\Public\PostController;
use Cms\Content\Http\Requests\V3\GetFeaturedPostsRequest;
use Cms\Content\Http\Requests\V3\GetLatestPostsRequest;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Repositories\PostRepository;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class V3PublicPostControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_post_list_payload_includes_derivative_image_appends_without_original_url_only_payload(): void
    {
        $image = $this->makeImageDouble();
        $controller = new PostController(Mockery::mock(PostInterface::class));
        $method = new ReflectionMethod($controller, 'preparePostListPayload');

        $method->invoke($controller, collect([
            $this->makeListPost($image),
        ]));

        $this->assertSame([
            'thumbnail_sm',
            'cover_sm',
            'sm',
            'cover_md',
            'cover_lg',
            'cover_xl',
        ], $image->getAppends());
    }

    public function test_post_list_payload_generates_card_cover_variant_for_real_upload(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'image/2026/06',
            UploadedFile::fake()->image('source.jpg', 1600, 1000),
            'source.jpg'
        );

        $image = $this->makeUploadImage();
        $controller = new PostController(Mockery::mock(PostInterface::class));
        $method = new ReflectionMethod($controller, 'preparePostListPayload');

        $method->invoke($controller, collect([
            $this->makeListPost($image),
        ]));

        Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
        $this->assertStringContainsString('/storage/image/2026/06/source.jpg.600x360.webp', $image->cover_lg);
        $this->assertSame('ready', data_get($image->config, 'variants.cover_lg.status'));
    }

    public function test_latest_posts_include_derivative_image_appends_without_original_url_only_payload(): void
    {
        $image = $this->makeImageDouble();
        $postRepository = Mockery::mock(PostInterface::class);
        $postRepository->shouldReceive('getLatestPosts')
            ->once()
            ->with(2)
            ->andReturn(collect([
                $this->makePost('Latest post', $image),
            ]));

        $controller = new PostController($postRepository);
        $request = GetLatestPostsRequest::create('/api/v3/post/latest', 'GET', ['limit' => 2]);

        $response = $controller->latestPosts($request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cropped-cover_lg.jpg', $payload['data'][0]['image']['cover_lg']);
        $this->assertSame([
            'thumbnail_sm',
            'cover_sm',
            'sm',
            'cover_md',
            'cover_lg',
            'cover_xl',
        ], $image->getAppends());
    }

    public function test_latest_posts_keeps_collection_shape_when_repository_returns_single_model(): void
    {
        $postRepository = Mockery::mock(PostInterface::class);
        $postRepository->shouldReceive('getLatestPosts')
            ->once()
            ->with(1)
            ->andReturn($this->makePost('Single latest post', null));

        $controller = new PostController($postRepository);
        $request = GetLatestPostsRequest::create('/api/v3/post/latest', 'GET', ['limit' => 1]);

        $response = $controller->latestPosts($request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('Single latest post', $payload['data'][0]['name']);
    }

    public function test_latest_posts_ignores_redis_cache_write_failure(): void
    {
        $previousRedisEnabled = config('database.redis.enable');
        config(['database.redis.enable' => true]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis down'));

        try {
            $postRepository = Mockery::mock(PostInterface::class);
            $postRepository->shouldReceive('getLatestPosts')
                ->once()
                ->with(2)
                ->andReturn(collect([
                    $this->makePost('Latest post', null),
                ]));

            $controller = new PostController($postRepository);
            $request = GetLatestPostsRequest::create('/api/v3/post/latest', 'GET', ['limit' => 2]);

            $response = $controller->latestPosts($request);
            $payload = $response->toResponse($request)->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertSame('Latest post', $payload['data'][0]['name']);
        } finally {
            config(['database.redis.enable' => $previousRedisEnabled]);
        }
    }

    public function test_latest_posts_controller_refreshes_repository_cache_after_invalidation_across_stores(): void
    {
        foreach (['array', 'file'] as $store) {
            $this->withDefaultCacheStore($store, function (): void {
                $postRepository = new class extends PostRepository
                {
                    public int $getCalls = 0;

                    public function __construct()
                    {
                    }

                    public function get(array $params): mixed
                    {
                        $this->getCalls++;

                        return collect([
                            (object) [
                                'name' => 'Post ' . $this->getCalls,
                                'image' => null,
                            ],
                        ]);
                    }
                };

                $controller = new PostController($postRepository);
                $request = GetLatestPostsRequest::create('/api/v3/post/latest', 'GET', ['limit' => 2]);

                $controller->latestPosts($request)->toResponse($request);
                $controller->latestPosts($request)->toResponse($request);

                $this->assertSame(1, $postRepository->getCalls);

                app(ContentPostCacheInvalidationService::class)->invalidateSnapshots([], [
                    'id' => 200,
                    'uuid' => 'post-200',
                    'type' => 'post',
                    'language_id' => 1,
                    'public' => true,
                    'status' => 'published',
                    'published_at' => null,
                    'deleted_at' => null,
                    'slugs' => ['post-200'],
                    'category_ids' => [5],
                    'category_scope_ids' => [5],
                    'featured' => false,
                    'content_hash' => 'content',
                    'card_hash' => 'card',
                    'search_hash' => 'search',
                    'metadata_hash' => 'metadata',
                ]);

                $controller->latestPosts($request)->toResponse($request);

                $this->assertSame(2, $postRepository->getCalls);
            });
        }
    }

    public function test_featured_posts_include_derivative_image_appends_without_original_url_only_payload(): void
    {
        $image = $this->makeImageDouble();
        $postRepository = Mockery::mock(PostInterface::class);
        $postRepository->shouldReceive('getFeaturedPosts')
            ->once()
            ->with(2)
            ->andReturn(collect([
                $this->makePost('Featured post', $image),
            ]));

        $controller = new PostController($postRepository);
        $request = GetFeaturedPostsRequest::create('/api/v3/post/featured', 'GET', ['limit' => 2]);

        $response = $controller->featuredPosts($request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cropped-cover_lg.jpg', $payload['data'][0]['image']['cover_lg']);
        $this->assertSame([
            'thumbnail_sm',
            'cover_sm',
            'sm',
            'cover_md',
            'cover_lg',
            'cover_xl',
        ], $image->getAppends());
    }

    public function test_detail_posts_include_detail_cover_variant(): void
    {
        $image = $this->makeImageDouble();
        $postRepository = Mockery::mock(PostInterface::class);
        $postRepository->shouldReceive('getPostDetailBySlug')
            ->once()
            ->with('post-slug')
            ->andReturn($this->makePost('Detail post', $image));

        $controller = new PostController($postRepository);
        $request = \Illuminate\Http\Request::create('/api/v3/post/post-slug', 'GET');

        $response = $controller->detailBySlug('post-slug');
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cropped-cover_lg.jpg', $payload['data']['image']['cover_lg']);
        $this->assertSame('cropped-cover_xl.jpg', $payload['data']['image']['cover_xl']);
        $this->assertSame('cropped-facebook.jpg', $payload['data']['image']['facebook']);
        $this->assertSame('cropped-twitter.jpg', $payload['data']['image']['twitter']);
    }

    public function test_detail_posts_generate_post_cover_context_for_real_upload(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'image/2026/06',
            UploadedFile::fake()->image('source.jpg', 1600, 1000),
            'source.jpg'
        );

        $image = $this->makeUploadImage();
        $postRepository = Mockery::mock(PostInterface::class);
        $postRepository->shouldReceive('getPostDetailBySlug')
            ->once()
            ->with('post-slug')
            ->andReturn($this->makePost('Detail post', $image));

        $controller = new PostController($postRepository);

        $controller->detailBySlug('post-slug');

        Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
        Storage::disk('public')->assertExists('image/2026/06/source.jpg.1200x720.webp');
        Storage::disk('public')->assertExists('image/2026/06/source.jpg.1200x630.webp');
        Storage::disk('public')->assertExists('image/2026/06/source.jpg.1024x512.webp');
        $this->assertSame('ready', data_get($image->config, 'variants.twitter.status'));
    }

    private function makePost(string $name, ?object $image): object
    {
        return (object) [
            'name' => $name,
            'image' => $image,
        ];
    }

    private function withDefaultCacheStore(string $store, \Closure $callback): void
    {
        $previous = Cache::getDefaultDriver();
        $original = getenv('SKIP_LANGUAGES_QUERY');

        Cache::setDefaultDriver($store);
        Cache::purge($store);
        Cache::store($store)->flush();
        // The cache version service memoises versions per instance; a new store starts from a clean memo.
        app()->forgetScopedInstances();
        putenv('SKIP_LANGUAGES_QUERY=true');

        try {
            $callback();
        } finally {
            Cache::store($store)->flush();
            Cache::purge($store);
            Cache::setDefaultDriver($previous);
            Cache::purge($previous);

            if ($original === false) {
                putenv('SKIP_LANGUAGES_QUERY');
            } else {
                putenv('SKIP_LANGUAGES_QUERY=' . $original);
            }
        }
    }

    private function makeListPost(object $image): object
    {
        return new class ($image)
        {
            public function __construct(public object $image)
            {
            }

            public function relationLoaded(string $relation): bool
            {
                return false;
            }
        };
    }

    private function makeImageDouble(): object
    {
        return new class
        {
            public ?string $cover_lg = null;
            private array $appends = [];

            public function cropImage(string $size): string
            {
                return 'cropped-' . $size . '.jpg';
            }

            public function setAppends(array $appends): void
            {
                $this->appends = $appends;
            }

            public function getAppends(): array
            {
                return $this->appends;
            }
        };
    }

    private function makeUploadImage(): Upload
    {
        return new Upload([
            'disk' => 'public',
            'type' => 'image',
            'path' => 'image/2026/06',
            'name' => 'source.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1000,
            'dimensions' => ['width' => 1600, 'height' => 1000],
            'config' => [],
        ]);
    }
}
