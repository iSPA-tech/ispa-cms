<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Email;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

test('Email casts and contact relation', function () {
    $model = new Email();

    expect($model->getTable())->toBe(CoreTableConstant::EMAILS);
    expect($model->getFillable())->toContain('uuid', 'subject', 'email', 'content', 'type');

    expect($model->hasCast('raw_data', 'json'))->toBeTrue();
    expect($model->hasCast('contact_id', 'integer'))->toBeTrue();
    expect($model->hasCast('user_id', 'integer'))->toBeTrue();
    expect($model->hasCast('reply_id', 'integer'))->toBeTrue();

    expect($model->contact())->toBeInstanceOf(BelongsTo::class);
});
