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
use Cms\Core\Interfaces\MenuItemInterface;

class MainMenuMobile extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(
        protected MenuItemInterface $item,
        protected bool              $template = false,
        public bool                 $onlyMenu = false,
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
        $menu = $this->item->getMenuItemListByMenuGroup('main_menu', config('ispa-cms.cache_enable', false));

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.main-menu-mobile' . ($this->onlyMenu ? '-simple' : ''), compact('menu'));
    }
}
