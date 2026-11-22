<?php

uses(\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Cms\Content\Services\Cache\ContentCacheVersionService;
use Cms\Content\Repositories\PostRepository;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;

final class LatestPostOrderFixture extends Model
{
    public $timestamps = false;

    protected $table = 'latest_post_order_fixtures';

    protected $guarded = [];
}

function makePostRepositoryStub(): PostRepository
{
    return new class extends PostRepository {
        public array $lastGet = [];
        public int $getCalls = 0;

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->getCalls++;
            $this->lastGet = $params;

            return ['call' => $this->getCalls];
        }
    };
}

function withPostRepositoryCacheStore(string $store, Closure $callback): void
{
    $previous = Cache::getDefaultDriver();

    Cache::setDefaultDriver($store);
    Cache::purge($store);
    Cache::store($store)->flush();

    $original = getenv('SKIP_LANGUAGES_QUERY');
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

function publicPostCacheSnapshot(array $overrides = []): array
{
    return array_merge([
        'id' => 100,
        'uuid' => 'post-100',
        'type' => 'post',
        'language_id' => 1,
        'public' => true,
        'status' => 'published',
        'published_at' => null,
        'deleted_at' => null,
        'slugs' => ['post-100'],
        'category_ids' => [5],
        'category_scope_ids' => [5],
        'featured' => false,
        'content_hash' => 'content',
        'card_hash' => 'card',
        'search_hash' => 'search',
        'metadata_hash' => 'metadata',
    ], $overrides);
}

function makePostCategoryRepositoryStub(): object
{
    return new class {
        public array $requestedIds = [];

        public function getChildrenIds(int $id): array
        {
            $this->requestedIds[] = $id;

            return [$id + 1000];
        }
    };
}

test('post repository latest scope prioritizes published at and then created at', function () {
    Schema::dropIfExists('latest_post_order_fixtures');
    Schema::create('latest_post_order_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('published_at')->nullable();
        $table->timestamp('created_at');
    });

    try {
        LatestPostOrderFixture::query()->insert([
            ['id' => 1, 'published_at' => '2026-09-03 08:00:00', 'created_at' => '2026-09-01 08:00:00'],
            ['id' => 2, 'published_at' => '2026-09-02 08:00:00', 'created_at' => '2026-09-03 12:00:00'],
            ['id' => 3, 'published_at' => '2026-09-03 08:00:00', 'created_at' => '2026-09-02 08:00:00'],
        ]);

        $repository = new PostRepository();
        $repository->setModel(LatestPostOrderFixture::class);

        $ids = $repository->get([
            'latest' => true,
            'select' => ['id'],
        ])->pluck('id')->all();

        expect($ids)->toBe([3, 1, 2]);
    } finally {
        Schema::dropIfExists('latest_post_order_fixtures');
    }
});

test('repository pagination honors an explicit current page', function () {
    Schema::dropIfExists('latest_post_order_fixtures');
    Schema::create('latest_post_order_fixtures', function (Blueprint $table): void {
        $table->id();
    });

    try {
        LatestPostOrderFixture::query()->insert(array_map(fn (int $id): array => ['id' => $id], range(1, 5)));
        $repository = new PostRepository();
        $repository->setModel(LatestPostOrderFixture::class);

        $page = $repository->get([
            'order' => ['id' => 'asc'],
            'page' => 2,
            'current_page' => 2,
        ]);

        expect($page->currentPage())->toBe(2)
            ->and($page->pluck('id')->all())->toBe([3, 4]);
    } finally {
        Schema::dropIfExists('latest_post_order_fixtures');
    }
});

test('post repository latest video uses created at as published at tie breaker', function () {
    $original = getenv('SKIP_LANGUAGES_QUERY');
    putenv('SKIP_LANGUAGES_QUERY=true');
    cache()->flush();

    $repository = makePostRepositoryStub();

    try {
        $repository->getLatestVideo();

        expect($repository->lastGet['order'])->toBe([
            'published_at' => 'desc',
            'created_at' => 'desc',
        ]);
    } finally {
        if ($original === false) {
            putenv('SKIP_LANGUAGES_QUERY');
        } else {
            putenv('SKIP_LANGUAGES_QUERY=' . $original);
        }
    }
});

