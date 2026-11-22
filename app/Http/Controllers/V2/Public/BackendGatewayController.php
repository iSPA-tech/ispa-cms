<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Public;

use Cms\Core\Responses\AppResponse;

use JsonException;
use Cms\Core\Helpers\RedisHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Http\Resources\Website\PublicMenuItemCollection;
use Cms\Core\Http\Resources\Website\PublicCategoryCollection;

class BackendGatewayController extends AdminController
{
    /**
     * Controller constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-03, 08:28 ICT
     */
    public function __construct(
        protected MenuInterface     $menu,
        protected CategoryInterface $category,
    )
    {
        parent::__construct();
    }

    /**
     * Get website category by type
     *
     * @param Request $request
     *
     * @return mixed
     * @throws JsonException
     * @since 6.62.6 - 2023-03-03, 08:26 ICT
     * @author malayvuong
     */
    public function categories(Request $request): mixed
    {
        $langPathAlias = lang('path', 'vi');
        $categoryType = $request->input('type', 'article');
        $redisKey = 'categories.' . $categoryType . '.' . $langPathAlias;

        $categories = Redis::get($redisKey);

        if (!$categories) {
            $categoryList = $this->category->getAllPublicCategoriesByType($categoryType, lang('id', get_lang_id()));

            //  Make multiple level for $menu->items
            //  multiLevel() is a global helper, not a controller method, so
            //  `$this->` raised "Method BackendGatewayController::multiLevel
            //  does not exist" and this endpoint answered 500 on every call.
            $categoryItems = multiLevel($categoryList);

            $categories = new PublicCategoryCollection($categoryItems);

            RedisHelper::set($redisKey, $categories);
        }

        return ires()->data($categories);
    }

    /**
     * Get website information
     *
     *
     * @return mixed
     * @throws JsonException
     * @since 6.62.6 - 2023-02-23, 13:36 ICT
     * @author malayvuong
     */
    public function index(): mixed
    {
        $data = RedisHelper::websiteSetting();

        return ires()->data($data);
    }
}
