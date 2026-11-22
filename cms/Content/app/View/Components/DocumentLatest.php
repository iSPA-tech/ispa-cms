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
use Illuminate\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Contracts\Support\Htmlable;

class DocumentLatest extends Component
{
    /**
     * Create a new component instance.
     *
     * @param PostInterface $post
     * @param bool $template
     * @param int $limit
     */
    public function __construct(
        protected PostInterface $post,
        public bool             $template = false,
        public int              $limit = 4
    )
    {

    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Application|Factory|Htmlable|Closure|string|\Illuminate\Contracts\Foundation\Application
    {
        $latestPost = $this->post->getLatestPostByType('document', $this->limit, true);

        return view(
            ($this->template ? config('ispa-cms.folder') : '') . 'components.document-latest',
            compact('latestPost')
        );
    }
}
