<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\Events\SaveTracking;
use Cms\Core\Facades\CMSCache;
use Illuminate\Contracts\View\View;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\Factory;
use Cms\Core\Interfaces\CategoryInterface;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Repositories\Interfaces\SlugInterface;

class HomeController extends Controller
{
    /** Slugs shown at the root address with the plain reader layout, whatever record they point to. */
    private const READER_SLUGS = ['privacy-policy', 'terms-of-service'];

    /**
     * Home controller constructor
     *
     * @param SlugInterface $slug
     * @param CategoryInterface $category
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-03-1, 23:50
     */
    public function __construct(
        protected SlugInterface     $slug,
        protected CategoryInterface $category
    )
    {
        parent::__construct();
    }

    /**
     * Category navigate (level 2)
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-07, 02:04 ICT
     */
    public function cateNavigate(string $cate = null, string $slug = null)
    {
        if (is_null($cate) || is_null($slug)) {
            abort(404, $slug . ' does not exist');
        }

        $cacheKey = make_key(['type' => 'slug', 'cate' => $cate, 'slug' => $slug]);

        $item = cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($slug) {
            return $this->findProductSlug($slug);
        });

        //  Category of product
        if (isset($item->target) && str_contains($item->target_type, '\Product')) {
            //  Redirect to product detail
            return redirect()->route('product.detail', ['slug' => $item->slug]);
            //return $this->product_detail($item->target);
        }

        abort(404, 'This URL not ready for connect.');
    }

    /**
     * The slug served under `/{cate}/{slug}`: only products are handled there (they redirect to the product page), so
     * only a product slug counts; a page or category with the same slug lives at another address.
     */
    protected function findProductSlug(string $slug): mixed
    {
        return $this->slug->findBy(['slug' => $slug, 'category' => 'product'], ['*'], ['target']);
    }

    /**
     * The slug served at the root address `/{slug}`: a page, the privacy/terms reader, or a product category, in that
     * order. Other records may share the slug and live at
     * their own addresses, so they are never picked here, even when newer.
     */
    protected function findRootSlug(string $slug): mixed
    {
        $rows = $this->slug->model()->where('slug', $slug)->with('target')->latest('id')->get();

        return $rows->firstWhere('category', 'page')
            ?? (in_array($slug, self::READER_SLUGS, true) ? $rows->first() : null)
            ?? $rows->first(static fn ($row): bool => $row->category === 'category' && $row->target?->type === 4);
    }

    /**
     * Main navigate of website
     *
     * @param string $slug
     *
     * @return RedirectResponse|Application|Factory|View
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-21, 14:39
     */
    public function navigate(string $slug = ''): RedirectResponse|View|Factory|Application
    {
        if (is_null($slug)) {
            abort(404, $slug . ' does not exist');
        }

        $cacheKey = CacheKeyHelper::slugDetail($slug);

        $slugDetail = CMSCache::rememberRandom($cacheKey, function () use ($slug) {
            return $this->findRootSlug($slug);
        });

        if (empty($slugDetail)) {
            //  Process to check function exists on HomeController, if it's name not exists, then abort 404
            if (method_exists($this, matchingSlugToFunction($slug))) {
                return $this->{matchingSlugToFunction($slug)}();
            }

            abort(404, __('web.abort.404', ['slug' => $slug]));
        }

        //  Check if it is a page
        if ($slugDetail->category === 'page') {
            return $this->showPage($slugDetail->target);
        }

        if (in_array($slugDetail->slug, self::READER_SLUGS, true)) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'article'], $slugDetail->target_type, $slugDetail->target_id));

            return view('pages.reader', [
                'article' => $slugDetail->target,
            ]);
        }
        //  Category of product
        if (!is_null($slugDetail->target) && $slugDetail->target->type === 4 && str_contains($slugDetail->target_type, '\Category')) {
            return $this->product_category($slugDetail->target);
        }

        abort(404, 'This URL not ready for connect.');
    }

    /**
     * Display a listing of the resource
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-01-23, 09:24 ICT
     */
    protected function showPage($pageData): View|Factory|\Illuminate\Foundation\Application
    {
        if (is_object($pageData) && isset($pageData->id)) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'page'], get_class($pageData), (int) $pageData->id));
        }

        return view(config('ispa-cms.folder') . '.pages.page.show', [
            'view' => $pageData,
        ]);
    }

    /**
     * Show category of product
     *
     * @param $target
     *
     * @return Factory|View|Application
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-05, 01:25 ICT
     */
    private function product_category($target): Factory|View|Application
    {
        if (is_null($target)) {
            abort(404);
        }

        //  Load target with other relationship
        $target->load('images');

        //  Process to get product list
//        $products = $this->product->get([
//            'select' => [
//
//            ],
//            'where' => [],
//            'page' => request('pageSize', 12),
//            'latest' => true,
//            'with' => [
//                'image', 'price',
//            ],
//        ]);

        //  Update view of category
        event(new SaveTracking([Constant::PAGE_VIEW, Constant::TRACKING_TYPE_PRODUCT_CATEGORY, Constant::TRACKING_TYPE_CATEGORY], get_class($target), $target->id));

        return view('pages.product-categories', [
            'view' => $target,
            'products' => [],
        ]);
    }

    /**
     * Homepage
     *
     * @return Factory|View|Application
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-21, 14:22
     */
    public function index(): Factory|View|Application
    {
        // CPL: call event SaveTracking to save track
        event(new SaveTracking([Constant::PAGE_VIEW, Constant::HOMEPAGE]));

        return view('index');
    }

    /**
     * Matching slug and return function name
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-27, 16:11 ICT
     */
    protected function matchingSlugToFunction(string $slug): string
    {
        return match ($slug) {
            'about', 'gioi-thieu' => 'about',
            'privacy-policy' => 'privacyPolicy',
            'terms-of-service' => 'termsOfService',
            default => '',
        };
    }
}
