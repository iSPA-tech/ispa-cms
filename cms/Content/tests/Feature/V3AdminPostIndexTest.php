<?php

declare(strict_types=1);

use Cms\Content\Http\Controllers\V3\Admin\PostController;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

uses(\Tests\TestCase::class);

test('v3 admin post index builds its response with an existing resource collection', function () {
    $repository = Mockery::mock(PostInterface::class);
    $repository->shouldReceive('get')->once()->andReturn(new LengthAwarePaginator([], 0, 15, 1));

    $response = (new PostController($repository))->index(Request::create('/api/v3/admin/post', 'GET'));
    $payload = json_decode(json_encode($response->toResponse(Request::create('/api/v3/admin/post'))->getData(true)), true);

    expect($payload['data'])->toHaveKeys(['data', 'total', 'perPage', 'currentPage', 'totalPages'])
        ->and($payload['data']['total'])->toBe(0);
});
