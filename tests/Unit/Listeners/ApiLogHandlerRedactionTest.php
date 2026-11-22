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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    if (!Schema::hasTable(Tables::API_LOGS)) {
        Schema::create(Tables::API_LOGS, function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
});

test('stores a redacted copy of the request and response and leaves the client response unchanged', function () {
    $stored = null;
    $repository = Mockery::mock(ApiLogInterface::class);
    $repository->shouldReceive('reset')->andReturnSelf();
    $repository->shouldReceive('create')->once()->andReturnUsing(function (array $attributes) use (&$stored) {
        $stored = $attributes;

        return new ApiLog();
    });

    $request = Request::create('/api/v2/orders?page=2&email=jane%40example.com', 'POST', [
        'password' => 'hunter2',
        'customer' => ['name' => 'Jane Doe', 'phone' => '0912345678'],
        'note' => 'deliver after 5pm',
    ]);
    $request->attributes->set('enable_log', true);
    $response = new JsonResponse(['data' => ['email' => 'jane@example.com', 'status' => 'ok', 'token' => 'tok-1']]);

    (new ApiLogHandler($repository, new LogRedactor()))->handle(new RequestHandled($request, $response));

    expect($stored['data_request']['password'])->toBe(LogRedactor::MASK)
        ->and($stored['data_request']['customer'])->toBe(['name' => LogRedactor::MASK, 'phone' => '******5678'])
        ->and($stored['data_request']['note'])->toBe('deliver after 5pm')
        ->and($stored['data_request']['email'])->toBe('ja***@***.com')
        ->and($stored['data_request']['page'])->toBe('2')
        ->and($stored['data_response']['data'])->toBe(['email' => 'ja***@***.com', 'status' => 'ok', 'token' => LogRedactor::MASK])
        ->and($stored['url'])->toContain('page=2')
        ->and($stored['url'])->not->toContain('jane')
        ->and($stored['method'])->toBe('POST');

    $clientBody = json_decode($response->getContent(), true);

    expect($clientBody['data'])->toBe(['email' => 'jane@example.com', 'status' => 'ok', 'token' => 'tok-1'])
        ->and($clientBody['request_id'])->toBe($stored['request_id']);
});
