<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers;

use Illuminate\Routing\Redirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /** @var bool $freshCache */
    public bool $freshCache = false;
    /** @var array|string[] $breadcrumbs */
    public array $breadcrumbs = [];

    /**
     * Controller constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 15:09 ICT
     */
    public function __construct()
    {
        $this->freshCache = (bool) config('ispa-cms.cache_enable', false);
        $this->breadcrumbs[] = ['url' => route('home'), 'title' => __('nav.a.home')];
    }

    /**
     * Maintenance Mode
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-21, 2:31
     * @last_updated 6.47.5 - 2022-08-13, 02:04 ICT - by malayvuong
     */
    public function maintenance(): View|Factory|Redirector|RedirectResponse|Application
    {
        if (!config('ispa-cms.maintenance')) {
            return redirect(route('home'));
        }

        return view('maintenance');
    }
}
