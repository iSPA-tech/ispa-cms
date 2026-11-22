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

class AccountMenu extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public bool   $template = false,
        public string $modules = 'account',
    )
    {
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $menus = [];
        $modules = explode(',', $this->modules);

        foreach ($modules as $module) {
            $menus[] = $this->getAccountMenu(trim($module));
        }

        $menus[] = $this->getAccountMenu('account_sign_out');

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.account-menu', compact('menus'));
    }

    protected function getAccountMenu(string $module = ''): array
    {
        $accountMenu = [
            [
                [
                    'index' => 0,
                    'url' => route('account.index'),
                    'title' => __('nav.a.account-dashboard'),
                ],
                [
                    'index' => 1,
                    'url' => route('account.password'),
                    'title' => __('nav.a.account-password'),
                ],
                [
                    'index' => 2,
                    'url' => route('account.update'),
                    'title' => __('text.acc.info'),
                ],
            ],
            [
                [
                    'index' => 3,
                    'url' => route('account.setting'),
                    'title' => __('nav.a.account-setting'),
                ],
//                [
//                    'index' => 4,
//                    'url' => route('account.index'),
//                    'title' => __('text.acc.info'),
//                ],
            ],
            [
                [
                    'index' => 5,
                    'url' => route('account.sign-out'),
                    'title' => __('nav.b.sign-out'),
                ],
            ],
        ];

        switch ($module) {
            case 'account':
                return $accountMenu[0];
            case 'account_setting':
                return $accountMenu[1];
            case 'account_sign_out':
                return $accountMenu[2];
            case 'order':
                return [
                    [
                        'index' => 6,
                        'url' => route('account.order'),
                        'title' => __('nav.acc.order'),
                    ],
                ];
        }
    }
}
