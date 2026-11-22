<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\WidgetInput;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

test('WidgetInput casts and widget relation', function () {
    $model = new WidgetInput();

    expect($model->getTable())->toBe(CoreTableConstant::WIDGET_INPUT);
    expect($model->getFillable())->toContain('widget_id', 'name', 'type', 'default_value', 'options');

    expect($model->hasCast('widget_id', 'integer'))->toBeTrue();
    expect($model->hasCast('options', 'json'))->toBeTrue();

    expect($model->widget())->toBeInstanceOf(BelongsTo::class);
});
