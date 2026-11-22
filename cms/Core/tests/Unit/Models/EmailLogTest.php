<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\EmailLog;
use Illuminate\Database\Eloquent\Relations\MorphTo;

test('EmailLog casts and target relation', function () {
    $model = new EmailLog();

    expect($model->getTable())->toBe(CoreTableConstant::EMAIL_LOGS);
    expect($model->getFillable())->toContain('type', 'subject', 'to', 'content', 'email_id');

    expect($model->hasCast('content', 'json'))->toBeTrue();
    expect($model->hasCast('view', 'integer'))->toBeTrue();
    expect($model->hasCast('email_id', 'integer'))->toBeTrue();
    expect($model->hasCast('user_id', 'integer'))->toBeTrue();
    expect($model->hasCast('target_id', 'integer'))->toBeTrue();

    expect($model->target())->toBeInstanceOf(MorphTo::class);
});
