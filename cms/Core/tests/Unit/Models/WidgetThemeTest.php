<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

test('WidgetTheme casts and widget relation', function () {
    $model = new WidgetTheme();

    expect($model->getTable())->toBe(CoreTableConstant::WIDGET_THEME);
    expect($model->getFillable())->toContain(
        'widget_id',
        'name',
        'content',
        'css',
        'query',
        'project_name'
    );

    expect($model->hasCast('widget_id', 'integer'))->toBeTrue();
    expect($model->hasCast('query', 'json'))->toBeTrue();
    expect($model->hasCast('project_name', 'json'))->toBeTrue();

    expect($model->widget())->toBeInstanceOf(BelongsTo::class);
});
