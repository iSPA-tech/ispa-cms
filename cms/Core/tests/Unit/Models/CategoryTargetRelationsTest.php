<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Cms\Core\Models\CategoryTarget;

test('CategoryTarget morph relations', function () {
    $model = new CategoryTarget();
    expect($model->post())->toBeInstanceOf(MorphTo::class);
    expect($model->target())->toBeInstanceOf(MorphTo::class);
});

