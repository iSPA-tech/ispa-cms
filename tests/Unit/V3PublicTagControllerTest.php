<?php

namespace Tests\Unit;

use Cms\Content\Http\Controllers\V3\Public\TagController;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Cms\Content\Interfaces\TagInterface;
use Cms\Content\Models\Post;
use Cms\Core\Models\Tag;
use Cms\Core\Models\TagData;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Content\Services\Cache\ContentPublicTagCacheService;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class V3PublicTagControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(Tables::TAGS);
        Schema::create(Tables::TAGS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid()->nullable();
            $table->string('type', 20)->default('post');
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::dropIfExists(Tables::TAGS_DATA);
        Schema::create(Tables::TAGS_DATA, static function (Blueprint $table): void {
            $table->id();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('tag_id')->nullable();
            $table->timestamps();
        });

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(Tables::TAGS_DATA);
        Schema::dropIfExists(Tables::TAGS);

        parent::tearDown();
    }

    public function test_index_does_not_eager_load_missing_images_relation(): void
    {
        Tag::query()->create([
            'name' => 'Xe dien',
            'slug' => 'xe-dien',
            'description' => 'Electric cars',
        ]);

        $controller = $this->tagController();

        $response = $controller->index();
        $payload = $response->toResponse(request())->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('xe-dien', $payload['data'][0]['slug']);
    }

    public function test_index_does_not_use_legacy_cms_cache_service(): void
    {
        $source = file_get_contents(base_path('cms/Content/app/Http/Controllers/V3/Public/TagController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('CMSCache::', $source);
    }

    public function test_index_cache_refreshes_after_same_second_tag_update(): void
    {
        $tag = Tag::query()->create([
            'name' => 'Xe dien',
            'slug' => 'xe-dien',
            'description' => 'Electric cars',
        ]);

        $controller = $this->tagController();

        $first = $controller->index()->toResponse(request())->getData(true);
        $this->assertSame('Xe dien', $first['data'][0]['name']);

        $tag->update([
            'name' => 'Xe điện mới',
            'description' => 'Electric cars updated',
        ]);

        $second = $controller->index()->toResponse(request())->getData(true);

        $this->assertSame('Xe điện mới', $second['data'][0]['name']);
        $this->assertSame('Electric cars updated', $second['data'][0]['description']);
    }

    public function test_show_does_not_use_the_legacy_non_expiring_redis_key(): void
    {
        $previousRedisEnabled = config('database.redis.enable');
        config(['database.redis.enable' => true]);
        Redis::shouldReceive('connection')->never();

        try {
            Tag::query()->create([
                'name' => 'Xe dien',
                'slug' => 'xe-dien',
                'description' => 'Electric cars',
            ]);

            $controller = $this->tagController();

            $request = Request::create('/api/v3/tag/xe-dien', 'GET');
            $response = $controller->show('xe-dien', $request);
            $payload = $response->toResponse($request)->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertSame('xe-dien', $payload['data']['tag']['slug']);
        } finally {
            config(['database.redis.enable' => $previousRedisEnabled]);
        }
    }

    public function test_show_reuses_public_tag_and_post_cache(): void
    {
        $tag = new Tag(['id' => 7, 'name' => 'Xe dien', 'slug' => 'xe-dien']);
        $tag->id = 7;
        $paginator = new LengthAwarePaginator([], 0, 24, 1);
        $cache = Mockery::mock(ContentPublicTagCacheService::class);
        $cache->shouldReceive('rememberTag')
            ->once()
            ->with('xe-dien', Mockery::type('callable'))
            ->andReturn($tag);
        $cache->shouldReceive('rememberPosts')
            ->once()
            ->with(7, 1, 24)
            ->andReturn($paginator);
        $controller = new TagController(Mockery::mock(TagInterface::class), $cache);
        $request = Request::create('/api/v3/tag/xe-dien', 'GET');

        $payload = $controller->show('xe-dien', $request)->toResponse($request)->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('xe-dien', $payload['data']['tag']['slug']);
        $this->assertSame(24, $payload['data']['tags']['per_page']);
    }

    public function test_show_cache_serves_a_second_request_without_tag_tables(): void
    {
        Tag::query()->create([
            'name' => 'Xe dien',
            'slug' => 'xe-dien',
            'description' => 'Electric cars',
        ]);
        $firstRequest = Request::create('/api/v3/tag/xe-dien', 'GET');
        $first = $this->tagController()->show('xe-dien', $firstRequest)->toResponse($firstRequest)->getData(true);
        $this->assertSame('success', $first['status']);

        Schema::drop(Tables::TAGS_DATA);
        Schema::drop(Tables::TAGS);
        $secondRequest = Request::create('/tag/xe-dien', 'GET');
        $second = $this->tagController()->show('xe-dien', $secondRequest)->toResponse($secondRequest)->getData(true);

        $this->assertSame('success', $second['status']);
        $this->assertSame('xe-dien', $second['data']['tag']['slug']);
    }

    public function test_tag_assignment_moves_the_public_post_cache_namespace(): void
    {
        $tag = Tag::query()->create(['name' => 'Xe dien', 'slug' => 'xe-dien']);
        $baseKey = 'content:public:v3:tags:posts:v2:' . $tag->id . ':1:24';
        $scopes = [
            'content:public:tags:posts:' . $tag->id,
            ContentPostCacheInvalidationService::listScope('post', lang('id')),
            ContentPostCacheInvalidationService::latestScope('post', lang('id')),
        ];
        $before = app(ContentCacheVersionService::class)->versionedKey($baseKey, $scopes);

        TagData::query()->create([
            'target_type' => Post::class,
            'target_id' => 999,
            'tag_id' => $tag->id,
        ]);

        $after = app(ContentCacheVersionService::class)->versionedKey($baseKey, $scopes);
        $this->assertNotSame($before, $after);
    }

    private function tagController(): TagController
    {
        return new TagController(
            Mockery::mock(TagInterface::class),
            app(ContentPublicTagCacheService::class),
        );
    }
}
