<?php

uses(\Tests\TestCase::class);

it('content models expose relationships', function (string $modelClass, string $relationName, string $expectedRelation) {
    $model = new $modelClass();

    expect($model->{$relationName}())->toBeInstanceOf($expectedRelation);
})->with([
    [\Cms\Content\Models\Content::class, 'contentStatus', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Content::class, 'author', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Content::class, 'target', \Illuminate\Database\Eloquent\Relations\MorphTo::class],
    [\Cms\Content\Models\Content::class, 'language', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Content::class, 'audios', \Illuminate\Database\Eloquent\Relations\BelongsToMany::class],
    [\Cms\Content\Models\Post::class, 'tags', \Illuminate\Database\Eloquent\Relations\BelongsToMany::class],
    [\Cms\Content\Models\Post::class, 'uploaded', \Illuminate\Database\Eloquent\Relations\BelongsToMany::class],
    [\Cms\Content\Models\Post::class, 'language', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Post::class, 'category', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Post::class, 'categories', \Illuminate\Database\Eloquent\Relations\BelongsToMany::class],
    [\Cms\Content\Models\Post::class, 'editableCategories', \Illuminate\Database\Eloquent\Relations\BelongsToMany::class],
    [\Cms\Content\Models\Post::class, 'target', \Illuminate\Database\Eloquent\Relations\HasMany::class],
    [\Cms\Content\Models\Post::class, 'attachs', \Illuminate\Database\Eloquent\Relations\MorphMany::class],
    [\Cms\Content\Models\Post::class, 'author', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Content\Models\Post::class, 'children', \Illuminate\Database\Eloquent\Relations\HasMany::class],
    [\Cms\Content\Models\Post::class, 'parent', \Illuminate\Database\Eloquent\Relations\BelongsTo::class],
    [\Cms\Core\Models\Tag::class, 'data', \Illuminate\Database\Eloquent\Relations\HasMany::class],
    [\Cms\Core\Models\TagData::class, 'target', \Illuminate\Database\Eloquent\Relations\MorphTo::class],
    [\Cms\Core\Models\TagData::class, 'tags', \Illuminate\Database\Eloquent\Relations\HasMany::class],
    [\Cms\Content\Models\BookMark::class, 'target', \Illuminate\Database\Eloquent\Relations\MorphTo::class],
]);
