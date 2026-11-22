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
use Cms\Core\Interfaces\CategoryInterface;

class RootCategoryFeatured extends Component
{
    public ?bool $template;

    protected CategoryInterface $category;

    public ?int $limit;

    public ?int $childrenLimit;

    /**
     * Create a new component instance.
     *
     * @param CategoryInterface $category
     * @param bool $template
     * @param int $limit
     * @param int $childrenLimit
     */
    public function __construct(CategoryInterface $category, bool $template = false, int $limit = 4, int $childrenLimit = 8)
    {
        $this->category = $category;
        $this->template = $template;
        $this->limit = $limit;
        $this->childrenLimit = $childrenLimit;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $categories = $this->category->get([
            'select' => [
                'id', 'name', 'description',
            ],
            'where' => [
                'status' => StatusEnum::PUBLISHED->value,
                'parent_id' => null,
                'featured' => true,
            ],
            'with' => [
                'children' => function ($q) {
                    $q->select('id', 'parent_id', 'name', 'description')->with([
                        'slugs:target_id,slug',
                    ])->when(
                        $this->childrenLimit > 0,
                        function ($e) {
                            $e->take($this->childrenLimit);
                        }
                    );
                },
                'slugs:target_id,slug',
            ],
            'when' => [
                [
                    $this->limit > 0,
                    function ($q) {
                        $q->take($this->limit);
                    },
                ],
            ],
        ]);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.root-category-featured', [
            'list' => $categories,
        ]);
    }
}
