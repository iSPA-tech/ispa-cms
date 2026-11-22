<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\EnumerateType;

test('EnumerateType fillable', function () {
    $m = new EnumerateType();
    expect($m->getFillable())->toContain('name', 'key_name');
});

