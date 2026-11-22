<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Cms\Core\Models\MenuItem;

test('MenuItem relations', function () {
    $model = new MenuItem();
    expect($model->menu())->toBeInstanceOf(BelongsTo::class);
    expect($model->items())->toBeInstanceOf(HasMany::class);
    expect($model->target())->toBeInstanceOf(MorphTo::class);
});

