<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/*
 * Routes of this project. The home (`/`) is registered by routes/web.php for every project, and the Content module
 * serves the post, category and news pages with this project's layout. This file is not loaded while the project is
 * `Default`; a site that copies Default into its own `projects/<Name>` adds its own pages here.
 */

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => '\PJ\\' . config('ispa-cms.folder_name', 'Default') . '\Controllers',
    'middleware' => ['web'],
], static function (): void {
    // Route::get('about', 'PageController@about')->name('about');
});
