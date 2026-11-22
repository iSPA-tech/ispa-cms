<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Slug\StoreSlugRequest;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use App\Services\SlugService;

class SlugController extends AdminController
{
    protected SlugInterface $model;

    protected SlugService $slugService;

    /**
     * Controller constructor
     *
     * @param SlugInterface $model
     * @param SlugService $slugService
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(SlugInterface $model, SlugService $slugService)
    {
        $this->model = $model;
        $this->slugService = $slugService;

        parent::__construct();
    }

    /**
     * Check slug
     *
     * @param StoreSlugRequest $request
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function check(StoreSlugRequest $request): mixed
    {
        return ires()->data($this->slugService->create($request->all()));
    }
}
