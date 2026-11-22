<?php

namespace Tests\Unit;

use Cms\Content\Http\Controllers\V3\Public\CategoryController;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Http\Requests\V3\GetFeaturedCategoriesRequest;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class V3PublicCategoryControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_featured_categories_tolerates_posts_without_image_relation(): void
    {
        $categoryRepository = Mockery::mock(CategoryInterface::class);
        $postRepository = Mockery::mock(PostInterface::class);

        $category = (object) [
            'id' => 11,
            'name' => 'Xe sang',
            'slug' => 'xe-sang',
        ];

        $image = $this->makeImageDouble();

        $posts = collect([
            $this->makePost('Imageless featured post', null),
            $this->makePost('Post with image', $image),
        ]);

        $categoryRepository->shouldReceive('getFeaturedCategories')
            ->once()
            ->with(getCategoryTypeIdByString('article'), 3)
            ->andReturn(collect([$category]));
        $categoryRepository->shouldReceive('getChildrenIdsForMany')
            ->once()
            ->with([11])
            ->andReturn([11 => [11]]);

        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([11], 1, 6, 'post')
            ->andReturn($posts);

        $controller = new CategoryControllerDouble($categoryRepository, $postRepository);

        $request = GetFeaturedCategoriesRequest::create(
            '/api/v3/public/category/featured',
            'GET',
            ['limit' => 6, 'childLimit' => 3]
        );

        $response = $controller->featuredCategories($request);

        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(1, $payload['data']);
        $this->assertCount(2, $payload['data'][0]['posts']);
        $this->assertNull($payload['data'][0]['posts'][0]['image']);
        $this->assertSame(
            'cropped-cover_lg.jpg',
            $payload['data'][0]['posts'][1]['image']['cover_lg']
        );
        $this->assertSame(['url'], $image->getAppends());
    }

    public function test_category_all_ignores_redis_cache_write_failure(): void
    {
        $previousRedisEnabled = config('database.redis.enable');
        config(['database.redis.enable' => true]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis down'));

        try {
            $categoryRepository = Mockery::mock(CategoryInterface::class);
            $postRepository = Mockery::mock(PostInterface::class);

            $categoryRepository->shouldReceive('get')
                ->once()
                ->andReturn(collect([
                    (object) ['id' => 1, 'name' => 'Category A'],
                ]));

            $controller = new CategoryControllerDouble($categoryRepository, $postRepository);

            $request = Request::create('/api/v3/category/all', 'GET');
            $response = $controller->getAll();
            $payload = $response->toResponse($request)->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertSame('Category A', $payload['data'][0]['name']);
        } finally {
            config(['database.redis.enable' => $previousRedisEnabled]);
        }
    }

    public function test_featured_categories_tolerates_array_payload(): void
    {
        $categoryRepository = Mockery::mock(CategoryInterface::class);
        $postRepository = Mockery::mock(PostInterface::class);

        $categoryRepository->shouldReceive('getFeaturedCategories')
            ->once()
            ->with(getCategoryTypeIdByString('article'), 3)
            ->andReturn([
                ['id' => 11, 'name' => 'Xe sang', 'slug' => 'xe-sang'],
            ]);
        $categoryRepository->shouldReceive('getChildrenIdsForMany')
            ->once()
            ->with([11])
            ->andReturn([11 => [11]]);

        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([11], 1, 6, 'post')
            ->andReturn(collect([
                $this->makePost('Imageless featured post', null),
            ]));

        $controller = new CategoryControllerDouble($categoryRepository, $postRepository);

        $request = GetFeaturedCategoriesRequest::create(
            '/api/v3/public/category/featured',
            'GET',
            ['limit' => 6, 'childLimit' => 3]
        );

        $response = $controller->featuredCategories($request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('xe-sang', $payload['data'][0]['slug']);
        $this->assertCount(1, $payload['data'][0]['posts']);
        $this->assertNull($payload['data'][0]['posts'][0]['image']);
    }

    public function test_preview_posts_generate_card_cover_variant_for_real_upload(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs(
            'image/2026/06',
            UploadedFile::fake()->image('source.jpg', 1600, 1000),
            'source.jpg'
        );

        $image = $this->makeUploadImage();
        $controller = new CategoryControllerDouble(
            Mockery::mock(CategoryInterface::class),
            Mockery::mock(PostInterface::class)
        );
        $method = new ReflectionMethod($controller, 'decoratePostPreviewImages');

        $method->invoke($controller, collect([
            $this->makePost('Post with real upload', $image),
        ]));

        Storage::disk('public')->assertExists('image/2026/06/source.jpg.600x360.webp');
        $this->assertStringContainsString('/storage/image/2026/06/source.jpg.600x360.webp', $image->cover_lg);
        $this->assertSame('ready', data_get($image->config, 'variants.cover_lg.status'));
    }

    public function test_featured_categories_preserves_paginator_shape(): void
    {
        $categoryRepository = Mockery::mock(CategoryInterface::class);
        $postRepository = Mockery::mock(PostInterface::class);

        $category = (object) [
            'id' => 22,
            'name' => 'Xe nam',
            'slug' => 'xe-nam',
        ];

        $paginator = new LengthAwarePaginator(
            collect([
                $this->makePost('Post A', null),
                $this->makePost('Post B', $this->makeImageDouble()),
            ]),
            2,
            2,
            1,
            ['path' => '/api/v3/public/category/featured']
        );

        $categoryRepository->shouldReceive('getFeaturedCategories')
            ->once()
            ->with(getCategoryTypeIdByString('article'), 3)
            ->andReturn(collect([$category]));

        $categoryRepository->shouldReceive('getChildrenIdsForMany')
            ->once()
            ->with([22])
            ->andReturn([22 => [22]]);

        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([22], 1, 6, 'post')
            ->andReturn($paginator);

        $controller = new CategoryControllerDouble($categoryRepository, $postRepository);

        $request = GetFeaturedCategoriesRequest::create(
            '/api/v3/public/category/featured',
            'GET',
            ['limit' => 6, 'childLimit' => 3]
        );

        $response = $controller->featuredCategories($request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(1, $payload['data']);
        $this->assertArrayHasKey('data', $payload['data'][0]['posts']);
        $this->assertCount(2, $payload['data'][0]['posts']['data']);
    }

    public function test_tree_with_post_tolerates_posts_without_image_relation(): void
    {
        $categoryRepository = Mockery::mock(CategoryInterface::class);
        $postRepository = Mockery::mock(PostInterface::class);

        $category = (object) [
            'id' => 11,
            'name' => 'Xe sang',
            'slug' => 'xe-sang',
            'has_children' => false,
            'public_children' => collect(),
        ];

        $posts = collect([
            $this->makePost('Imageless tree post', null),
            $this->makePost('Tree post with image', $this->makeImageDouble()),
        ]);

        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([11], 1, 15, 'post')
            ->andReturn($posts);

        $controller = new CategoryControllerDouble(
            $categoryRepository,
            $postRepository,
            $category
        );

        $request = Request::create('/api/v3/public/category/xe-sang/tree-with-post', 'GET');

        $response = $controller->treeWithPost('xe-sang', $request);

        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('Xe sang', $payload['data']['name']);
        $this->assertCount(2, $payload['data']['posts']);
        $this->assertNull($payload['data']['posts'][0]['image']);
        $this->assertSame(
            'cropped-cover_lg.jpg',
            $payload['data']['posts'][1]['image']['cover_lg']
        );
    }

    public function test_tree_with_post_uses_batched_child_tree_lookup(): void
    {
        $categoryRepository = Mockery::mock(CategoryInterface::class);
        $postRepository = Mockery::mock(PostInterface::class);

        $firstChild = (object) ['id' => 101, 'name' => 'Xe sang A'];
        $secondChild = (object) ['id' => 102, 'name' => 'Xe sang B'];
        $category = (object) [
            'id' => 11,
            'name' => 'Xe sang',
            'slug' => 'xe-sang',
            'has_children' => true,
            'public_children' => collect([$firstChild, $secondChild]),
        ];

        $categoryRepository->shouldReceive('getChildrenIdsForMany')
            ->once()
            ->with([101, 102])
            ->andReturn([
                101 => [101, 201],
                102 => [102],
            ]);

        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([101, 201], 1, 6, 'post')
            ->andReturn(collect([$this->makePost('Child A post', null)]));
        $postRepository->shouldReceive('getPostsByCategoriesIds')
            ->once()
            ->with([102], 1, 6, 'post')
            ->andReturn(collect([$this->makePost('Child B post', null)]));

        $controller = new CategoryControllerDouble(
            $categoryRepository,
            $postRepository,
            $category
        );

        $request = Request::create('/api/v3/public/category/xe-sang/tree-with-post', 'GET');

        $response = $controller->treeWithPost('xe-sang', $request);
        $payload = $response->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(2, $payload['data']['public_children']);
        $this->assertSame('Child A post', $payload['data']['public_children'][0]['posts'][0]['name']);
        $this->assertSame('Child B post', $payload['data']['public_children'][1]['posts'][0]['name']);
    }

    private function makePost(string $name, ?object $image): object
    {
        return (object) [
            'name' => $name,
            'image' => $image,
        ];
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

class CategoryControllerDouble extends CategoryController
{
    public function __construct(
        CategoryInterface $category,
        PostInterface $post,
        private readonly ?object $categoryTreeOverride = null,
    ) {
        parent::__construct($category, $post);
    }

    protected function findPublishedCategoryTreeBySlug(string $slug): mixed
    {
        return $this->categoryTreeOverride;
    }
}
