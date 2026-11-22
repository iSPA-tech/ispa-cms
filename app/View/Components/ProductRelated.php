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
use Illuminate\Contracts\View\View;
use Cms\Product\Models\Product;

class ProductRelated extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public bool $template = false,
        public int  $limit = 3,
        public int  $categoryId = 0,
        public int  $productId = 0,
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
            'type' => 'product:related',
            'category_id' => $this->categoryId,
            'product_id' => $this->productId,
            'limit' => $this->limit,
        ]);
        //  Get all product categories at leaf level (no children)
        $products = cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey) {

            return Product::query()
                ->where([
                    'status' => StatusEnum::PUBLISHED->value,
                    ['id', '!=', $this->productId],
                ])
                ->when($this->categoryId > 0, function ($query) {
                    $query->whereHas('categories', function ($query) {
                        $query->where('id', $this->categoryId);
                    });
                })
                ->where(function ($query) {
                    $query->whereNotNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->take($this->limit)->latest()->get();
        });

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.product-related', [
            'products' => $products,
        ]);
    }
}
