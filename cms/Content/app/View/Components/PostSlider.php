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

class PostSlider extends Component
{
    /** @var array|string[] $typeAllowed */
    protected array $typeAllowed = ['featured', 'hotWeek', 'hotMonth'];

    /**
     * Create a new component instance.
     *
     * @param PostInterface $post
     * @param bool $template
     * @param int $limit
     * @param string $type
     * @param string $articleType
     */
    public function __construct(
        protected PostInterface $post,
        public bool             $template = false,
        public int              $limit = 4,
        public string           $type = 'featured',
        public string           $articleType = 'post'
    )
    {
        $this->type = in_array($type, $this->typeAllowed, true) ? $type : 'featured';
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $listPost = $this->post->getFeaturedPosts($this->limit);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.post-slider', [
            'list' => $listPost,
        ]);
    }
}
