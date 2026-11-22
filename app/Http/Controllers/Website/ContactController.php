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

class ContactController extends Controller
{
    /**
     * Subscribe to newsletter
     *
     * @return Factory|View|Application
     * @author malayvuong
     * @since 6.62.6 - 2023-02-21, 08:05 ICT
     */
    public function subscribe(): Factory|View|Application
    {
        // Tracking
        event(new SaveTracking(['subscribe', Constant::PAGE_VIEW]));

        return view('pages.subscribe', ['title' => __('web.contact.title')]);
    }

    /**
     * Show form contact page
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-02-21, 14:46
     */
    public function show(): Factory|View|Application
    {
        // Tracking
        event(new SaveTracking(['contact', Constant::PAGE_VIEW]));

        return view('pages.contact', ['title' => __('web.contact.title')]);
    }
}
