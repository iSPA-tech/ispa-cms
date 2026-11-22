<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Field;
use Illuminate\Database\Eloquent\Relations\HasMany;

test('Field casts and items relation', function () {
    $model = new Field();

    expect($model->getTable())->toBe(CoreTableConstant::FIELDS);
    expect($model->getFillable())->toContain('form_id', 'name', 'custom', 'styles', 'status');

    expect($model->hasCast('custom', 'json'))->toBeTrue();
    expect($model->hasCast('expires_from', 'datetime'))->toBeTrue();
    expect($model->hasCast('expires_to', 'datetime'))->toBeTrue();

    expect($model->items())->toBeInstanceOf(HasMany::class);
});
