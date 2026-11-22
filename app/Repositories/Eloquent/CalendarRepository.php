<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use DateTimeInterface;
use App\Models\Calendar;
use Cms\Core\Facades\CMSCache;
use App\Constants\Constant;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use App\Repositories\Interfaces\CalendarInterface;

class CalendarRepository extends RepositoriesAbstract implements CalendarInterface
{
    public static function calendarCacheVersionKey(): string
    {
        return CacheKeyHelper::custom([
            'type' => Constant::CACHE_KEY_CALENDARS,
            'scope' => 'version',
        ]);
    }

    public static function calendarCacheVersion(): int
    {
        return (int) Cache::get(self::calendarCacheVersionKey(), 1);
    }

    public static function bumpCalendarCacheVersion(): int
    {
        $nextVersion = self::calendarCacheVersion() + 1;

        Cache::forever(self::calendarCacheVersionKey(), $nextVersion);

        return $nextVersion;
    }

    public function __construct()
    {
        $this->setModel(Calendar::class);
    }

    /**
     * Get calendar by month and year
     *
     * @return Collection|array
     * @author malayvuong
     * @since 6.62.6 - 2022-08-15, 01:23 ICT
     */
    public function getCalendar(): Collection|array
    {
        $firstDate = now()->addMonths((request('offset', 0) - 2))->firstOfMonth();
        $lastDate = now()->addMonths((request('offset', 0) + 2))->lastOfMonth();

        return $this->filterByDateRange($firstDate, $lastDate, [
            'id', 'name', 'url', 'date_from', 'date_to', 'is_repeat', 'repeat_type', 'is_all_day',
        ]);
    }

    /**
     * Get calendar filter by date Range
     *
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @param array $select
     * @param string $status
     *
     * @return array|Collection
     * @author malayvuong
     * @since 6.19.6 - 2022-08-02, 12:16 ICT
     */
    public function filterByDateRange(DateTimeInterface $from, DateTimeInterface $to, array $select = [], string $status = 'status_published'): array|Collection
    {
        $cacheKey = CacheKeyHelper::custom([
            'type' => Constant::CACHE_KEY_CALENDARS,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'lang' => lang()->id ?? 0,
            'version' => self::calendarCacheVersion(),
        ]);

        return CMSCache::rememberRandomWithTracking($cacheKey, function () use ($from, $to, $select, $status) {
            $fromMd = $from->format('m-d');
            $toMd = $to->format('m-d');
            $monthDay = $this->monthDayExpression();

            $model = $this->model()->where(function ($q) use ($from, $to, $fromMd, $toMd, $monthDay) {
                $q->whereBetween('date_from', [$from, $to])
                    ->orWhere(function ($q) {
                        //  Monthly
                        $q->where('repeat_type', 1)->where('is_repeat', true);
                    })
                    ->orWhere(function ($q) use ($fromMd, $toMd, $monthDay) {
                        //  Yearly: match month-day within the window, ignore the stored year
                        $q->where('repeat_type', 0)->where('is_repeat', true);

                        if ($fromMd <= $toMd) {
                            $q->whereRaw("{$monthDay} BETWEEN ? AND ?", [$fromMd, $toMd]);
                        } else {
                            //  Window crosses the year boundary (Dec -> Jan)
                            $q->where(function ($q) use ($fromMd, $toMd, $monthDay) {
                                $q->whereRaw("{$monthDay} >= ?", [$fromMd])
                                    ->orWhereRaw("{$monthDay} <= ?", [$toMd]);
                            });
                        }
                    });
            })->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            })->where([
                'language_id' => lang()->id,
            ]);

            if (!empty($select)) {
                $model->select($select);
            }

            //  Sort by month-day so repeats stored in past years keep calendar order;
            //  entries before the window start (year-boundary wrap) sort last
            return $model->orderByRaw(
                "({$monthDay} < ?), {$monthDay}, id DESC",
                [$fromMd]
            )->get();
        });
    }

    /**
     * SQL expression extracting "MM-DD" from date_from for the active driver.
     */
    protected function monthDayExpression(): string
    {
        return match ($this->model()->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%m-%d', date_from)",
            'pgsql' => "to_char(date_from, 'MM-DD')",
            default => "DATE_FORMAT(date_from, '%m-%d')",
        };
    }

    /**
     * Get calendar filter by $date
     *
     * @param DateTimeInterface $date
     * @param array $select
     *
     * @return array|Collection
     * @author malayvuong
     * @since 6.19.6 - 2022-08-01, 12:28 ICT
     */
    public function filterByDate(DateTimeInterface $date, array $select = []): array|Collection
    {
        $model = $this->model()->where(function ($q) use ($date) {
            $q->whereDate('date_from', $date)
                ->orWhere(function ($q) use ($date) {
                    //  Yearly
                    $q->where('date_from', '<=', $date)->where('date_to', '>=', $date)->where('is_repeat', false);
                })
                ->orWhere(function ($q) use ($date) {
                    //  Monthly
                    $q->where('repeat_type', 1)->where('is_repeat', true)->where('date_from', 'like', '%-' . $date->format('d') . ' %');
                })
                ->orWhere(function ($q) use ($date) {
                    //  Yearly
                    $q->where('repeat_type', 0)->where('date_from', 'like', '%-' . $date->format('m-d') . ' %')->where('is_repeat', true);
                });
        })->with('language:id,name,path');

        if (!empty($select)) {
            $model->select($select);
        }

        $model = $model->orderBy('id', 'DESC')->get();

//        dd($model);

        return $model;
    }
}
