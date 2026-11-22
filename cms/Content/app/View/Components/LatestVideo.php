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
use Cms\Content\Interfaces\PostInterface;

class LatestVideo extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected PostInterface $post,
        public bool             $template = false,
        public int              $limit = 5,
        public int              $theme_id = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $videos = $this->post->getLatestPostByType('video', $this->limit, getenv('CACHE_ENABLE'));

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-video-' . $this->theme_id, [
            'videos' => $videos,
        ]);
    }
}
