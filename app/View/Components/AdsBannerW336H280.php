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
use App\Repositories\Interfaces\BannerInterface;

class AdsBannerW336H280 extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected BannerInterface $model,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $banners = $this->model->getBanners(Constant::BANNER_TYPE_ADS_336_280, [], 3);

        if ($banners->isEmpty()) {
            return '';
        }

        return view('components.ads-banner-w336-h280', compact('banners'));
    }
}
