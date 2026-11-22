<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Services;

use Carbon\Carbon;
use App\Enums\StatusEnum;
use App\Enums\PostTypeEnum;
use Cms\Content\Models\Post;
use App\Models\Tracking\Track;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheKeyHelper;
use App\Models\Tracking\TrackingTop;
use App\Models\Tracking\TrackingTopDaily;
use App\Models\Tracking\TrackingTargetDaily;
use Illuminate\Database\Eloquent\Collection;

/**
 * Most viewed published posts over a tracking range (component `<x-best-view-of-post>`).
 *
 * Moved out of the app TrackRepository on 2026-11-21: the query reads Content posts, so it ships with Content.
 *
 * @package Cms\Content\Services
 */
class PostTopViewsService
{
    /**
     * Published articles and documents of the current language, most viewed first, cached for an hour.
     *
     * @param string $range Tracking range name: 7d, 1m, 3m, 6m or 1y.
     * @param int $limit Number of posts.
     *
     * @return Collection
     * @author malayvuong
     * @since 7.160.1175 - 2024-08-17, 16:01 ICT
     */
    public function bestViewedPosts(string $range = '1y', int $limit = 4): Collection
    {
        $readMode = (string) config('tracking.read_mode', 'raw');
        $contractVersion = (int) config('tracking.aggregate_contract_version', 1);

        // Remember for 1 hour (3600s)
        $posts = CMSCache::rememberRandomWithTracking(
            CacheKeyHelper::topViewOfPostByRange($range, $limit)
                . ":tracking:{$readMode}:v{$contractVersion}",
            static function () use ($limit, $range, $readMode) {

                $topData = TrackingTop::query()
                    ->where('range_name', $range)
                    ->where('target_type', Post::class)
                    ->orderByDesc('total_views')
                    ->limit($limit)
                    ->get(['target_id', 'total_views']);

                if ($topData->isEmpty()) {
                    $rangeEnd = Carbon::today();
                    $rangeStart = match ($range) {
                        '1m' => $rangeEnd->copy()->subMonth(),
                        '3m' => $rangeEnd->copy()->subMonths(3),
                        '6m' => $rangeEnd->copy()->subMonths(6),
                        '1y' => $rangeEnd->copy()->subYear(),
                        default => $rangeEnd->copy()->subDays(7),
                    };

                    $daily = $readMode === 'aggregate'
                        ? TrackingTargetDaily::query()
                        : TrackingTopDaily::query();
                    $topData = $daily
                            ->where('target_type', Post::class)
                            ->whereBetween('date', [$rangeStart, $rangeEnd])
                            ->selectRaw('target_id, SUM(total_views) as total_views')
                            ->groupBy('target_id')
                            ->orderByDesc('total_views')
                            ->limit($limit)
                            ->get();
                }

                if ($topData->isEmpty() && $readMode === 'raw') {
                    $topData = Track::query()
                        ->where('target_type', Post::class)
                        ->selectRaw('target_id, SUM(view) as total_views')
                        ->groupBy('target_id')
                        ->orderByDesc('total_views')
                        ->limit($limit)
                        ->get();
                }

                // 2) Retrieve the actual Post models
                $posts = Post::query()
                    ->select('id', 'name', 'category_id', 'type')
                    ->whereIn('id', $topData->pluck('target_id'))
                    ->where([
                        'language_id' => lang('id', 1),
                        'status' => StatusEnum::PUBLISHED->value,
                    ])
                    ->whereIn('type', [
                        PostTypeEnum::get('ARTICLE'),
                        PostTypeEnum::get('DOCUMENT'),
                    ])
                    ->whereHas('categories')
                    ->with([
                        'slugs' => function ($query) {
                            $query->select('target_id', 'slug');
                        },
                        'category' => function ($query) {
                            $query->select('id', 'name')->with([
                                'slugs' => function ($query) {
                                    $query->select('target_id', 'slug');
                                },
                            ]);
                        },
                        'images',
                    ])
                    ->get();

                // 3) Match up `total_views` from $topData to each post
                $posts->map(function ($post) use ($topData) {
                    $found = $topData->where('target_id', $post->id)->first();

                    $post->views = $found->total_views ?? 0;
                    return $post;
                });

                return $posts;
            });

        // 4) Finally, sort by `views` desc, and take the final $limit
        return $posts->sortByDesc('views')->take($limit);
    }
}
