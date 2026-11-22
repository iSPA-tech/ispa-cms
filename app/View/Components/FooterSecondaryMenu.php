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
use Cms\Core\Interfaces\MenuInterface;

class FooterSecondaryMenu extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(
        protected MenuInterface $menu,
        public bool             $template = false,
        public int              $themeId = 0,
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
        $menu = $this->menu->getMenuByGroupKey(Constant::CACHE_KEY_MENU_FOOTER_SECONDARY);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.footer-secondary-menu' . ($this->themeId > 0 ? '-' . $this->themeId : ''), [
            'menu' => $menu,
        ]);
    }
}
