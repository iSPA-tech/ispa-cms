<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\Gate;
use Cms\Core\Models\Category;
use Cms\Core\Models\Widget;
use Cms\Core\Policies\CategoryPolicy;
use Cms\Core\Policies\WidgetPolicy;

test('category policy is registered', function () {
    $policy = Gate::getPolicyFor(Category::class);
    expect($policy)->toBeInstanceOf(CategoryPolicy::class);
});

test('widget policy is registered for model authorization', function () {
    $policy = Gate::getPolicyFor(Widget::class);
    expect($policy)->toBeInstanceOf(WidgetPolicy::class);
});
