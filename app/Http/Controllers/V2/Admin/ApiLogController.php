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
use App\Repositories\Interfaces\ApiLogInterface;

class ApiLogController extends AdminController
{
    public ApiLogInterface $model;

    /**
     * Controller constructor
     *
     * @param ApiLogInterface $model
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct(ApiLogInterface $model)
    {
        $this->model = $model;

        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'page' => request('limit', 10),
            'latest' => true,
            'select' => [
                'id', 'request_id', 'type', 'method', 'ip_address', 'user_agent', 'url', 'domain', 'created_at', 'user_id', 'duration',
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return $res;
    }
}
