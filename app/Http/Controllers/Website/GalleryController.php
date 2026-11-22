<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use App\Constants\Constant;
use App\Events\SaveTracking;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\Foundation\Application;
use App\Repositories\Interfaces\GalleryInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;

class GalleryController extends Controller
{
    protected GalleryInterface $gallery;

    protected SlugInterface $slug;

    /**
     * GalleryController constructor
     *
     * @param GalleryInterface $gallery
     * @param SlugInterface $slug
     *
     * @author malayvuong
     *
     * @since 6.5.3 - 2022-03-4, 1:8
     */
    public function __construct(GalleryInterface $gallery, SlugInterface $slug)
    {
        $this->gallery = $gallery;
        $this->slug = $slug;
    }

    /**
     * Show gallery detail
     *
     * @author malayvuong
     *
     * @since 6.6.8 - 2022-03-7, 16:15
     */
    public function show(string $slug)
    {
        if (!$slug) {
            abort(404, $slug . ' does not exist');
        }

        $cacheKey = make_key(['type' => 'gallery', 'slug' => $slug]);
        $view = cache()->remember(
            $cacheKey,
            config('setting.cache.gallery', 86400),
            function () use ($slug, $cacheKey) {

                return $this->slug->findBy(['slug' => $slug, 'category' => 'gallery'], ['target_id', 'target_type'], [
                    'target' => function ($q) {
                        $q->with([
                            'author' => function ($a) {
                                $a->select('id', 'name')->with('avatar:id,name,path,mime_type,target_id');
                            },
                            'images:id,name,path,mime_type,target_id,category',
                            'categories' => function ($c) {
                                $c->with('slugs:target_id,slug,category')->select('id', 'name', 'uid', 'description');
                            },
                            'seo:target_id,title,description,hashtag,facebook,twitter,custom,config',
                        ])->select('id', 'name', 'description', 'content', 'hashtag', 'user_id', 'custom', 'published_at', 'created_at');
                    },
                ]);
            }
        );

        if (!$view || !$view->target) {
            abort(404, $slug . ' does not exist');
        }

        //  Save tracking
        event(new SaveTracking([Constant::PAGE_VIEW, 'gallery', 'gallery-detail'], $view->target_type, $view->target_id));

        return view('pages.gallery', [
            'view' => $view->target,
        ]);
    }

    /**
     * Index show
     *
     * @return Factory|View|Application
     *
     * @author malayvuong
     *
     * @since 6.5.3 - 2022-03-4, 1:8
     */
    public function index(): Factory|View|Application
    {
        $cacheKey = make_key([
            'type' => 'gallery', 'gallery' => 'all', 'page' => request('page', 1),
            'pageSize' => request('limit', 6), 'languageId' => lang()->id,
        ]);
        //  Todo: need to paginate here
        $list = cache()->remember(
            $cacheKey,
            config('setting.cache.gallery', 86400),
            function () use ($cacheKey) {

                return $this->gallery->model()
//              ->where(function ($q) {
//                  $q->where('published_at', null)->orWhere('published_at', '<', now());
//              })
                    ->published()->whereLanguageId(lang()->id)
                    ->with([
                        'image:id,name,path,target_id,mime_type',
                        'slugs:target_id,slug',
                    ])->select('id', 'name', 'description')->latest()->get();
            }
        );

        //  Save tracking
        event(new SaveTracking([Constant::PAGE_VIEW, 'gallery']));

        return view('pages.galleries', ['list' => $list, 'title' => __('nav.a.galleries')]);
    }
}
