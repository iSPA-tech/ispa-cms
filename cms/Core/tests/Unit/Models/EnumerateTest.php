<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Enumerate;

test('Enumerate casts', function () {
    $m = new Enumerate();
    expect($m->hasCast('is_filter', 'boolean'))->toBeTrue();
    expect($m->hasCast('status', 'boolean'))->toBeTrue();
    expect($m->hasCast('type_id', 'integer'))->toBeTrue();
});

