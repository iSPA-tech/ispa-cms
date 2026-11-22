<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Traits\Models;

use App\Constants\Constant;
use App\Constants\Tables;
use App\Models\Tracking\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lifetime view total for a tracked model.
 *
 * The source depends on `tracking.read_mode`. In `raw` mode the total is a SUM
 * over the legacy `tracks` rows; in `aggregate` mode it is the entity row in
 * `tracking_target_totals` (baseline + live). Once a site rotates its write
 * mode to `aggregate` the pipeline stops writing `tracks` altogether, so a
 * legacy SUM freezes at the cutover value and reports 0 for anything published
 * after it — which is what the public article header used to render.
 *
 * `total_view` is appended on Post, Product and Gallery, so it is resolved once
 * per row during serialization. Resolving it row by row cost one aggregate per
 * row on a cold cache — 36 of them behind a single featured-category page,
 * measured on production. withTotalView() answers a whole batch with one
 * grouped query: 36 queries and 27 ms became 1 query and 1.4 ms.
 */
trait ViewCounterTrait
{
    /**
     * Totals already resolved this request, keyed by morph class and id.
     *
     * @var array<string, int>
     */
    private static array $totalViewMemo = [];

    /**
     * Count total view.
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-01, 00:45 ICT
     * @last_updated 8.1.0 - 2026-08-03 - by iSPA Team
     */
    public function totalView(): Attribute
    {
        // The query lives in the getter, not in this factory: Eloquent calls
        // totalView() every time the attribute is touched, so a query out here
        // runs again on each access. shouldCache() pins the resolved value.
        return (new Attribute(get: fn (): int => $this->resolveTotalView()))->shouldCache();
    }

    /**
     * Answer `total_view` for a whole result set with one grouped aggregate.
     *
     * Registered as an afterQuery hook so a caller cannot forget it. It has to
     * be attached by the caller, though: Builder::get() applies global scopes
     * to a clone while running after-query callbacks on the original builder,
     * so a hook added from a global scope never fires.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeWithTotalView(Builder $query): Builder
    {
        return $query->afterQuery(static function (mixed $results): mixed {
            if ($results instanceof Collection) {
                self::warmTotalViews($results);
            }

            return $results;
        });
    }

    /**
     * Resolve every missing total in the batch with one query per morph type.
     *
     * @param iterable<int|string, mixed> $models
     */
    public static function warmTotalViews(iterable $models): void
    {
        $pending = [];

        foreach ($models as $model) {
            if (! $model instanceof Model || ! method_exists($model, 'totalView')) {
                continue;
            }

            $id = (int) $model->getKey();
            if ($id < 1) {
                continue;
            }

            $morph = $model->getMorphClass();
            if (array_key_exists($morph . '|' . $id, self::$totalViewMemo)) {
                continue;
            }

            //  The concrete class rides along: aggregate target aliases are
            //  keyed by class name, which is not always the morph class.
            $pending[$morph][$id] = $model::class;
        }

        if ($pending === []) {
            return;
        }

        $totals = self::readTotalViews($pending);

        $writes = [];
        foreach ($pending as $morph => $rows) {
            foreach (array_keys($rows) as $id) {
                $memoKey = $morph . '|' . $id;
                $total = $totals[$memoKey] ?? 0;
                self::$totalViewMemo[$memoKey] = $total;
                // Prime the per-row key too, so an admin detail view that never
                // goes through this scope still skips the aggregate.
                $writes[self::totalViewCacheKeyFor($morph, (int) $id)] = $total;
            }
        }

        if ($writes !== []) {
            cache()->putMany($writes, 3600);
        }
    }

    /**
     * Memo, then cache, then a single aggregate.
     */
    private function resolveTotalView(): int
    {
        $id = (int) $this->getKey();

        if ($id < 1) {
            return 0;
        }

        $morph = $this->getMorphClass();
        $memoKey = $morph . '|' . $id;

        if (array_key_exists($memoKey, self::$totalViewMemo)) {
            return self::$totalViewMemo[$memoKey];
        }

        $class = static::class;
        $total = (int) cache()->remember(
            self::totalViewCacheKeyFor($morph, $id),
            3600,
            static fn (): int => self::readTotalViews([$morph => [$id => $class]])[$morph . '|' . $id] ?? 0
        );

        return self::$totalViewMemo[$memoKey] = $total;
    }

