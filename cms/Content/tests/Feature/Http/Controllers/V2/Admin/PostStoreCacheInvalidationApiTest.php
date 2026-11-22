<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Clients\CmsClient;
use App\Clients\OpenAIClient;
use App\Enums\StatusEnum;
use App\Models\User;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Models\Post;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (!Schema::hasTable(CoreTableConstant::ENUMERATES)) {
        Artisan::call('migrate', [
            '--path' => 'cms/Core/database/migrations/2010_01_01_000001_create_enumerates_table.php',
            '--realpath' => false,
        ]);
    }

    DB::table(CoreTableConstant::ENUMERATES)->updateOrInsert(
        ['enum_key' => StatusEnum::PUBLISHED->value],
        [
            'name' => 'Published',
            'type' => 'status',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
});

test('admin post store API rolls back and does not invalidate cache when save fails before commit', function () {
    $invalidator = new class extends ContentPostCacheInvalidationService {
        public array $calls = [];

        public function __construct()
        {
            parent::__construct(new ContentCacheVersionService());
        }

        public function invalidateSnapshots(array $before, array $after): array
        {
            $this->calls[] = [$before, $after];

            return ['content:post:10'];
        }
    };

    app()->instance(ContentPostCacheInvalidationService::class, $invalidator);
    app()->instance(CategoryInterface::class, \Mockery::mock(CategoryInterface::class));
    app()->instance(CategoryTargetInterface::class, \Mockery::mock(CategoryTargetInterface::class));
    app()->instance(CmsClient::class, \Mockery::mock(CmsClient::class));
    app()->instance(OpenAIClient::class, \Mockery::mock(OpenAIClient::class));

    $postRepository = \Mockery::mock(PostInterface::class);
    $postRepository->shouldReceive('model')->andReturn(new PostStoreApiSaveFailurePost());
    $postRepository->shouldReceive('checkUnique')->andReturn('post-save-failure-uuid');
    app()->instance(PostInterface::class, $postRepository);

    $user = new PostStoreApiCacheTestUser();
    $user->id = 77;

    $response = $this
        ->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/api/v2/admin/post', [
            'name' => 'Cache save failure',
            'type' => 'post',
            'content' => [
                ['type' => 'text', 'value' => 'Body'],
            ],
            'status' => StatusEnum::PUBLISHED->value,
            'slugs' => ['slug' => 'cache-save-failure'],
        ]);

    $response
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'forced post save failure');

    expect(DB::transactionLevel())->toBe(0);
    expect($invalidator->calls)->toBe([]);
});

class PostStoreApiSaveFailurePost extends Post
{
    public function save(array $options = [])
    {
        throw new RuntimeException('forced post save failure');
    }
}

class PostStoreApiCacheTestUser extends User
{
    public function can($abilities, $arguments = []): bool
    {
        return true;
    }
}
