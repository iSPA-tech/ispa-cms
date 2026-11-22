<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use App\Enums\StatusEnum;
use Illuminate\View\Component;
use Cms\Product\Models\Product;
use Illuminate\Contracts\View\View;

class ProductRecentlyView extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public bool $template = false
    )
    {
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        // Retrieve recently viewed product IDs from session
        // Suppose you're storing them in the session as an array 'recently_viewed'
        $recentlyViewedIds = session('recently_viewed', []); // e.g. [12, 8, 31]

        // Fetch the Product models (limit to, say, 5)
        // You might want to keep them in the order they were viewed, too
        // but for simplicity, let's just load them in descending order by ID.
        $products = Product::query()->where([
            'status' => StatusEnum::PUBLISHED->value,
        ])
            ->whereIn('id', $recentlyViewedIds)
            ->limit(3)
            ->get();

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.product-recently-view', [
            'products' => $products,
        ]);
    }
}
