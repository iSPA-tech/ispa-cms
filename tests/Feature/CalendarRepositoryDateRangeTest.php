<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\Calendar;
use App\Repositories\Eloquent\CalendarRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DB::table('enumerates')->insert([
        ['enum_key' => 'status_published'],
        ['enum_key' => 'status_draft'],
    ]);
    DB::table('languages')->insert([
        'id' => 1,
        'name' => 'Tiếng Việt',
        'path' => 'vi',
        'for_web' => true,
        'default_web' => true,
    ]);
});

function makeCalendarEntry(array $attributes = []): Calendar
{
    //  withoutEvents: CalendarObserver::creating() rewrites date_from/date_to from request()
    return Calendar::withoutEvents(function () use ($attributes) {
        return Calendar::query()->forceCreate(array_merge([
            'name' => 'Test event',
            'date_from' => '2024-08-15 00:00:00',
            'date_to' => '2024-08-15 22:00:00',
            'url' => 'https://example.test/event',
            'is_all_day' => true,
            'is_repeat' => false,
            'repeat_type' => 0,
            'status' => 'status_published',
            'language_id' => lang()->id,
        ], $attributes));
    });
}

test('yearly repeats stored in past years appear anywhere inside the window', function () {
    $inside = makeCalendarEntry([
        'name' => 'Yearly mid-month feast',
        'date_from' => '2024-08-15 00:00:00',
        'is_repeat' => true,
    ]);
    $outside = makeCalendarEntry([
        'name' => 'Yearly feast outside window',
        'date_from' => '2024-09-20 00:00:00',
        'is_repeat' => true,
    ]);

    $result = app(CalendarRepository::class)->filterByDateRange(
        Carbon::parse('2026-08-01 00:00:00'),
        Carbon::parse('2026-08-31 23:59:59'),
    );

    expect($result->pluck('id')->all())->toContain($inside->id)
        ->not->toContain($outside->id);
});

test('yearly repeats are not limited to the first day of the window', function () {
    // Regression: the old query only matched month-day == window start
    makeCalendarEntry([
        'name' => 'Feast on the 9th',
        'date_from' => '2024-07-09 00:00:00',
        'is_repeat' => true,
    ]);

    $result = app(CalendarRepository::class)->filterByDateRange(
        Carbon::parse('2026-07-01 00:00:00'),
        Carbon::parse('2026-07-31 23:59:59'),
    );

    expect($result)->toHaveCount(1);
});

test('window crossing the year boundary matches both december and january repeats', function () {
    $december = makeCalendarEntry([
        'name' => 'Christmas',
        'date_from' => '2023-12-25 00:00:00',
        'is_repeat' => true,
    ]);
    $january = makeCalendarEntry([
        'name' => 'New year feast',
        'date_from' => '2022-01-06 00:00:00',
        'is_repeat' => true,
    ]);
    $outside = makeCalendarEntry([
        'name' => 'Spring feast',
        'date_from' => '2024-03-19 00:00:00',
        'is_repeat' => true,
    ]);

    $result = app(CalendarRepository::class)->filterByDateRange(
        Carbon::parse('2026-12-20 00:00:00'),
        Carbon::parse('2027-01-19 23:59:59'),
    );

    expect($result->pluck('id')->all())->toContain($december->id)
        ->toContain($january->id)
        ->not->toContain($outside->id);

    //  Window-start side (December) sorts before the wrapped side (January)
    expect($result->pluck('id')->all())->toBe([$december->id, $january->id]);
});

test('results are ordered by month-day even when stored years differ', function () {
    $later = makeCalendarEntry([
        'name' => 'Feast on the 20th (old year)',
        'date_from' => '2024-08-20 00:00:00',
        'is_repeat' => true,
    ]);
    $earlier = makeCalendarEntry([
        'name' => 'Feast on the 5th (current year)',
        'date_from' => '2026-08-05 00:00:00',
        'is_repeat' => false,
    ]);

    $result = app(CalendarRepository::class)->filterByDateRange(
        Carbon::parse('2026-08-01 00:00:00'),
        Carbon::parse('2026-08-31 23:59:59'),
    );

    expect($result->pluck('id')->all())->toBe([$earlier->id, $later->id]);
});

test('unpublished entries stay hidden', function () {
    makeCalendarEntry([
        'name' => 'Draft feast',
        'date_from' => '2024-08-10 00:00:00',
        'is_repeat' => true,
        'status' => 'status_draft',
    ]);

    $result = app(CalendarRepository::class)->filterByDateRange(
        Carbon::parse('2026-08-01 00:00:00'),
        Carbon::parse('2026-08-31 23:59:59'),
    );

    expect($result)->toHaveCount(0);
});
