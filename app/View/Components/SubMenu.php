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
use Cms\Core\Interfaces\MenuInterface;

class SubMenu extends Component
{
    protected MenuInterface $model;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(MenuInterface $model)
    {
        $this->model = $model;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|Closure|string
    {
        $cacheKey = make_key([
            'type' => 'menu',
            'group_key' => 'sub_menu',
            'language_id' => lang()->id,
        ]);
        $menu = cache()->remember($cacheKey, config('setting.cache.menu', 3600), function () use ($cacheKey) {

            return $this->model->findBy([
                'group_key' => 'sub_menu',
                'language_id' => lang()->id,
            ], ['id', 'group_key'], [
                'items' => function ($q) {
                    $q->select('menu_id', 'name', 'title', 'type', 'url', 'target_type', 'target_id', 'parent_id', 'custom')->when(
                        'target_id !== null',
                        function ($e) {
                            $e->with('target');
                        }
                    );
                },
            ]);
        });

        return view('components.sub-menu', compact('menu'));
    }
}
