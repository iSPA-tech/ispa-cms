<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

test('EmailTemplate casts and language relation', function () {
    $model = new EmailTemplate();

    expect($model->getTable())->toBe(CoreTableConstant::EMAIL_TEMPLATES);
    expect($model->getFillable())->toContain('name', 'group', 'key_name', 'content');

    expect($model->hasCast('language_id', 'integer'))->toBeTrue();
    expect($model->hasCast('user_id', 'integer'))->toBeTrue();
    expect($model->hasCast('variables', 'array'))->toBeTrue();
    expect($model->hasCast('sample_data', 'array'))->toBeTrue();

    expect($model->language())->toBeInstanceOf(BelongsTo::class);
});