function makePostIndexQueryRecorder(): object
{
    return new class {
        public array $whereNull = [];
        public array $whereIn = [];
        public array $where = [];
        public array $orWhere = [];
        public array $nested = [];

        public function where($column, mixed $operator = null, mixed $value = null): self
        {
            if ($column instanceof Closure) {
                $nested = makePostIndexQueryRecorder();
                $column($nested);
                $this->nested[] = $nested;

                return $this;
            }

            $this->where[] = [$column, $operator, $value];

            return $this;
        }

        public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
        {
            $this->orWhere[] = [$column, $operator, $value];

            return $this;
        }

        public function whereNull(string $column): self
        {
            $this->whereNull[] = $column;

            return $this;
        }

        public function whereIn(string $column, array $values): self
        {
            $this->whereIn[] = [$column, $values];

            return $this;
        }
    };
}

test('admin post index handles no category array without generic category filter', function () {
    request()->replace([
        'categoryId' => [0],
        'status' => 'all',
        'pageSize' => 20,
    ]);

    $repo = makePostRepositoryStub();
    $category = new class {
        public function getChildrenIds(int $id): array
        {
            throw new \RuntimeException('No-category filter must not load category children.');
        }
    };

    $repo->index('post', $category);

    expect($repo->lastGet['filter'])->not->toHaveKey('categoryId');

    $categoryFilter = $repo->lastGet['when'][3];
    expect($categoryFilter[0])->toBeTrue();

    $query = makePostIndexQueryRecorder();
    $categoryFilter[1]($query);

    expect($query->whereNull)->toBe(['category_id']);
    expect($query->whereIn)->toBe([]);
});

test('admin post index orders newest inserted IDs in database pagination', function () {
    request()->replace(['pageSize' => 25]);

    $repo = makePostRepositoryStub();
    $repo->index('post', makePostCategoryRepositoryStub());

    expect($repo->lastGet['order'])->toBe(['id' => 'desc'])
        ->and($repo->lastGet['page'])->toBe(25);
});

test('admin post index keeps keyword filter scoped to uncategorized posts', function () {
    request()->replace([
        'keywords' => 'giới thiệu',
        'categoryId' => [0],
        'status' => 'all',
        'pageSize' => 20,
    ]);

    $repo = makePostRepositoryStub();
    $category = makePostCategoryRepositoryStub();

    $repo->index('post', $category);

    expect($repo->lastGet['filter'])->not->toHaveKey('keywords');
    expect($repo->lastGet['filter'])->not->toHaveKey('categoryId');

    $query = makePostIndexQueryRecorder();
    $repo->lastGet['when'][3][1]($query);

    expect($query->whereNull)->toBe(['category_id']);
    expect($query->whereIn)->toBe([]);
    expect($category->requestedIds)->toBe([]);
});

test('admin post index groups keyword search before uncategorized filter', function () {
    request()->replace([
        'keywords' => 'giới thiệu',
        'categoryId' => [0],
        'status' => 'all',
        'pageSize' => 20,
    ]);

    $repo = makePostRepositoryStub();
    $category = makePostCategoryRepositoryStub();

    $repo->index('post', $category);

    expect($repo->lastGet['filter'])->not->toHaveKey('keywords');
    expect($repo->lastGet['filter'])->not->toHaveKey('categoryId');

    $query = makePostIndexQueryRecorder();
    foreach ($repo->lastGet['when'] as $when) {
        if (is_array($when) && count($when) === 2 && $when[0]) {
            $when[1]($query);
        }
    }

    expect($query->orWhere)->toBe([]);
    expect($query->nested)->toHaveCount(1);
    expect($query->nested[0]->where)->toBe([
        ['name', 'like', '%giới thiệu%'],
    ]);
    expect($query->nested[0]->orWhere)->toBe([
        ['content', 'like', '%giới thiệu%'],
    ]);
    expect($query->whereNull)->toBe(['category_id']);
    expect($query->whereIn)->toBe([]);
    expect($category->requestedIds)->toBe([]);
});

test('post repository getLatestPosts builds query', function () {
    $original = getenv('SKIP_LANGUAGES_QUERY');
    putenv('SKIP_LANGUAGES_QUERY=true');
    cache()->flush();

    $repo = makePostRepositoryStub();

    try {
        $repo->getLatestPosts(4);

        expect($repo->lastGet['take'])->toBe(4);
        expect($repo->lastGet['where']['type'])->toBe('post');
        expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
        expect($repo->lastGet['with'])->toContain('images');
        expect($repo->lastGet['with'])->toContain('slugs:target_id,slug');
        expect($repo->lastGet['has'])->toContain('category');
    } finally {
        if ($original === false) {
            putenv('SKIP_LANGUAGES_QUERY');
        } else {
            putenv('SKIP_LANGUAGES_QUERY=' . $original);
        }
    }
});

