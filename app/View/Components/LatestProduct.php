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
use App\Enums\StatusEnum;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Product\Interfaces\ProductInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;

class LatestProduct extends Component
{
    /**
     * Create a new component instance.
     *
     * @param ProductInterface $product
     * @param CategoryTargetInterface $cateRelation
     * @param int $limit
     * @param bool $template
     * @param int $themeId
     */
    public function __construct(
        protected ProductInterface        $product,
        protected CategoryTargetInterface $cateRelation,
        public int                        $limit = 10,
        public bool                       $template = false,
        public int                        $themeId = 0
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
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_PRODUCTS, Constant::CACHE_KEY_PRODUCTS => 'latest',
            'language_id' => lang()->id, 'limit' => $this->limit,
            'time' => now()->format('Y-m-d H:i'),   //  Set time for published date in every min
        ]);

        $listProduct = cache()->remember($cacheKey, config('setting.cache.product', 0), function () use ($cacheKey) {

            return $this->product->get([
                'select' => [
                    'id', 'name', 'description', 'category_id', 'first_option_id', 'second_option_id',
                ],
                'with' => [
                    'category' => function ($q) {
                        $q
                            ->select('id', 'name', 'description')
                            ->whereHas('slugs', fn($q) => $q->select('target_id', 'slug'));
                    },
                    'variants' => fn($q) => $q->select('id', 'product_id', 'sell_price', 'sale_price', 'sku')
                        ->published(),
                    'first_option',
                    'second_option',
                ],
                'has' => [
                    'slugs' => fn($q) => $q->select('target_id', 'slug'),
                ],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang()->id,
                    ['category_id', '!=', null],
                    fn($q) => $q->whereNull('published_at')->orWhere(function ($e) {
                        $e->whereNotNull('published_at')->where('published_at', '<', now());
                    }),
                ],
                'order' => [
                    'created_at' => 'desc',
                    'published_at' => 'desc',
                ],
                'take' => $this->limit,
            ]);
        });

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-product' . ($this->themeId ? '-' . $this->themeId : ''), [
            'list' => $listProduct,
        ]);
    }
}
