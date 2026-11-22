<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Cms\Core\Models\MetaClass;

test('MetaClass relations', function () {
    $model = new MetaClass();
    expect($model->group())->toBeInstanceOf(HasMany::class);
    expect($model->options())->toBeInstanceOf(BelongsToMany::class);
});