test('post repository related posts require published primary category', function () {
    $original = getenv('SKIP_LANGUAGES_QUERY');
    putenv('SKIP_LANGUAGES_QUERY=true');
    cache()->flush();

    $repo = makePostRepositoryStub();

    try {
        $repo->getRelatedPosts(55, 7282, 6, 'post');

        expect($repo->lastGet['take'])->toBe(6);
        expect($repo->lastGet['where']['type'])->toBe('post');
        expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
        expect($repo->lastGet['has'])->toHaveKey('categories');
        expect($repo->lastGet['has'])->toHaveKey('category');
        expect($repo->lastGet['has'])->toContain('slugs');
        expect($repo->lastGet['with'])->toContain('category');

        $pivotCategoryQuery = new class {
            public array $where = [];
            public int $publishedCalls = 0;

            public function where(string $column, mixed $value): self
            {
                $this->where[] = [$column, $value];

                return $this;
            }

            public function published(): self
            {
                $this->publishedCalls++;

                return $this;
            }
        };

        $repo->lastGet['has']['categories']($pivotCategoryQuery);

        expect($pivotCategoryQuery->where)->toBe([['id', 55]]);
        expect($pivotCategoryQuery->publishedCalls)->toBe(1);

        $primaryCategoryQuery = new class {
            public int $publishedCalls = 0;

            public function published(): self
            {
                $this->publishedCalls++;

                return $this;
            }
        };

        $repo->lastGet['has']['category']($primaryCategoryQuery);

        expect($primaryCategoryQuery->publishedCalls)->toBe(1);
    } finally {
        if ($original === false) {
            putenv('SKIP_LANGUAGES_QUERY');
        } else {
            putenv('SKIP_LANGUAGES_QUERY=' . $original);
        }
    }
});

test('post repository latest cache moves to a fresh namespace after content invalidation', function () {
    withPostRepositoryCacheStore('array', function (): void {
        $repo = makePostRepositoryStub();

        expect($repo->getLatestPosts(4))->toBe(['call' => 1]);
        expect($repo->getLatestPosts(4))->toBe(['call' => 1]);
        expect($repo->getCalls)->toBe(1);

        app(ContentPostCacheInvalidationService::class)->invalidateSnapshots([], publicPostCacheSnapshot());

        expect($repo->getLatestPosts(4))->toBe(['call' => 2]);
        expect($repo->getCalls)->toBe(2);
    });
});

test('normal public post cache workflow refreshes across cache stores', function (string $store) {
    withPostRepositoryCacheStore($store, function (): void {
        $latestRepo = makePostRepositoryStub();
        expect($latestRepo->getLatestPosts(4))->toBe(['call' => 1]);
        expect($latestRepo->getLatestPosts(4))->toBe(['call' => 1]);
        app(ContentPostCacheInvalidationService::class)->invalidateSnapshots([], publicPostCacheSnapshot([
            'id' => 101,
            'uuid' => 'post-101',
            'slugs' => ['post-101'],
        ]));
        expect($latestRepo->getLatestPosts(4))->toBe(['call' => 2]);

        $featuredRepo = makePostRepositoryStub();
        expect($featuredRepo->getPostByTypeAndConfig('post', 'featured', 3))->toBe(['call' => 1]);
        expect($featuredRepo->getPostByTypeAndConfig('post', 'featured', 3))->toBe(['call' => 1]);
        app(ContentPostCacheInvalidationService::class)->invalidateSnapshots([], publicPostCacheSnapshot([
            'id' => 102,
            'uuid' => 'post-102',
            'slugs' => ['post-102'],
            'featured' => true,
        ]));
        expect($featuredRepo->getPostByTypeAndConfig('post', 'featured', 3))->toBe(['call' => 2]);

        $categoryRepo = makePostRepositoryStub();
        expect($categoryRepo->getPostsByCategoriesIds([5], 1, 12, 'post'))->toBe(['call' => 1]);
        expect($categoryRepo->getPostsByCategoriesIds([5], 1, 12, 'post'))->toBe(['call' => 1]);
        app(ContentPostCacheInvalidationService::class)->invalidateSnapshots([], publicPostCacheSnapshot([
            'id' => 103,
            'uuid' => 'post-103',
            'slugs' => ['post-103'],
            'category_ids' => [5],
            'category_scope_ids' => [2, 5],
        ]));
        expect($categoryRepo->getPostsByCategoriesIds([5], 1, 12, 'post'))->toBe(['call' => 2]);
    });
})->with(['array', 'file']);

