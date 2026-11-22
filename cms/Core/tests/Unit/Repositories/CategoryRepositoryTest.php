<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Facades\CMSCache;
use Illuminate\Support\Facades\Cache;
use Cms\Core\Repositories\CategoryRepository;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (!Schema::hasTable('languages')) {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('path')->unique();
            $table->string('path_alias')->nullable();
            $table->string('flag')->nullable();
            $table->string('currency')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (!DB::table('languages')->where('path', 'vi')->exists()) {
        DB::table('languages')->insert([
            'name' => 'Vietnamese',
            'path' => 'vi',
            'path_alias' => 'vi',
            'flag' => null,
            'currency' => 'VND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

function withCategoryRepositoryCacheStore(string $store, Closure $callback): void
{
    $previous = Cache::getDefaultDriver();

    Cache::setDefaultDriver($store);
    Cache::purge($store);
    Cache::store($store)->flush();

    try {
        $callback();
    } finally {
        Cache::store($store)->flush();
        Cache::purge($store);
        Cache::setDefaultDriver($previous);
        Cache::purge($previous);
    }
}

function publicCategoryCacheSnapshot(array $overrides = []): array
{
    return array_merge([
        'id' => 5,
        'type' => 0,
        'language_id' => 1,
        'public' => true,
        'status' => 'published',
        'parent_id' => 2,
        'slugs' => ['category-5'],
        'category_scope_ids' => [2, 5],
    ], $overrides);
}

test('CategoryRepository public post cards retain media and publish fields', function () {
    expect(CategoryRepository::PUBLIC_POST_CARD_COLUMNS)->toContain(
        'id',
        'posts.category_id',
        'name',
        'description',
        'published_at',
        'video_url',
    );
    expect(CategoryRepository::PUBLIC_POST_CARD_CACHE_VERSION)->toBe('v3');
});

test('category post queries eagerly load the primary category and its slug', function () {
    withCategoryRepositoryCacheStore('array', function (): void {
        $query = new class((new \Cms\Content\Models\Post())->newQuery()->getQuery()) extends Builder {
            public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
            {
                expect($this->getQuery()->columns)->toContain('posts.category_id');
                expect(array_keys($this->getEagerLoads()))->toContain('category', 'category.slugs');
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 12);
            }
        };
        $query->setModel(new \Cms\Content\Models\Post());
        $category = new class($query) {
            public array $public_children = [];
            public function __construct(private Builder $query) {}
            public function posts(): Builder { return $this->query; }
            public function setAttribute($key, $value): void {}
        };
        $repo = new class($category) extends CategoryRepository {
            public function __construct(private object $category) {}
            public function get(array $params): mixed { return $this->category; }
        };
        $repo->getCategoryBySlugWithPosts('secondary-category', true);
    });
});

function ensureCategoryRepositoryCategoriesTable(): void
{
    if (Schema::hasTable('categories')) {
        return;
    }

    Schema::create('categories', static function (Blueprint $table): void {
        $table->id();
        $table->char('uid', 10)->nullable()->unique();
        $table->char('uuid', 36)->nullable()->unique();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->longText('content')->nullable();
        $table->text('hashtag')->nullable();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->unsignedTinyInteger('priority')->default(0);
        $table->unsignedTinyInteger('type')->default(0);
        $table->boolean('featured')->default(false);
        $table->char('status', 50)->nullable();
        $table->text('config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

test('CategoryRepository getChildrenIds delegates to batched lookup', function () {
    $repo = new class extends CategoryRepository {
        public array $receivedIds = [];

        public function __construct() {}

        public function getChildrenIdsForMany(array $ids): array
        {
            $this->receivedIds = $ids;

            return [42 => [42, 43]];
        }
    };

    $result = $repo->getChildrenIds(42);

    expect($result)->toBe([42, 43]);
    expect($repo->receivedIds)->toBe([42]);
});

test('CategoryRepository getChildrenIdsForMany returns descendants per root', function () {
    ensureCategoryRepositoryCategoriesTable();
    Cache::flush();

    $namePrefix = 'batch-descendants-' . uniqid();
    $now = now();

    $rootA = DB::table('categories')->insertGetId([
        'name' => $namePrefix . '-root-a',
        'status' => StatusEnum::PUBLISHED->value,
        'priority' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $childA = DB::table('categories')->insertGetId([
        'name' => $namePrefix . '-child-a',
        'parent_id' => $rootA,
        'status' => StatusEnum::PUBLISHED->value,
        'priority' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $grandChildA = DB::table('categories')->insertGetId([
        'name' => $namePrefix . '-grand-child-a',
        'parent_id' => $childA,
        'status' => StatusEnum::PUBLISHED->value,
        'priority' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $rootB = DB::table('categories')->insertGetId([
        'name' => $namePrefix . '-root-b',
        'status' => StatusEnum::PUBLISHED->value,
        'priority' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('categories')->insert([
        'name' => $namePrefix . '-draft-child-b',
        'parent_id' => $rootB,
        'status' => 'draft',
        'priority' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $repo = new CategoryRepository();
    $result = $repo->getChildrenIdsForMany([$rootA, $rootB]);

    expect($result[$rootA])->toBe([$rootA, $childA, $grandChildA]);
    expect($result[$rootB])->toBe([$rootB]);
});

test('CategoryRepository getChildrenIdsForMany uses stable cache key for same root set', function () {
    Cache::flush();

    $keys = [];
    CMSCache::shouldReceive('rememberWithTracking')
        ->twice()
        ->andReturnUsing(function (string $key, Closure $callback) use (&$keys): array {
            $keys[] = $key;

            return [];
        });

    $repo = new CategoryRepository();
    $repo->getChildrenIdsForMany([3, 1, 2]);
    $repo->getChildrenIdsForMany([2, 3, 1]);

    expect($keys)->toHaveCount(2);
    expect($keys[0])->toBe($keys[1]);
});

test('CategoryRepository getAllPublicCategoriesByType maps type and language', function () {
    $repo = new class extends CategoryRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $repo->getAllPublicCategoriesByType('product', 3);

    expect($repo->lastGet['where']['type'])->toBe((string) Constant::CATEGORY_TYPE_PRODUCT);
    expect($repo->lastGet['where']['language_id'])->toBe(3);
    expect($repo->lastGet['order']['priority'])->toBe('asc');
    expect($repo->lastGet['with'])->toContain('slugs:target_id,slug', 'language:id,name,path,flag');
});

test('CategoryRepository getCategoryBySlugAndType builds query and uses cache helper', function () {
    cache()->flush();

    $cacheHelper = new class extends CacheHelper {
        public array $stored = [];

        public function store(string $cacheKey, string $title, int $ttl = 0): void
        {
            $this->stored = [$cacheKey, $title, $ttl];
        }
    };

    CacheHelper::swap($cacheHelper);

    $repo = new class extends CategoryRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return (object)['id' => 1];
        }
    };

    $repo->getCategoryBySlugAndType('slug-1', 2, true);

    expect($repo->lastGet['where'])->toMatchArray([
        'type' => 2,
        'status' => StatusEnum::PUBLISHED->value,
    ]);
    expect($repo->lastGet['has'])->toHaveKey('slugs');
    expect($repo->lastGet['with'])->toContain('images', 'slugs:target_id,slug');
    expect($repo->lastGet['with'])->toHaveKey('children');
    expect($repo->lastGet['take'])->toBe(1);
    expect($cacheHelper->stored[1])->toContain('Category detail by slug and type 2');
});

test('category repository detail cache moves to a fresh namespace across cache stores', function (string $store) {
    withCategoryRepositoryCacheStore($store, function (): void {
        $repo = new class extends CategoryRepository {
            public array $lastGet = [];
            public int $getCalls = 0;

            public function __construct() {}

            public function get(array $params): mixed
            {
                $this->getCalls++;
                $this->lastGet = $params;

                return (object)['id' => $this->getCalls];
            }
        };

        expect($repo->getCategoryBySlugAndType('category-5', 0))->id->toBe(1);
        expect($repo->getCategoryBySlugAndType('category-5', 0))->id->toBe(1);
        expect($repo->getCalls)->toBe(1);

        app(CategoryCacheInvalidationService::class)->invalidateSnapshots(
            publicCategoryCacheSnapshot(),
            publicCategoryCacheSnapshot(['slugs' => ['category-5'], 'parent_id' => 3])
        );

        expect($repo->getCategoryBySlugAndType('category-5', 0))->id->toBe(2);
        expect($repo->getCalls)->toBe(2);
    });
})->with(['array', 'file']);

test('category repository detail cache separates pagination requests', function () {
    withCategoryRepositoryCacheStore('array', function (): void {
        $cacheHelper = new class extends CacheHelper {
            public array $keys = [];

            public function store(string $cacheKey, string $title, int $ttl = 0): void
            {
                $this->keys[] = $cacheKey;
            }
        };
        CacheHelper::swap($cacheHelper);

        $repo = new class extends CategoryRepository {
            public function __construct() {}

            public function get(array $params): mixed
            {
                return null;
            }
        };

        app()->instance('request', Request::create('/bai-viet/tin-xe', 'GET', ['page' => 2, 'pageSize' => 15, 'limit' => 15]));
        $repo->getCategoryBySlugWithPosts('tin-xe', true);
        cache()->flush();
        app()->instance('request', Request::create('/bai-viet/tin-xe', 'GET', ['page' => 3, 'pageSize' => 15, 'limit' => 15]));
        $repo->getCategoryBySlugWithPosts('tin-xe', true);

        expect($cacheHelper->keys)->toHaveCount(2)
            ->and($cacheHelper->keys[0])->not->toBe($cacheHelper->keys[1]);
    });
});

test('CategoryRepository AdminIndex builds conditional filters', function () {
    $repo = new class extends CategoryRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $request = Request::create('/', 'GET', [
        'query' => 'lorem',
        'type' => 1,
        'typeStr' => 'product',
    ]);

    $repo->AdminIndex($request);

    expect($repo->lastGet['select'])->toContain('id', 'parent_id', 'name');
    expect($repo->lastGet['with'])->toContain('language:id,name,path,flag', 'slugs:target_id,slug,category');
    expect($repo->lastGet['when'][0][0])->toBeTrue();
    expect($repo->lastGet['when'][1][0])->toBeTrue();
    expect($repo->lastGet['when'][2][0])->toBeTrue();
    expect($repo->lastGet['order']['priority'])->toBe('asc');
});

test('CategoryRepository getCategoriesByType uses cache wrapper and builds query', function () {
    CMSCache::shouldReceive('rememberRandomWithTracking')
        ->once()
        ->andReturnUsing(function ($key, $callback, ...$rest) {
            return $callback();
        });

    $repo = new class extends CategoryRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $repo->getCategoriesByType('product');

    expect($repo->lastGet['where']['type'])->toBe((string) Constant::CATEGORY_TYPE_PRODUCT);
    expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
    expect($repo->lastGet['where']['language_id'])->toBe(CacheKeyHelper::getLangId());
    expect($repo->lastGet['with'])->toContain('images', 'slugs:target_id,slug');
    expect($repo->lastGet['with'])->toHaveKey('children');
});
