<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Cms\Core\Models\AuditLog;

test('AuditLog relations return relation objects', function () {
    $model = new AuditLog();
    expect($model->target())->toBeInstanceOf(MorphTo::class);
    expect($model->creator())->toBeInstanceOf(BelongsTo::class);
});
