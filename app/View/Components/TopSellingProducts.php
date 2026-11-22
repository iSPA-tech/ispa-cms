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
use Cms\Product\Interfaces\ProductInterface;

class TopSellingProducts extends Component
{
    /**
     * Create a new component instance.
     *
     * @param ProductInterface $product
     * @param bool $template
     * @param int $limit
     * @param int $themeId
     */
    public function __construct(
        protected ProductInterface $product,
        public bool                $template = false,
        public int                 $limit = 6,
        public int                 $themeId = 0
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
        $products = $this->product->topSellerProducts($this->limit, 1, getenv('CACHE_ENABLE', false));

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.top-selling-products-' . $this->themeId, [
            'products' => $products,
        ]);
    }
}
