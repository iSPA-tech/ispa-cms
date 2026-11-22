<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Widget;
use Illuminate\Database\Eloquent\Relations\HasMany;

test('Widget relations and table', function () {
    $model = new Widget();

    expect($model->getTable())->toBe(CoreTableConstant::WIDGET);
    expect($model->getFillable())->toContain('name', 'description', 'js');

    expect($model->template())->toBeInstanceOf(HasMany::class);
    expect($model->inputs())->toBeInstanceOf(HasMany::class);
});
