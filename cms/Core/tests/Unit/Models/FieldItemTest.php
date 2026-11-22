<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\FieldItem;

test('FieldItem table, fillable, and casts', function () {
    $model = new FieldItem();

    expect($model->getTable())->toBe(CoreTableConstant::FIELD_ITEMS);
    expect($model->getFillable())->toContain(
        'name',
        'field_id',
        'field_name',
        'type',
        'description',
        'options',
        'custom',
        'priority',
        'name_trans_key',
        'phd_trans_key',
        'status'
    );

    expect($model->hasCast('field_id', 'integer'))->toBeTrue();
    expect($model->hasCast('priority', 'integer'))->toBeTrue();
    expect($model->hasCast('status', 'boolean'))->toBeTrue();
    expect($model->hasCast('custom', 'json'))->toBeTrue();
    expect($model->hasCast('options', 'json'))->toBeTrue();
});
