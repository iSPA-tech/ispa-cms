<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Paginate extends Component
{
    public object $data;

    public bool $template;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(object $data, bool $template = false)
    {
        $this->data = $data;
        $this->template = $template;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $data = $this->data;

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.paginate', compact('data'));
    }
}
