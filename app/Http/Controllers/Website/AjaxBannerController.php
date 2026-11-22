<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use Cms\Core\Responses\AppResponse;

use JsonException;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cookie;
use App\Repositories\Interfaces\BannerInterface;

class AjaxBannerController extends Controller
{
    /**
     * Controller constructor
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-17, 21:47 ICT
     */
    public function __construct(
        protected BannerInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Update view count of banner
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-17, 21:42 ICT
     */
    public function view(Request $request): mixed
    {
        $id = $request->input('id');
        $this->model->model()->where('id', $id)->increment('view');

        return ires()->code(201)->message('success');
    }

    /**
     * Update click count of banner
     *
     * @throws JsonException
     * @since 7.160.1175 - 2024-02-17, 21:42 ICT
     * @author malayvuong
     */
    public function click(Request $request): mixed
    {
        $id = $request->input('id');
        $this->model->model()->where('id', $id)->increment('click');

        //  Set cookie to prevent click again
        $this->setCookie($id);

        return ires()->code(201)->message('success');
    }

    /**
     * Set cookie for banner close
     *
     * @param int $id
     *
     * @throws JsonException
     * @since 7.160.1175 - 2024-02-18, 11:17 ICT
     * @author malayvuong
     */
    protected function setCookie(int $id = 0): void
    {
        //  Set cookie to prevent click again
        $bannerClose = json_decode(Cookie::get('banner_close', '{}'), true, 512, JSON_THROW_ON_ERROR);

        $bannerClose[$id] = now()->addDay()->timestamp;
        //  Set cookie forever
        Cookie::queue('banner_close', json_encode($bannerClose, JSON_THROW_ON_ERROR), 525600);
    }

    /**
     * Update close of banner
     *
     * @throws JsonException
     * @since 7.160.1175 - 2024-02-17, 21:42 ICT
     * @author malayvuong
     */
    public function close(Request $request): mixed
    {
        $id = $request->input('id');

        //  Set cookie to prevent click again
        $this->setCookie($id);

        return ires()->code(201)->message('success');
    }
}
