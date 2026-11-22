<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Content\Services\PostTopViewsService;

/**
 * `<x-best-view-of-post>`: the most viewed posts of the last year.
 *
 * Moved out of app/View/Components on 2026-11-21 so the component ships with Content; the tag is unchanged.
 *
 * @package Cms\Content\View\Components
 */
class BestViewOfPost extends Component
{
    public function __construct(
        protected PostTopViewsService $topViews,
        public ?string                $fromDate = null,
        public ?string                $toDate = null,
        public bool                   $template = false,
        public int                    $themeId = 0,
        public int                    $months = 6,
        public int                    $limit = 4
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        // If $fromDate and $toDate are not set, then set them to the current date and last 6 months
        if (empty($this->fromDate)) {
            $this->fromDate = now()->subMonths($this->months)->format('Y-m-d');
        }
        if (empty($this->toDate)) {
            $this->toDate = now()->format('Y-m-d');
        }

        $posts = $this->topViews->bestViewedPosts('1y', $this->limit);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.best-view-of-post' . ($this->themeId > 0 ? '-' . $this->themeId : ''), [
            'posts' => $posts,
        ]);
    }
}
