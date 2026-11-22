<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use App\Repositories\Interfaces\GalleryInterface;

class LatestGallery extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected GalleryInterface $gallery,
        public bool                $template = false,
        public bool                $removeFirst = false,
        public int                 $limit = 8,
        public int                 $themeId = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $galleries = $this->gallery->getLatestGalleries($this->limit);
        if ($this->limit > 1) {
            $gallery = $galleries;
            if ($this->removeFirst) {
                // remove the first item from the collection
                $gallery = $galleries->slice(1);
            }
        } else {
            $gallery = $galleries && $galleries->count() > 0 ? $galleries->first() : null;
        }

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-gallery' . ($this->themeId > 0 ? '-' . $this->themeId : ''),
            compact('galleries', 'gallery')
        );
    }
}
