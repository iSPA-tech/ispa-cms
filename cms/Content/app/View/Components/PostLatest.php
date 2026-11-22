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
use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Illuminate\View\Component;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Contracts\View\View;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Interfaces\CategoryTargetInterface;

class PostLatest extends Component
{
    /**
     * Create a new component instance.
     *
     * @param PostInterface $post
     * @param CategoryTargetInterface $cateRelation
     * @param int $limit
     * @param bool $template
     * @param int $themeId
     * @param string $articleType
     */
    public function __construct(
        public PostInterface           $post,
        public CategoryTargetInterface $cateRelation,
        public int                     $limit = 10,
        public bool                    $template = false,
        public int                     $themeId = 0,
        public string                  $articleType = 'post'
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
        $languageId = lang('id', get_lang_id());
        $baseCacheKey = sprintf(
            '%s:type:%s',
            CacheKeyHelper::latestPosts($this->limit),
            $this->articleType
        );
        $cacheKey = app(ContentPostCacheInvalidationService::class)->versionedKey(
            $baseCacheKey,
            [
                ContentPostCacheInvalidationService::latestScope($this->articleType, $languageId),
                ContentPostCacheInvalidationService::listScope($this->articleType, $languageId),
            ]
        );

        $list = CMSCache::rememberRandomWithTracking($cacheKey, function () use ($languageId) {
            return Post::query()
                ->select('id', 'name', 'description', 'category_id')
                ->whereHas('slugs', fn($q) => $q->select('target_id', 'slug'))
                ->whereHas('category')
                ->with('slugs')
                ->with('category', fn($q) => $q->select('id', 'name')
                    ->with('slugs')
                )
                ->where([
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    'type' => $this->articleType,
                ])
                ->where(function ($q) {
                    $q->where('published_at', '<=', now())
                        ->orWhereNull('published_at');
                })
                ->with([
                    'slugs:target_id,slug',
                    'images:id,target_id,path,name,mime_type,type,config',
                ])
                ->orderBy('published_at', 'desc')
                ->orderBy('id', 'desc')
                ->take($this->limit)
                ->get();
        });

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.post-latest-' . $this->themeId, [
            'list' => $list,
        ]);
    }
}
