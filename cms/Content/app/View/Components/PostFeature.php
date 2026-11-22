<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\View\Components;

use Closure;
use App\Enums\StatusEnum;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;

class PostFeature extends Component
{
    /**
     * Create a new component instance.
     *
     * @param PostInterface $post
     * @param CategoryTargetInterface $cate
     * @param CategoryInterface $cateModel
     * @param int $cateId
     * @param string $type
     * @param string|null $title
     * @param string|null $description
     * @param int $limit
     * @param string $articleType
     */
    public function __construct(
        protected PostInterface           $post,
        protected CategoryTargetInterface $cate,
        protected CategoryInterface       $cateModel,
        public int                        $cateId = 0,
        public string                     $type = 'latest',
        public ?string                    $title = null,
        public ?string                    $description = null,
        public int                        $limit = 3,
        public string                     $articleType = 'post'
    )
    {
        $this->title = $title ?? __('web.post.feature');
        $this->description = $description ?? __('web.post.feature-desc');
        $this->limit = (1 < $limit && $limit < 5) ? $limit : 3;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $languageId = lang('id', get_lang_id());
        $cache_key = [
            'type' => 'post_feature',
            'post_type' => $this->type,
            'limit' => $this->limit,
            'article_type' => $this->articleType,
            'lang' => $languageId,
        ];
        if ($this->cateId > 0) {
            $cache_key['cateId'] = $this->cateId;

            $cacheKey = make_key(['type' => 'category', 'id' => $this->cateId, 'lang' => $languageId]);
            $category = cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () {

                return $this->cateModel->find($this->cateId, ['name', 'id', 'description'], ['seo:target_id,title,description']);
            }
            );
        }

        $cacheKey = make_key($cache_key);
        $post = cache()->remember(
            $cacheKey,
            config('setting.cache.blog', 3600),
            function () use ($cacheKey, $languageId) {

                return $this->post->model()->select('id', 'name', 'description', 'user_id', 'cateId', 'published_at')
                    ->where([
                        ['cateId', '=', $this->cateId],
                        'status' => StatusEnum::PUBLISHED->value,
                        'type' => $this->articleType,
                        'language_id' => $languageId,
                    ])
                    ->where(function ($query) {
                        $query->where('published_at', '<=', now())->orWhereNull('published_at');
                    })->whereHas('slugs')
                    ->when(
                        $this->cateId > 0,
                        function ($q) {
                            $listId = $this->cate->get([
                                'select' => ['target_id'],
                                'where' => [
                                    'target_type' => get_class($this->post->model()),
                                    'cateId' => $this->cateId,
                                ],
                            ]);
                            if (count($listId) > 0) {
                                $q->whereIn('id', $listId->pluck('target_id')->toArray());
                            }
                        }
                    )->when(
                        $this->type === 'feature',
                        function ($q) {
                            return $q->where('custom.feature', true);
                        }
                    )->when(
                        $this->type === 'newest',
                        function ($q) {
                            return $q->where('custom.newest', true);
                        }
                    )->with([
                        'categories' => function ($e) {
                            return $e->select('id', 'name', 'description')->with('slugs:target_id,slug');
                        },
                        'author:id,name',
                        'slugs:target_id,slug',
                    ])->take($this->limit)->orderBy('id', 'DESC')->get();
            }
        );

        return view('components.post-feature', [
            'post' => $post,
            'category' => $category ?? null,
        ]);
    }
}
