<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Upload;
use Cms\Core\Repositories\CategoryTargetRepository;
use Cms\Content\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

test('CategoryTargetRepository returns null when category ids are empty', function () {
    $repo = new CategoryTargetRepository();

    $result = $repo->getPostsByCategoryIds([]);

    expect($result)->toBeNull();
});

test('CategoryTargetRepository getUploadedByType flattens targets', function () {
    $request = Request::create('/', 'GET', ['page' => 2, 'limit' => 5]);
    $this->app->instance('request', $request);

    $repo = new class extends CategoryTargetRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return collect([
                (object)['target' => (object)['id' => 99]],
            ]);
        }
    };

    $result = $repo->getUploadedByType('image', [
        'IN' => [1, 2],
        'id' => 5,
        'limit' => 5,
    ]);

    expect($repo->lastGet['where'])->toBe(['target_type' => Upload::class]);
    expect($repo->lastGet['when'][0][0])->toBeTrue();
    expect($repo->lastGet['when'][1][0])->toBeTrue();
    expect($repo->lastGet['with'])->toHaveKey('target');
    expect($result[0]->id)->toBe(99);
});

test('CategoryTargetRepository getPostsByCategoryIds maps targets', function () {
    $request = Request::create('/', 'GET', ['limit' => 7]);
    $this->app->instance('request', $request);

    $repo = new class extends CategoryTargetRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return new EloquentCollection([
                (object)['target_id' => 10, 'target' => (object)['id' => 10]],
            ]);
        }
    };

    $result = $repo->getPostsByCategoryIds([1, 2], 12);

    expect($repo->lastGet['where']['target_type'])->toBe(\Cms\Content\Models\Post::class);
    expect($repo->lastGet['where'][0])->toBe(['category_id', 'IN', [1, 2]]);
    expect($result)->toBeInstanceOf(EloquentCollection::class);
    expect($result->first()->id)->toBe(10);
});

test('CategoryTargetRepository getRelatedPosts maps targets', function () {
    $repo = new class extends CategoryTargetRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return collect([
                (object)['target' => (object)['id' => 55]],
            ]);
        }
    };

    $result = $repo->getRelatedPosts(8, 21, 4, 'post');

    expect($repo->lastGet['where']['category_id'])->toBe(8);
    expect($repo->lastGet['where'][0])->toBe(['target_id', '!=', 21]);
    expect($repo->lastGet['where']['target_type'])->toBe(Post::class);
    expect($repo->lastGet['take'])->toBe(4);
    expect($repo->lastGet['order']['target_id'])->toBe('DESC');
    expect($repo->lastGet['has'])->toHaveKey('target');
    expect($result[0]->id)->toBe(55);
});
