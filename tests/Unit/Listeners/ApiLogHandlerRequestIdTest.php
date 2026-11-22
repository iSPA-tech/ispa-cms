<?php

use App\Constants\Tables;
use App\Listeners\ApiLogHandler;
use App\Models\Tracking\ApiLog;
use App\Repositories\Interfaces\ApiLogInterface;
use Cms\Core\Services\Security\LogRedactor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Context::flush();
    if (!Schema::hasTable(Tables::API_LOGS)) {
        Schema::create(Tables::API_LOGS, function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
});

test('stores the shared Context request id', function () {
    Context::add('request_id', 'shared-id-1');
    $stored = null;
    $repository = Mockery::mock(ApiLogInterface::class);
    $repository->shouldReceive('reset')->andReturnSelf();
    $repository->shouldReceive('create')->once()->andReturnUsing(function (array $attributes) use (&$stored) {
        $stored = $attributes;

        return new ApiLog();
    });
    $request = Request::create('/api/v2/ping', 'GET');
    $request->attributes->set('enable_log', true);
    $response = new JsonResponse(['data' => []]);

    (new ApiLogHandler($repository, new LogRedactor()))->handle(new RequestHandled($request, $response));

    expect($stored['request_id'])->toBe('shared-id-1')->and(Context::get('request_id'))->toBe('shared-id-1');
});
