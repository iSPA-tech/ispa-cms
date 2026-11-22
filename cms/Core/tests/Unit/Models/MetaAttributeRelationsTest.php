<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Models\MetaAttribute;

test('MetaAttribute option relations', function () {
    $model = new MetaAttribute(['type' => 'string']);

    expect($model->bigNumValues())->toBeInstanceOf(BelongsToMany::class);
    expect($model->tinyNumValues())->toBeInstanceOf(BelongsToMany::class);
    expect($model->stringValues())->toBeInstanceOf(BelongsToMany::class);
    expect($model->textValues())->toBeInstanceOf(BelongsToMany::class);
    expect($model->dateValues())->toBeInstanceOf(BelongsToMany::class);
    expect($model->booleanValues())->toBeInstanceOf(BelongsToMany::class);
});

