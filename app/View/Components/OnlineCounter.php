<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use Cms\Core\Facades\CMSCache;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use App\Repositories\Interfaces\TrackInterface;
use App\Services\Tracking\TrackingAnalyticsService;

class OnlineCounter extends Component
{
    /** @var int|null $views */
    public ?int $views;

    /** @var int|null $online */
    public ?int $online;

    /** @var int|null $visit */
    public ?int $visit;

    /**
     * Create a new component instance.
     *
     * @param TrackInterface $track
     * @param bool $template
     * @param int $plusView
     * @param int $plusUser
     * @param bool $views
     * @param bool $online
     * @param bool $visit
     * @param bool $showLabel
     */
    public function __construct(
        protected TrackInterface $track,
        public bool              $template = false,
        public int               $plusView = 0,
        public int               $plusUser = 0,
        bool                     $views = false,
        bool                     $online = false,
        bool                     $visit = false,
        public bool              $showLabel = false
    )
    {
        $this->views = $views ? 1 : 0;
        $this->online = $online ? 1 : 0;
        $this->visit = $visit ? 1 : 0;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $analytics = app(TrackingAnalyticsService::class);
        $counter = CMSCache::remember(
            $analytics->cacheNamespace('online-counter'),
            300,
            static function () use ($analytics) {
                $totals = $analytics->siteTotals();

                return (object) [
                    'total_views' => $totals['total_view'],
                    'total_visitor' => $totals['total_visit'],
                ];
            }
        );

        if ($this->views > 0) {
            $totalView = $counter->total_views + $this->plusView;
        }

        if ($this->visit > 0) {
            $totalUsers = $counter->total_visitor + $this->plusUser;
        }

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.online-counter', [
            'total_view' => $totalView ?? 0,
            'total_online' => CMSCache::countOnline(),
            'total_user' => $totalUsers ?? 0,
        ]);
    }
}
