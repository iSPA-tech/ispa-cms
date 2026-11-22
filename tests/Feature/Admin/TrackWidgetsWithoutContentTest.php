<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Constants\UserType;
use App\Http\Controllers\V2\Admin\TrackController;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Nwidart\Modules\Facades\Module;
use App\Services\Tracking\TrackingAnalyticsService;
use Tests\TestCase;

/**
 * The track dashboard widget and the top-targets widget must answer 200 when
 * the Content module is disabled (its container bindings and tables are absent).
 */
final class TrackWidgetsWithoutContentTest extends TestCase
{
    /**
     * Authenticate as an admin, disable transport middleware and create the minimal tables.
     */
    protected function setUp(): void
    {
        parent::setUp();
        TrackController::forgetSchemaMemo();
        cache()->flush();
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        Sanctum::actingAs(new User(['id' => 91, 'type' => UserType::WEBMASTER]));

        Schema::create('tracking_daily', static function (Blueprint $t): void {
            $t->id();
            foreach (['views', 'visitors', 'scans', 'download', 'sessions'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->date('date')->nullable();
            $t->timestamps();
        });
        Schema::create('tracking_top', static function (Blueprint $t): void {
            $t->id();
            $t->string('range_name')->nullable();
            $t->date('range_start')->nullable();
            $t->date('range_end')->nullable();
            $t->string('target_key')->nullable();
            $t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->default(0);
            $t->unsignedBigInteger('total_views')->default(0);
            $t->unsignedInteger('rank')->default(0);
            $t->timestamps();
        });
    }

    /**
     * Pretend the Content module is disabled and its interface binding is gone.
     */
    private function disableContent(): void
    {
        $module = \Mockery::mock(\Nwidart\Modules\Module::class);
        $module->shouldReceive('isEnabled')->andReturn(false);
        Module::shouldReceive('find')->andReturnUsing(
            static fn (string $name) => strtolower($name) === 'content' ? $module : null
        );
        $this->app->offsetUnset(PostInterface::class);
        $this->app->instance(TrackingAnalyticsService::class, $this->fakeAnalytics());
        $this->app->forgetInstance(PostInterface::class);
    }

    /**
     * Analytics stub so the test needs no aggregate tables.
     */
    private function fakeAnalytics(): TrackingAnalyticsService
    {
        $mock = \Mockery::mock(TrackingAnalyticsService::class);
        $mock->shouldReceive('siteTotals')->andReturn([
            'total_scan' => 0, 'total_view' => 0, 'total_visit' => 0,
            'data_quality' => 'ok', 'total_scope' => 'all',
        ]);
        $mock->shouldReceive('chart')->andReturn(['dates' => [], 'views' => [], 'visitors' => [], 'scans' => []]);
        $mock->shouldReceive('readMode')->andReturn('aggregate');
        $mock->shouldReceive('targetTotals')->andReturn([
            'total_visit' => 0, 'total_scan' => 0, 'total_view' => 0, 'data_quality' => 'ok',
            'total_scope' => 'all', 'total_visit_semantics' => 'x', 'metric_quality' => 'ok',
        ]);

        return $mock;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function call2(string $method, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')->json($method, $uri);
    }

    /**
     * Dashboard keeps its payload shape with Content-backed sections empty.
     */
    public function test_dashboard_is_200_without_content(): void
    {
        $this->disableContent();

        $response = $this->call2('POST', '/api/v2/admin/track/dashboard');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(0, $data['totalNews']);
        $this->assertArrayHasKey('totalView', $data);
        $this->assertArrayHasKey('comments', $data);
    }

    /**
     * Top targets keeps the full payload shape (empty lists) without Content.
     */
    public function test_top_targets_is_empty_200_without_content(): void
    {
        $this->disableContent();

        $response = $this->call2('GET', '/api/v2/admin/track/top-targets');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $this->assertSame('7d', $response->json('data.range'));
        $this->assertSame(['7d', '1m', '3m', '6m', '1y'], $response->json('data.ranges'));
        $this->assertSame('', $response->json('data.type'));
        $this->assertSame([], $response->json('data.types'));
        $this->assertSame([], $response->json('data.items'));
        $this->assertNull($response->json('data.rangeStart'));
        $this->assertNull($response->json('data.rangeEnd'));
        $this->assertSame(0, $response->json('data.rangeTotalViews'));
    }

    /**
     * The requested range is echoed back even without Content.
     */
    public function test_top_targets_without_content_echoes_requested_range(): void
    {
        $this->disableContent();

        $response = $this->call2('GET', '/api/v2/admin/track/top-targets?range=1m');

        $response->assertOk();
        $this->assertSame('1m', $response->json('data.range'));
    }

    /**
     * Chart and get-track also survive without Content.
     */
    public function test_chart_and_get_track_are_200_without_content(): void
    {
        $this->disableContent();

        $this->call2('POST', '/api/v2/admin/track/chart')->assertOk();
        $this->call2('POST', '/api/v2/admin/track/get-track')->assertOk();
    }

    /**
     * With Content enabled the previous top-targets shape is kept.
     */
    public function test_top_targets_keeps_shape_with_content(): void
    {
        $module = \Mockery::mock(\Nwidart\Modules\Module::class);
        $module->shouldReceive('isEnabled')->andReturn(true);
        Module::shouldReceive('find')->andReturn($module);
        DB::table('tracking_top')->insert([
            'range_name' => '7d', 'range_start' => now()->subDays(7)->toDateString(), 'range_end' => now()->toDateString(),
            'target_key' => 'k', 'target_type' => 'Cms\\Content\\Models\\Post', 'target_id' => 5, 'total_views' => 9, 'rank' => 1,
        ]);

        $response = $this->call2('GET', '/api/v2/admin/track/top-targets');

        $response->assertOk();
        $this->assertSame('7d', $response->json('data.range'));
        $this->assertSame('Post', $response->json('data.type'));
        $this->assertCount(1, $response->json('data.items'));
    }
}
