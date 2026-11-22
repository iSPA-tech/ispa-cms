<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\UserSetting;

test('UserSetting table, fillable, and casts', function () {
    $model = new UserSetting();

    expect($model->getTable())->toBe(CoreTableConstant::USERS_SETTINGS);
    expect($model->getFillable())->toContain('user_id', 'setting_group', 'setting_key', 'setting_value');

    expect($model->hasCast('user_id', 'integer'))->toBeTrue();
    expect($model->hasCast('setting_value', 'array'))->toBeTrue();
});
