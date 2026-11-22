<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Cms\Core\Models\Language;

test('Language status relation', function () {
    $model = new Language(['status' => 'published']);
    expect($model->status())->toBeInstanceOf(BelongsTo::class);
});

test('Language route locale fields trim legacy char padding', function () {
    $model = new Language([
        'path' => 'vi        ',
        'path_alias' => 'vi-VN     ',
        'code' => 'VN        ',
        'currency' => 'đ         ',
    ]);

    expect($model->path)->toBe('vi')
        ->and($model->path_alias)->toBe('vi-VN')
        ->and($model->code)->toBe('VN')
        ->and($model->currency)->toBe('đ');
});
