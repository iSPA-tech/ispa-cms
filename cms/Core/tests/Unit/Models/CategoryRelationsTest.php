<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Models\Category;

test('Category parent and children relations', function () {
    $model = new Category();
    expect($model->parent())->toBeInstanceOf(BelongsTo::class);
    expect($model->children())->toBeInstanceOf(HasMany::class);
    expect($model->public_children())->toBeInstanceOf(HasMany::class);
});

test('Category belongsToMany relations', function () {
    $model = new Category();
    expect($model->galleries())->toBeInstanceOf(BelongsToMany::class);
    expect($model->documents())->toBeInstanceOf(BelongsToMany::class);
    expect($model->posts())->toBeInstanceOf(BelongsToMany::class);
    expect($model->uploaded())->toBeInstanceOf(BelongsToMany::class);
});
