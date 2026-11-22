<?php

uses(\Tests\TestCase::class);

use App\Http\Controllers\Website\AjaxController;
use Cms\Content\Models\Post;

function resolveAjaxControllerContentTargetType(string $type): string
{
    $controller = (new ReflectionClass(AjaxController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($controller, 'resolveContentTargetType');
    $method->setAccessible(true);

    return $method->invoke($controller, $type);
}

test('ajax controller resolves content post target aliases', function (string $type) {
    expect(resolveAjaxControllerContentTargetType($type))->toBe(Post::class);
})->with([
    'Post',
    'post',
    Post::class,
    'App\\Models\\Post',
    'App\\Models\\Articles\\Post',
]);

