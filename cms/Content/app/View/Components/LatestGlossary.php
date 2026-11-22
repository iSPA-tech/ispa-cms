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
use Cms\Core\Interfaces\CategoryInterface;

class LatestGlossary extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected PostInterface     $post,
        protected CategoryInterface $category,
        public string               $glossarySlug = 'glossary',
        public int                  $pageSize = 12,
        public bool                 $template = false,
        public int                  $theme_id = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $glossary = $this->category->getCategoryBySlugAndType($this->glossarySlug);

        $glossaries = $this->post->getGlossaryPostList($this->glossarySlug, 'all', 'post', $this->pageSize);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-glossary-' . $this->theme_id, compact('glossaries', 'glossary'));
    }
}
