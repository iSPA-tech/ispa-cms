<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\View\Components;

use Closure;
use Cms\Core\Facades\CMSCache;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;

class PostsByCategory extends Component
{
    /** @var int|null $limit */
    public ?int $limit;

    /** @var int|null $themeId */
    public ?int $themeId;

    /** @var bool $template */
    public bool $template;

    /** @var int $categoryId */
    public int $categoryId;

    /** @var array<int> $categoryIds */
    public array $categoryIds;

    /** @var string $postType */
    public string $postType = 'post';

    /** @var CategoryTargetInterface $target */
    protected CategoryTargetInterface $target;

    /** @var CategoryInterface $category */
    protected CategoryInterface $category;

    /**
     * Create a new component instance.
     *
     * @param CategoryTargetInterface $target
     * @param CategoryInterface $category
     * @param PostInterface $post
     * @param int $categoryId
     * @param int $limit
     * @param bool $template
     * @param int $themeId
     * @param string $type
     * @param string $viewMoreUrl
     * @param string $routeName
     */
    public function __construct(
        CategoryTargetInterface $target,
        CategoryInterface       $category,
        protected PostInterface $post,
        int                     $categoryId = 0,
        int                     $limit = 10,
        bool                    $template = false,
        int                     $themeId = 0,
        public string           $type = 'post',
        public string           $viewMoreUrl = '',
        public ?string          $routerName = null,
        array|string|null       $categoryIds = null,
        public ?string          $title = null
    )
    {
        $this->target = $target;
        $this->category = $category;
        $this->limit = $limit;
        $this->template = $template;
        $this->themeId = $themeId;
        $this->categoryId = $categoryId;
        $this->postType = $type;
        $this->categoryIds = collect(is_array($categoryIds) ? $categoryIds : explode(',', (string) $categoryIds))
            ->map(static fn($id) => (int) $id)
            ->filter(static fn($id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $primaryCategoryId = $this->categoryId > 0 ? $this->categoryId : ($this->categoryIds[0] ?? 0);

        if ($primaryCategoryId < 1) {
            return '';
        }

        $category = CMSCache::rememberWithTracking(
            CacheKeyHelper::categoryBySlug($primaryCategoryId),
            function () {
                return $this->category->find($this->categoryId > 0 ? $this->categoryId : ($this->categoryIds[0] ?? 0), ['*'], ['slugs:target_id,slug']);
            }, 86400);

        if (empty($category) && empty($this->title)) {
            return '';
        }

        // Include the current category plus all of its descendants so child-category posts are also returned.
        $sourceCategoryIds = $this->categoryIds ?: [$this->categoryId];
        $categoryIds = [];
        foreach ($sourceCategoryIds as $sourceCategoryId) {
            $categoryIds = [
                ...$categoryIds,
                ...$this->category->getChildrenIds($sourceCategoryId),
                $sourceCategoryId,
            ];
        }
        $categoryIds = array_values(array_unique($categoryIds));

        $posts = $this->post->getPostsByCategoriesIds($categoryIds, 1, $this->limit, $this->postType);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.posts-by-category-' . $this->themeId, [
            'category' => $category,
            'posts' => $posts->items() ?? [],
            'title' => $this->title ?: ($category->name ?? ''),
            'viewMoreUrl' => $this->viewMoreUrl,
            'routerName' => $this->routerName,
        ]);
    }
}
