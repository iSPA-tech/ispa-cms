<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use App\Models\Place;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;

class FeaturedPlaces extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public bool $template = false,
        public int  $themeId = 0,
        public int  $limit = 10,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_PLACE_DETAIL, Constant::CACHE_KEY_PLACE_DETAIL => 'featured-places',
            'language_id' => lang('id', get_lang_id()), 'limit' => $this->limit,
        ]);
        //  Take random places
        $listPlaces = Place::where('status', StatusEnum::PUBLISHED->value)->limit($this->limit)->inRandomOrder()->get();

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.featured-places-' . $this->themeId, [
            'list' => $listPlaces,
        ]);
    }
}
