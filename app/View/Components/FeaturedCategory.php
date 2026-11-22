<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use App\Constants\Constant;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Core\Interfaces\CategoryInterface;

class FeaturedCategory extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryInterface $category,
        public bool                 $template = false,
        public int                  $limitArticle = 8,
        public int                  $themeId = 0,
        public int                  $noPosts = 0
    )
    {

    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $categories = $this->category->getFeaturedCategoriesWithLimitedArticles(Constant::CATEGORY_TYPE_ARTICLE, $this->limitArticle, $this->noPosts);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.featured-category-' . $this->themeId, [
            'categories' => $categories,
        ]);
    }
}
