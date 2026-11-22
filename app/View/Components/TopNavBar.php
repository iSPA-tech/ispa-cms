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

class TopNavBar extends Component
{
    /** @var bool $template */
    public bool $template = false;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(bool $template = false)
    {
        $this->template = $template;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render()
    {
        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.top-nav-bar');
    }
}
