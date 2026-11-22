<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Category;

test('Category type_name accessor maps numeric type', function () {
    $c = new Category();
    $c->type = 0; expect($c->type_name)->toBe('news');
    $c->type = 1; expect($c->type_name)->toBe('place');
    $c->type = 2; expect($c->type_name)->toBe('gallery');
    $c->type = 3; expect($c->type_name)->toBe('event');
    $c->type = 4; expect($c->type_name)->toBe('product');
    $c->type = 5; expect($c->type_name)->toBe('file');
    $c->type = 6; expect($c->type_name)->toBe('role');
});
