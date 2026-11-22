<?php

uses(\Tests\TestCase::class);

it('content repositories map to expected models', function (string $repositoryClass, string $modelClass) {
    $repo = new $repositoryClass();

    expect($repo->getModelClass())->toBe($modelClass);
    $inner = $repo->model();
    // The tag repository holds a post-scoped builder rather than a bare model.
    expect($inner instanceof \Illuminate\Database\Eloquent\Builder ? $inner->getModel() : $inner)->toBeInstanceOf($modelClass);
})->with([
    [\Cms\Content\Repositories\BookmarkRepository::class, \Cms\Content\Models\BookMark::class],
    [\Cms\Content\Repositories\ContentRepository::class, \Cms\Content\Models\Content::class],
    [\Cms\Content\Repositories\PostRepository::class, \Cms\Content\Models\Post::class],
    [\Cms\Content\Repositories\TagRepository::class, \Cms\Core\Models\Tag::class],
]);
