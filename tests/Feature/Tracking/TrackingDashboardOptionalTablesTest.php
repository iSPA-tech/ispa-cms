<?php

use App\Constants\Tables;
use App\Http\Controllers\V2\Admin\TrackController;
use App\Repositories\Interfaces\CommentInterface;
use App\Repositories\Interfaces\ContactInterface;
use App\Repositories\Interfaces\TrackInterface;
use App\Services\Tracking\TrackingAnalyticsService;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('dashboard returns zero totals when optional content tables are absent', function (): void {
    TrackController::forgetSchemaMemo();
    cache()->flush();

    foreach ([Tables::PLACE_ITEMS, Tables::POSTS, Tables::CONTACTS, Tables::COMMENTS] as $table) {
        Schema::dropIfExists($table);
    }

    $analytics = Mockery::mock(TrackingAnalyticsService::class);
    $analytics->shouldReceive('siteTotals')->once()->andReturn([
        'total_scan' => 0,
        'total_view' => 0,
        'total_visit' => 0,
        'data_quality' => [],
        'total_scope' => [],
    ]);

    $controller = new TrackController(
        mock(TrackInterface::class),
        mock(PostInterface::class),
        mock(ContactInterface::class),
        mock(CommentInterface::class),
        $analytics,
    );

    $payload = $controller->dashboard()->toResponse(request())->getData(true);

    expect($payload['data']['totalItems'])->toBe(0)
        ->and($payload['data']['totalNews'])->toBe(0)
        ->and($payload['data']['totalContact'])->toBe(0)
        ->and($payload['data']['comments'])->toBe([
            'total' => 0,
            'new' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'rejected' => 0,
        ]);
});
