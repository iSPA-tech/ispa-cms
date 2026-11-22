<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\Website;

use App\Constants\Constant;
use App\Enums\StatusEnum;
use App\Events\SaveTracking;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\View\Factory;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;

class BlogController extends Controller
{
    /**
     * BlogController constructor
     *
     * @param PostInterface $post
     * @param CategoryInterface $category
     * @param SlugInterface $slug
     * @param CategoryTargetInterface $target
     *
     * @author malayvuong
     * @since 6.5.1 - 2022-02-28, 11:54
     */
    public function __construct(
        protected PostInterface           $post,
        protected CategoryInterface       $category,
        protected SlugInterface           $slug,
        protected CategoryTargetInterface $target
    )
    {
        parent::__construct();
    }

    /**
     * Reader article non category
     *
     * @param string $slug
     *
     * @return Factory|View|Application
     * @author malayvuong
     * @since 1.0.0 - 2022-03-2, 20:58
     */
    public function reader(string $slug): Factory|View|Application
    {
        if (!$slug) {
            abort(404, $slug . ' does not exist');
        }

        $target = $this->slug->findBy(['slug' => $slug, 'target_type' => get_class($this->post->model())], ['target_id', 'target_type'], [
            'target' => function ($q) {
                $q->with('categories:id,name,description');
            },
        ]);

        if (!$target || !$target->target) {
            abort(404, $slug . ' does not exist');
        }

        event(new SaveTracking([Constant::PAGE_VIEW, 'article'], get_class($this->post->model()), $target->target_id));

        return $this->pageView('reader', [
            'view' => $target->target,
        ]);
    }

