<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Observers\CalendarObserver;
use App\Repositories\Eloquent\CalendarRepository;
use Cms\Core\Facades\CMSCache;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CalendarCacheInvalidationTest extends TestCase
{
    protected function tearDown(): void
    {
        cache()->forget(CalendarRepository::calendarCacheVersionKey());

        parent::tearDown();
    }

    public function test_calendar_repository_cache_key_changes_after_calendar_mutation(): void
    {
        cache()->forget(CalendarRepository::calendarCacheVersionKey());

        $keys = [];

        CMSCache::shouldReceive('rememberRandomWithTracking')
            ->twice()
            ->andReturnUsing(function (string $key) use (&$keys) {
                $keys[] = $key;

                return new Collection();
            });

        $repository = new CalendarRepository();
        $from = Carbon::parse('2026-06-01 00:00:00');
        $to = Carbon::parse('2026-06-30 23:59:59');

        $repository->filterByDateRange($from, $to);

        Event::fake();

        $calendar = new Calendar(['name' => 'Cache refresh proof']);
        $calendar->id = 123;

        (new CalendarObserver())->created($calendar);

        $repository->filterByDateRange($from, $to);

        $this->assertStringContainsString('type:calendars', $keys[0]);
        $this->assertStringContainsString('version:1', $keys[0]);
        $this->assertStringContainsString('version:2', $keys[1]);
        $this->assertNotSame($keys[0], $keys[1]);
    }
}
