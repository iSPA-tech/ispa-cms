<?php

use Cms\Core\Models\Category;

test('Category::type_name mapping', function () {
    $c0 = new Category(['type' => 0]);
    $c1 = new Category(['type' => 1]);
    $c2 = new Category(['type' => 2]);
    $c4 = new Category(['type' => 4]);
    $c5 = new Category(['type' => 5]);
    $cx = new Category(['type' => 999]);

    expect($c0->type_name)->toBe('news');
    expect($c1->type_name)->toBe('place');
    expect($c2->type_name)->toBe('gallery');
    expect($c4->type_name)->toBe('product');
    expect($c5->type_name)->toBe('file');
    expect($cx->type_name)->toBe('unknown');
});
