<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Cms\Core\Models\MetaData;

test('MetaData relations', function () {
    $model = new MetaData();
    expect($model->target())->toBeInstanceOf(MorphTo::class);
    expect($model->class())->toBeInstanceOf(BelongsTo::class);
    expect($model->attribute())->toBeInstanceOf(BelongsTo::class);
    expect($model->value())->toBeInstanceOf(MorphTo::class);
});

