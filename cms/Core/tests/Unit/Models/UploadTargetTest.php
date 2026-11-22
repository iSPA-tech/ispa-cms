<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Cms\Core\Models\UploadTarget;

test('UploadTarget upload relation definition', function () {
    $model = new UploadTarget();
    $relation = $model->upload();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getForeignKeyName())->toBe('upload_id');
});
