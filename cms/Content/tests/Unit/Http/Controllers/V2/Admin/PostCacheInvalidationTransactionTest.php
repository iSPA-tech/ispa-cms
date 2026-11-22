<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Content\Http\Controllers\V2\Admin\PostController;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Illuminate\Support\Facades\DB;

test('admin post cache invalidation runs only after transaction commit', function () {
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

    $before = ['id' => 10, 'content_hash' => 'old'];
    $after = ['id' => 10, 'content_hash' => 'new'];

    DB::beginTransaction();
    schedulePostCacheInvalidationForTest($before, $after);

    expect($invalidator->calls)->toBe([]);

    DB::commit();

    expect($invalidator->calls)->toBe([[$before, $after]]);
});

test('admin post cache invalidation does not run when save transaction rolls back', function () {
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

    DB::beginTransaction();
    schedulePostCacheInvalidationForTest([], ['id' => 10, 'content_hash' => 'new']);
    DB::rollBack();

    expect($invalidator->calls)->toBe([]);
});

function schedulePostCacheInvalidationForTest(array $before, array $after): void
{
    $controller = (new ReflectionClass(PostController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(PostController::class, 'schedulePostCacheInvalidation');
    $method->setAccessible(true);
    $method->invoke($controller, $before, $after);
}
