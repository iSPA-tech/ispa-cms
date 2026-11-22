<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use JsonException;
use App\Constants\Constant;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Interfaces\BannerInterface;

class PopUpBanner extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected BannerInterface $model,
        public int                $delay = 1,  //  1 day
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     *
     */
    public function render(): View|Closure|string
    {
        $excludedBannerIds = $this->getValidClosedBannerIds();
        $banner = $this->model->getBanners(Constant::BANNER_TYPE_POPUP, $excludedBannerIds);

        // Maybe this banner returned an array, so we need to check and only get first item in the array
        if (is_array($banner) || $banner instanceof Collection) {
            $banner = collect($banner)->first();
        }

        $bannerId = data_get($banner, 'id');

        if (empty($banner) || empty($bannerId) || in_array($bannerId, $excludedBannerIds, true)) {
            return '';
        }

        return view('components.pop-up-banner', [
            'banner' => $banner,
            'bannerClose' => $excludedBannerIds,
            'bannerId' => $bannerId,
        ]);
    }

    private function getValidClosedBannerIds(): array
    {
        try {
            $raw = json_decode(Cookie::get('banner_close', '{}'), true, 512, JSON_THROW_ON_ERROR);
            return collect($raw)
                ->filter(static fn($timestamp, $key) => is_numeric($key) && $timestamp > now()->timestamp)
                ->keys()
                ->map(static fn($id) => (int)$id)
                ->toArray();
        } catch (JsonException) {
            return [];
        }
    }

}
