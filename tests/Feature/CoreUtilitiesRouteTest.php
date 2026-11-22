<?php

namespace Tests\Feature;

use App\Repositories\Interfaces\CalendarInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mockery;
use ReflectionMethod;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Cms\Core\Http\Controllers\V2\Admin\UtilitiesController;

class CoreUtilitiesRouteTest extends TestCase
{
    public function test_calendar_utility_routes_use_core_admin_controller(): void
    {
        $listDaysRoute = $this->findGetRoute('api/v2/admin/utilities/list-days');
        $componentsRoute = $this->findGetRoute('api/v2/admin/utilities/components');

        $this->assertSame(UtilitiesController::class . '@listDays', $listDaysRoute?->getActionName());
        $this->assertSame(UtilitiesController::class . '@listComponents', $componentsRoute?->getActionName());

        $this->assertSame(
            0,
            (new ReflectionMethod(UtilitiesController::class, 'listDays'))->getNumberOfRequiredParameters()
        );
        $this->assertSame(
            0,
            (new ReflectionMethod(UtilitiesController::class, 'listComponents'))->getNumberOfRequiredParameters()
        );
    }

    public function test_list_days_accepts_string_month_offset_from_query(): void
    {
        Carbon::setTestNow('2026-06-13 10:00:00');
        $this->app->instance('request', Request::create('/api/v2/admin/utilities/list-days', 'GET', [
            'offset' => '1',
        ]));

        $calendar = Mockery::mock(CalendarInterface::class);
        $calendar->shouldReceive('filterByDate')
            ->atLeast()
            ->once()
            ->andReturn(new Collection());

        try {
            $response = (new UtilitiesController($calendar))->listDays()->toResponse(request());
            $payload = $response->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertSame('July 2026', $payload['data']['month']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function findGetRoute(string $uri): mixed
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }
}
