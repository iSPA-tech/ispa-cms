<?php

uses(\Tests\TestCase::class);

use Cms\Content\Repositories\BookmarkRepository;
use Cms\Content\Models\Post;

function makeBookmarkRepositoryStub(): BookmarkRepository
{
    return new class extends BookmarkRepository {
        public array $lastGet = [];

        public function __construct() {}

        public function get(array $params): mixed
        {
            $this->lastGet = $params;

            return ['ok'];
        }
    };
}

test('bookmark repository getBookmarkListByTarget builds query', function () {
    cache()->flush();

    $repo = makeBookmarkRepositoryStub();
    $repo->getBookmarkListByTarget('post', 2, 15, 9);

    expect($repo->lastGet['where']['target_type'])->toBe(Post::class);
    expect($repo->lastGet['where']['user_id'])->toBe(9);
    expect($repo->lastGet['with'])->toHaveKey('target');
    expect($repo->lastGet['page'])->toBe(15);
    expect($repo->lastGet['latest'])->toBeTrue();
});

test('bookmark repository getLatestBookmark builds query', function () {
    cache()->flush();

    $repo = makeBookmarkRepositoryStub();
    $repo->getLatestBookmark('post', 9, 4);

    expect($repo->lastGet['where']['target_type'])->toBe(Post::class);
    expect($repo->lastGet['where']['user_id'])->toBe(9);
    expect($repo->lastGet['hasMorph']['target'])->toBe([Post::class]);
    expect($repo->lastGet['order']['created_at'])->toBe('desc');
    expect($repo->lastGet['take'])->toBe(4);
});

test('bookmark repository getBookmarkByTarget builds query', function () {
    cache()->flush();

    $repo = makeBookmarkRepositoryStub();
    $repo->getBookmarkByTarget('post', 9);

    expect($repo->lastGet['where']['target_type'])->toBe(Post::class);
    expect($repo->lastGet['where']['user_id'])->toBe(9);
    expect($repo->lastGet['with'])->toHaveKey('target');
});

test('bookmark repository accepts post class aliases', function (string $type) {
    cache()->flush();

    $repo = makeBookmarkRepositoryStub();
    $repo->getBookmarkByTarget($type, 9);

    expect($repo->lastGet['where']['target_type'])->toBe(Post::class);
})->with([
    'Post',
    'post',
    Post::class,
    'App\\Models\\Post',
    'App\\Models\\Articles\\Post',
]);
