<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Cms\Core\Facades\CMSCache;
use Illuminate\View\Component;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Contracts\View\View;
use App\Repositories\Interfaces\BannerInterface;

class Slider extends Component
{
    /**
     * Create a new component instance.
     *
     * @param BannerInterface $banner
     * @param string $type
     * @param bool $fullscreen
     * @param bool $template
     * @param bool $noPadding
     */
    public function __construct(
        protected BannerInterface $banner,
        public string             $type,
        public bool               $fullscreen = false,
        public bool               $template = false,
        public bool               $noPadding = false
    )
    {

    }

    /**
     * Get the view / contents that represent the component.
     *
     * Renders nothing when the banner type is blank or has no matching view
     * (e.g. a Slider block previewed before its type is filled in), instead
     * of throwing "View [components.banner-] not found".
     *
     * @return View|string
     */
    public function render(): View|string
    {
        $view = ($this->template ? config('ispa-cms.folder') : '') . 'components.banner-' . str($this->type)->replace('_', '-');

        if (blank($this->type) || !view()->exists($view)) {
            return '';
        }

        $banners = CMSCache::rememberRandomWithTracking(
            CacheKeyHelper::bannerType($this->type),
            function () {
                return $this->banner->GetBannerList($this->type);
            }
        );

        return view($view, compact('banners'));
    }
}