test('post detail slug cache key refreshes across cache stores', function (string $store) {
    withPostRepositoryCacheStore($store, function (): void {
        $versions = app(ContentCacheVersionService::class);
        $invalidator = app(ContentPostCacheInvalidationService::class);
        $baseKey = CacheKeyHelper::postBySlug('post-100', 1);
        $scope = ContentPostCacheInvalidationService::slugScope('post-100', 1);

        $oldKey = $versions->versionedKey($baseKey, [$scope]);
        cache()->put($oldKey, ['name' => 'old'], 3600);

        expect(cache()->get($oldKey))->toBe(['name' => 'old']);

        $invalidator->invalidateSnapshots(
            publicPostCacheSnapshot(['slugs' => ['post-100']]),
            publicPostCacheSnapshot([
                'slugs' => ['post-100'],
                'content_hash' => 'new-content',
                'search_hash' => 'new-search',
            ])
        );

        $newKey = $versions->versionedKey($baseKey, [$scope]);

        expect($newKey)->not->toBe($oldKey);
        expect(cache()->get($newKey))->toBeNull();
    });
})->with(['array', 'file']);

test('post repository getPostListByType builds query', function () {
    $original = getenv('SKIP_LANGUAGES_QUERY');
    putenv('SKIP_LANGUAGES_QUERY=true');
    cache()->flush();

    $repo = makePostRepositoryStub();

    try {
        $repo->getPostListByType('article', 12, 2);

        expect($repo->lastGet['where']['type'])->toBe('article');
        expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
        expect($repo->lastGet['page'])->toBe(12);
        expect($repo->lastGet['current_page'])->toBe(2);
        expect($repo->lastGet['order']['published_at'])->toBe('desc');
        expect($repo->lastGet['with'])->toContain('images');
        expect($repo->lastGet['with'])->toContain('slugs:target_id,slug');
        expect($repo->lastGet['with'])->toHaveKey('category');
    } finally {
        if ($original === false) {
            putenv('SKIP_LANGUAGES_QUERY');
        } else {
            putenv('SKIP_LANGUAGES_QUERY=' . $original);
        }
    }
});

test('post repository getPostByTypeAndConfig builds query', function () {
    $original = getenv('SKIP_LANGUAGES_QUERY');
    putenv('SKIP_LANGUAGES_QUERY=true');
    cache()->flush();

    $repo = makePostRepositoryStub();

    try {
        $repo->getPostByTypeAndConfig('post', 'featured', 3);

        expect($repo->lastGet['take'])->toBe(3);
        expect($repo->lastGet['where']['type'])->toBe('post');
        expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
        expect($repo->lastGet['where']['config->featured'])->toBeTrue();
        expect($repo->lastGet['with'])->toContain('images');
        expect($repo->lastGet['with'])->toContain('slugs:target_id,slug');
    } finally {
        if ($original === false) {
            putenv('SKIP_LANGUAGES_QUERY');
        } else {
            putenv('SKIP_LANGUAGES_QUERY=' . $original);
        }
    }
});

it('generates valid SQL for multi-word keyword search', function (): void {
    withPostRepositoryCacheStore('array', function (): void {
        $repository = app(\Cms\Content\Repositories\PostRepository::class);

        $queries = \Illuminate\Support\Facades\DB::pretend(function () use ($repository): void {
            try {
                $repository->advancedSearch([
                    'type' => 'post',
                    'keyword' => 'Toyota Innova Cross',
                    'page' => 1,
                    'pageSize' => 4,
                ], true);
            } catch (\Throwable) {
                // Pretend-mode count() returns no rows; SQL capture is all we need.
            }
        });

        $sql = collect($queries)->pluck('query')->implode(' | ');
        $bindings = collect($queries)->flatMap(fn ($q) => $q['bindings'])->values();

        // Regression: the published_at closure must never become a `0 = (subquery)` clause.
        expect($sql)->not->toContain('"0" =')
            ->not->toContain('`0` =')
            // OrderedKeywordSearch tiers: full phrase + ordered-token wildcards.
            ->and($bindings->all())->toContain('%toyota innova cross%')
            ->toContain('%toyota%innova%cross%')
            // … and no array is ever bound as a single LIKE value.
            ->and($bindings->filter(fn ($b) => is_array($b))->all())->toBeEmpty();
    });
});
