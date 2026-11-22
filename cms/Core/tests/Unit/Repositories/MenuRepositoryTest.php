<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Repositories\MenuRepository;

test('MenuRepository getMenuList merges base filters', function () {
    $repo = new class extends MenuRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };

    $repo->getMenuList(['group_key' => 'main'], 2);

    expect($repo->lastGet['where'])->toMatchArray([
        'status' => StatusEnum::PUBLISHED->value,
        'language_id' => 2,
        'group_key' => 'main',
    ]);
    expect($repo->lastGet['order']['id'])->toBe('DESC');
    expect($repo->lastGet['with'])->toHaveKey('items');
});

test('MenuRepository getMenuDetailByGroupName uses cached query', function () {
    cache()->flush();

    $cacheHelper = new class extends CacheHelper {
        public array $stored = [];

        public function store(string $cacheKey, string $title, int $ttl = 0): void
        {
            $this->stored = [$cacheKey, $title, $ttl];
        }
    };

    CacheHelper::swap($cacheHelper);

    $repo = new class extends MenuRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return (object)['id' => 1];
        }
    };

    $repo->getMenuDetailByGroupName('footer', true);

    expect($repo->lastGet['take'])->toBe(1);
    expect($repo->lastGet['where']['group_key'])->toBe('footer');
    expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
    expect($repo->lastGet['with'])->toHaveKey('items');
    expect($cacheHelper->stored[1])->toContain('Menu footer');
});

test('MenuRepository getMenuByGroupKey uses cache wrapper and builds query', function () {
    CMSCache::shouldReceive('rememberWithTracking')
        ->once()
        ->andReturnUsing(function ($key, $callback, ...$rest) {
            return $callback();
        });

    $repo = new class extends MenuRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return (object)['id' => 1];
        }
    };

    $repo->getMenuByGroupKey('main');

    expect($repo->lastGet['where']['group_key'])->toBe('main');
    expect($repo->lastGet['where']['status'])->toBe(StatusEnum::PUBLISHED->value);
    expect($repo->lastGet['where']['language_id'])->toBe(CacheKeyHelper::getLangId());
    expect($repo->lastGet['with'])->toHaveKey('items');
});
