<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

test('Storage relations', function () {
    $model = new Storage();

    expect($model->getTable())->toBe(CoreTableConstant::STORAGES);
    expect($model->author())->toBeInstanceOf(BelongsTo::class);
    expect($model->storage_type())->toBeInstanceOf(BelongsTo::class);
    expect($model->children())->toBeInstanceOf(HasMany::class);
    expect($model->parent())->toBeInstanceOf(BelongsTo::class);
    expect($model->logs())->toBeInstanceOf(MorphMany::class);
    expect($model->creator())->toBeInstanceOf(BelongsTo::class);
});

test('Storage casts', function () {
    $model = new Storage();

    expect($model->hasCast('enumerate_id', 'integer'))->toBeTrue();
    expect($model->hasCast('established_by', 'integer'))->toBeTrue();
    expect($model->hasCast('user_id', 'integer'))->toBeTrue();
    expect($model->hasCast('parent_id', 'integer'))->toBeTrue();
    expect($model->hasCast('place_id', 'integer'))->toBeTrue();
});