    /**
     * News post
     *
     * @return Factory|View|Application
     * @author malayvuong
     *
     * @since 6.5.3 - 2022-03-2, 9:9
     */
    public function news(): Factory|View|Application
    {
        //  Set parent breadcrumb
        $this->breadcrumbs[] = ['title' => __('nav.a.posts')];
        if (request()->has('page')) {
            $this->breadcrumbs[] = ['title' => __('pagination.page') . ' ' . request('page', 1)];
        }

        //  Make cache key
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_NEWS,
            Constant::CACHE_KEY_NEWS => 'all-news',
            'page' => request('page', 1),
            'limit' => request('limit', 12),
        ]);

        // Todo: make cache Tag here
        $news = cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey) {

            return $this->post->model()->select(
                'id', 'name', 'description', 'category_id',
            )
                ->where([
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                    'type' => 'post',
                ])->where(function ($q) {
                    $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                })->with([
                    'category:id,name',
                    'slugs:target_id,slug',
                ])->whereHas('category')->orderBy('published_at', 'DESC')->orderBy('id', 'desc')->paginate(request('limit', 12));
        });


        event(new SaveTracking([Constant::PAGE_VIEW, 'news']));

        return $this->pageView('news', [
            'posts' => $news ?? null,
            'links' => isset($news) ? $news->appends($_GET)->onEachSide(1)->links() : null,
            'breadcrumbs' => $this->breadcrumbs,
        ]);
    }

    /**
     * Show category detail
     *
     * @param string $slug
     *
     * @return Factory|View|Application
     *
     * @author malayvuong
     * @since 6.5.1 - 2022-03-1, 17:26
     */
    public function category(string $slug): Factory|View|Application
    {
        if (!$slug) {
            abort(404);
        }
        //  Set parent breadcrumb
        $this->breadcrumbs[] = ['title' => __('nav.a.posts')];

        //  Process to check function exists on HomeController, if it's name not exists, then abort 404
        if (($slug === 'glossary') && method_exists($this, matchingSlugToFunction($slug))) {
            return $this->{matchingSlugToFunction($slug)}('a');
        }

        $category = $this->category->getCategoryBySlugWithPosts($slug, $this->freshCache);

        if (empty($category)) {
            abort(404, __('mgs.url.404'));
        }

        //  Make breadcrumbs
        $this->breadcrumbs[] = ['title' => $category->name];

        $page = request('page', 1);
        if ($page > 1) {
            $this->breadcrumbs[] = ['title' => __('pagination.page') . ' ' . $page];
        }

        event(new SaveTracking([Constant::PAGE_VIEW, 'category'], get_class($category), $category->id));

        return $this->pageView('category', [
            'view' => $category,
            'links' => isset($category->append_posts) ? $category->append_posts->appends($_GET)->onEachSide(1)->links() : null,
            'breadcrumbs' => $this->breadcrumbs,
        ]);
    }

    /**
     * Show Blog detail
     *
     * @param string $cate
     * @param string $slug
     *
     * @return Factory|View|Application|RedirectResponse
     * @author malayvuong
     * @since 6.5.1 - 2022-02-28, 11:56
     */
    public function article(string $cate, string $slug): Factory|View|Application|RedirectResponse
    {
        if (!$slug || !$cate) {
            abort(404);
        }
        $post = $this->post->getPostDetailBySlugAndCategorySlug($slug, $cate, 'post', $this->freshCache);
        //  Set parent breadcrumb
        $this->breadcrumbs[] = ['title' => __('nav.a.posts')];

        if (empty($post)) {
            return $this->redirectToDocument($cate, $slug) ?? abort(404, __('mgs.url.404'));
        }

        //  Set breadcrumbs
        if (isset($post->category)) {
            $this->breadcrumbs[] = [
                'title' => $post->category->name,
                'url' => route('category.' . lang('path', 'vi'), $post->category->slug),
            ];
        }
        $this->breadcrumbs[] = ['title' => $post->name];

        event(new SaveTracking([Constant::PAGE_VIEW, 'article'], get_class($this->post->model()), $post->id));

        //  Reset the app language if it's different from the post language
        if ($post->language_id > 0 && lang()->id !== $post->language_id) {
            return redirect(route('setLocale', ['locale' => $post->language->path]));
        }


        return $this->pageView('article', [
            'view' => $post,
            'cate' => $post->category,
            'breadcrumbs' => $this->breadcrumbs,
        ]);
    }

    /**
     * A document opened through an article URL (`/bai-viet/{cate}/{slug}`, e.g. a link copied by hand) answers 301 to
     * the document page, on sites whose project declares `document.show.<lang>` routes. Other sites keep the 404.
     *
     * @param string $cate
     * @param string $slug
     *
     * @return RedirectResponse|null
     */
    protected function redirectToDocument(string $cate, string $slug): ?RedirectResponse
    {
        $routeName = 'document.show.' . lang('path', 'vi');
        if (!Route::has($routeName)) {
            return null;
        }

        $document = $this->post->getPostDetailBySlugAndCategorySlug($slug, $cate, 'document', $this->freshCache);
        $categorySlug = $document?->category?->slugs?->first()?->slug;
        if (empty($document) || empty($categorySlug)) {
            return null;
        }

        return redirect()->route($routeName, ['cate' => $categorySlug, 'slug' => $slug], 301);
    }

    /**
     * Render a public blog page: the project's own template first, then the module default that extends the project
     * layout. A project without that layout cannot render module pages, so the request is a 404, never a missing-view
     * 500.
     *
     * @param string $page One of news, reader, category, article.
     * @param array<string, mixed> $data
     *
     * @return Factory|View|Application
     */
    protected function pageView(string $page, array $data): Factory|View|Application
    {
        $folder = (string) config('ispa-cms.folder');
        $projectView = $folder . 'pages.' . $page;
        if (view()->exists($projectView)) {
            return view($projectView, $data);
        }

        $moduleView = 'content::pages.' . $page;
        if (view()->exists($folder . 'layout.index') && view()->exists($moduleView)) {
            return view($moduleView, $data);
        }

        abort(404);
    }

}
