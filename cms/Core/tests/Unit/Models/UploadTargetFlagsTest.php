<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\UploadTarget;

test('UploadTarget disables timestamps', function () {
    $m = new UploadTarget();
    expect($m->timestamps)->toBeFalse();
});