    /**
     * Drop the per-process memo.
     *
     * The memo is sized for one request. A queue worker or an Octane process
     * outlives that, so anything long running has to be able to let go of it.
     */
    public static function flushTotalViewMemo(): void
    {
        self::$totalViewMemo = [];
    }

    /**
     * Read totals for a pending batch from whichever store the site reads.
     *
     * @param  array<string, array<int, class-string>> $pending morph class => [id => concrete class]
     * @return array<string, int>                      memo key (`morph|id`) => total
     */
    private static function readTotalViews(array $pending): array
    {
        return self::totalViewReadsAggregates()
            ? self::aggregateTotalViews($pending)
            : self::legacyTotalViews($pending);
    }

    /**
     * @param  array<string, array<int, class-string>> $pending
     * @return array<string, int>
     */
    private static function aggregateTotalViews(array $pending): array
    {
        $memoKeysByTarget = [];
        foreach ($pending as $morph => $rows) {
            foreach ($rows as $id => $class) {
                $targetKey = self::totalViewTargetKey([$class, $morph], (int) $id);
                if ($targetKey === null) {
                    continue;
                }

                $memoKeysByTarget[$targetKey] = $morph . '|' . $id;
            }
        }

        if ($memoKeysByTarget === []) {
            return [];
        }

        $totals = DB::table(Tables::TRACKING_TARGET_TOTALS)
            ->select('target_key')
            ->selectRaw('(baseline_views + live_views) as total')
            ->whereIn('target_key', array_keys($memoKeysByTarget))
            ->pluck('total', 'target_key');

        $resolved = [];
        foreach ($memoKeysByTarget as $targetKey => $memoKey) {
            $resolved[$memoKey] = (int) ($totals[$targetKey] ?? 0);
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<int, class-string>> $pending
     * @return array<string, int>
     */
    private static function legacyTotalViews(array $pending): array
    {
        $resolved = [];

        foreach ($pending as $morph => $rows) {
            $ids = array_map('intval', array_keys($rows));

            $totals = DB::table((new Track())->getTable())
                ->select('target_id')
                ->selectRaw('sum(' . DB::connection()->getQueryGrammar()->wrap('view') . ') as total')
                ->where('target_type', $morph)
                ->whereIn('target_id', $ids)
                ->groupBy('target_id')
                ->pluck('total', 'target_id');

            foreach ($ids as $id) {
                $resolved[$morph . '|' . $id] = (int) ($totals[$id] ?? 0);
            }
        }

        return $resolved;
    }

    /**
     * Entity target key used by the aggregate pipeline, or null when this model
     * is not mapped into the tracking contract at all.
     *
     * Mirrors TrackingTargetKeyResolver's entity branch: producers pass
     * `get_class($model)`, so the alias is looked up by class name first and
     * the morph class only as a fallback.
     *
     * @param list<string> $classCandidates
     */
    private static function totalViewTargetKey(array $classCandidates, int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        $aliases = (array) config('tracking.target_aliases', []);

        foreach ($classCandidates as $candidate) {
            $alias = $aliases[$candidate] ?? null;
            if (is_string($alias) && $alias !== '') {
                return $alias . ':' . $id;
            }
        }

        return null;
    }

    private static function totalViewReadsAggregates(): bool
    {
        return config('tracking.read_mode', 'raw') === 'aggregate';
    }

    /**
     * Cache key for one model's lifetime total.
     *
     * The morph class is part of the key. It used to be hardcoded to the posts
     * bucket, so a Product and a Post sharing an id also shared a cached total
     * and reported each other's view count. The read mode is part of it too, so
     * a site flipping to aggregate reads does not serve frozen legacy totals
     * out of the cache for another hour.
     */
    private static function totalViewCacheKeyFor(string $morph, int $id): string
    {
        return make_key([
            'type' => Constant::CACHE_KEY_COUNTER,
            Constant::CACHE_KEY_COUNTER => Constant::CACHE_KEY_POSTS,
            'mode' => self::totalViewReadsAggregates() ? 'aggregate' : 'raw',
            'model' => $morph,
            'article' => $id,
        ]);
    }
}
