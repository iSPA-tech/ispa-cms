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
use Cms\Content\Interfaces\TagInterface;

class TagsRelated extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected TagInterface $tag,
        public bool            $template = false,
        public int             $limit = 6,
        public int             $themeId = 0,
        protected int          $exceptId = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $tags = $this->tag->model()->where([
            ['id', '!=', $this->exceptId],
        ])->take($this->limit)->latest()->get();

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.tags-related' . ($this->themeId > 0 ? '-' . $this->themeId : ''), [
            'tags' => $tags,
        ]);
    }
}
