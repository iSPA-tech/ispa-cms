<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Cms\Core\Models\Menu;

test('Menu relations', function () {
    $model = new Menu();
    expect($model->items())->toBeInstanceOf(HasMany::class);
    expect($model->language())->toBeInstanceOf(BelongsTo::class);
});

