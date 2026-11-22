<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\MenuItem;

test('MenuItem have_children accessor defaults to false', function () {
    $item = new MenuItem();
    expect($item->have_children)->toBeFalse();
});

