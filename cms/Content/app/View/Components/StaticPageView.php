<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\View\Components;

use Closure;
use App\Constants\Constant;
use Cms\Content\Models\Post;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;

class StaticPageView extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public int $id = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        if ($this->id < 1) {
            $this->id = config('ispa-cms.homepage_static_id');
        }

        $page = Post::query()->where('id', $this->id)
            ->where('type', 'page')
            ->published()
            ->first();

        if (!$page) {
            abort(404, 'Static page not found');
        }

        return view('components.static-page-view', [
            'page' => $page,
        ]);
    }
}
