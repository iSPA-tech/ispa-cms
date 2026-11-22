<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace PJ\Default\Controllers;

use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class HomeController extends Controller
{
    /** Posts on the home: the newest one large, the next six in a grid. */
    private const LATEST_POSTS = 7;

    /**
     * Home of the Default theme: the site's own content.
     */
    public function index(): View
    {
        return view(config('ispa-cms.folder') . 'index', ['posts' => $this->latestPosts()]);
    }

    /**
     * The newest published posts in the current language (Content module, cached), or none when Content is not there.
     */
    private function latestPosts(): Collection
    {
        if (! interface_exists(PostInterface::class) || ! app()->bound(PostInterface::class)) {
            return collect();
        }

        return collect(app(PostInterface::class)->getLatestPosts(self::LATEST_POSTS))->filter()->values();
    }
}
