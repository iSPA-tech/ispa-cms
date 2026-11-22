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
use Cms\Core\Interfaces\CategoryTargetInterface;

class RelatedPosts extends Component
{
    /**
     * Create a new component instance.
     *
     * @param CategoryTargetInterface $target
     * @param PostInterface $post
     * @param bool $template
     * @param int $categoryId
     * @param int $postId
     * @param int $limit
     * @param string|null $templateId
     * @param string $articleType
     */
    public function __construct(
        protected CategoryTargetInterface $target,
        protected PostInterface           $post,
        public bool                       $template = false,
        public int                        $categoryId = 0,
        public int                        $postId = 0,
        public int                        $limit = 6,
        public ?string                    $templateId = "0",
        public string                     $articleType = 'post',
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
        $related = $this->post->getRelatedPosts($this->categoryId, $this->postId, $this->limit, $this->articleType);

        return view(
            ($this->template ? config('ispa-cms.folder') : '') . 'components.related-posts-' . $this->articleType . '-' . $this->templateId,
            compact('related')
        );
    }
}
