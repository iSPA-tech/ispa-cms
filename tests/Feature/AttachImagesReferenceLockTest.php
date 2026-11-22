<?php

declare(strict_types=1);

use App\Services\Upload\AttachAndDetachImages;
use Tests\TestCase;

uses(TestCase::class);

/*
 * whether attaching the images of a content block locks their uploads and lets a failed attach throw is a
 * setting (`ispa-cms.upload.lock_references`, off by default) that a project turns on; the open code names no project.
 * The attach fails here on purpose (the upload tables do not exist in the test database), so the difference is whether the failure throws.
 */

function attachContent(): object
{
    return (object) ['id' => 1, 'content' => [['type' => 'image', 'value' => [['id' => 11], ['id' => 12]]]]];
}

test('with the lock on, a failed attach throws', function () {
    config(['ispa-cms.upload.lock_references' => true]);
    expect(fn () => AttachAndDetachImages::attachImageInsideContent(attachContent()))->toThrow(Exception::class);
});

test('with the lock off, a failed attach is only logged', function () {
    config(['ispa-cms.upload.lock_references' => false]);
    AttachAndDetachImages::attachImageInsideContent(attachContent());

    expect(true)->toBeTrue();
});

test('the lock is off unless a project turns it on', function () {
    expect(config('ispa-cms.upload.lock_references'))->toBeFalse();
});
