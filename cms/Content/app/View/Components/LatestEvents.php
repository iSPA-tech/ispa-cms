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

class LatestEvents extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected PostInterface $post,
        public bool             $template = false,
        public int              $limit = 5,
        public int              $themeId = 0
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $audios = $this->post->getLatestPostByType('events', $this->limit, getenv('CACHE_ENABLE'));
//        $cacheKey = 'latest-events-' . $this->limit;
//        $ttl = getenv('APP_ENV') === 'local' ? 60 : 3600;
//
//        $events = cache()->remember($cacheKey, $ttl, function () {
//            return $this->post->get([
//                'where' => [
//                    'type' => 'events',
//                    'status' => Constant::STATUS_PUBLISHED,
//                    'language_id' => lang('id'),
//                ],
//                'order' => [
//                    'published_at' => 'desc',
//                    'created_at' => 'desc',
//                ],
//                'page' => $this->limit,
//                'with' => [
//                    'slugs:target_id,slug,target_type',
//                    'images:id,name,path,target_type,target_id,category,mime_type,type',
//                ],
//            ]);
//        });

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-events' . ($this->themeId > 0 ? '-' . $this->themeId : ''), [
            'events' => $audios,
        ]);
    }
}
