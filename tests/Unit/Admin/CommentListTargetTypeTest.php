<?php

uses(\Tests\TestCase::class);

use App\Http\Controllers\V2\Admin\CommentController;
use App\Http\Controllers\Website\AjaxController;
use Cms\Content\Models\Post;

/**
 * Invoke a protected/private single-string method on a controller built without its constructor.
 */
function invokeCommentTargetResolver(string $class, string $method, string $type): string
{
    $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, $type);
}

test('the admin post comment list filters on the target type the public comment form stores', function () {
    // base-admin's post-comment page sends type=Post; the public form posts type="Cms\Content\Models\Post".
    $listed = invokeCommentTargetResolver(CommentController::class, 'targetType', 'Post');
    $stored = invokeCommentTargetResolver(AjaxController::class, 'resolveContentTargetType', Post::class);

    expect($listed)->toBe(Post::class)->and($listed)->toBe($stored);
});

test('other comment list types keep their App\\Models alias', function () {
    expect(invokeCommentTargetResolver(CommentController::class, 'targetType', 'Item'))->toBe('App\\Models\\Item');
});
