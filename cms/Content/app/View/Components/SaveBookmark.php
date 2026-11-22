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
use Cms\Content\Interfaces\BookmarkInterface;

class SaveBookmark extends Component
{
    public bool $isBookmarked = false;
    public int $userId;

    /**
     * Create a new component instance.
     */
    public function __construct(
        protected BookmarkInterface $bookmark,
        public string               $type,
        public int                  $id,
        public bool                 $template = false,
    )
    {
        $this->userId = user('id', 0);
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $listCache = $this->bookmark->getBookmarkByTarget($this->type, $this->userId, config('ispa-cms.cache_enable', false));

        if ($listCache) {
            foreach ($listCache as $item) {
                if ($item->target_id === $this->id) {
                    $this->isBookmarked = true;
                    break;
                }
            }
        }

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.save-bookmark');
    }
}
