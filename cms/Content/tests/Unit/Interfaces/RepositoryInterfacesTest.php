<?php

uses(\Tests\TestCase::class);

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

it('content repository interfaces extend base repository interface', function (string $interface) {
    $ref = new ReflectionClass($interface);

    expect($ref->implementsInterface(BaseRepositoryInterface::class))->toBeTrue();
})->with([
    [\Cms\Content\Interfaces\BookmarkInterface::class],
    [\Cms\Content\Interfaces\ContentInterface::class],
    [\Cms\Content\Interfaces\PostInterface::class],
    [\Cms\Content\Interfaces\TagInterface::class],
]);
