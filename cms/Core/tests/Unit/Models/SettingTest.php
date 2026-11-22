<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Cms\Core\Models\Setting;

test('Setting language relation', function () {
    $model = new Setting();
    expect($model->language())->toBeInstanceOf(BelongsTo::class);
});
