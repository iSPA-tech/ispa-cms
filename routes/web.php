<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Support\Modules\ModuleStatus;
use Illuminate\Support\Facades\Route;
use App\Services\TemplatePreviewService;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RuntimeModuleAssetController;
use App\Http\Controllers\Website\AuthController as WebsiteAuthController;
use App\Http\Controllers\System\AdminPanelController;
use App\Http\Controllers\System\HealthCheckController;
use App\Http\Controllers\System\ProjectAssetController;
use Cms\GameAdmin\Http\Controllers\HealthController;

$moduleIsEnabled = static function (string $module): bool {
    return ModuleStatus::isEnabled($module);
};

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

//--------------------Default Router, please don't delete
//--------------------Router mặc định, vui lòng không xóa
//  Language Route
Route::get('lang/{locale}', 'Website\LocaleController@index')->name('setLocale');
//  Maintenance Mode
Route::get('maintenance', 'Controller@maintenance')->name('maintenance');

Route::post('auth/sign-in', [WebsiteAuthController::class, 'postSignIn'])
    ->name('auth.postSignIn')
    ->middleware(['recaptcha', 'throttle:web-login']);
//  Link to storage

if ($moduleIsEnabled('GameAdmin') && class_exists(HealthController::class)) {
    Route::get('/health', [HealthController::class, 'show'])->name('health');
} else {
    Route::get('/health', HealthCheckController::class)->name('health');
}
// Link a path to public/projects/{project_folder}/{path}
Route::get('projects/' . config('ispa-cms.folder_name', 'Default') . '/{path}', ProjectAssetController::class)
    ->where('path', '.*')->name('link-folder');
// SVG files of runtime-installed modules, served with nosniff and a script-blocking CSP; every other
// published runtime asset is a static file under public/runtime-assets. Stateless: no session or cookies.
Route::get('runtime-assets/{module}/{version}/{path}', RuntimeModuleAssetController::class)
    ->where('path', '.+\\.[sS][vV][gG]')->withoutMiddleware('web')->name('runtime-module-asset');
// End default Router
// ----------------------------------------------------------------------
// Return default template if not set
if (app()->environment('local')) {
    Route::get('/preview/{path?}', static function (TemplatePreviewService $previewer, $path = 'index') {
        return response($previewer->render($path));
    })->where('path', '.*');
    Route::get('/preview-browser', static function () {
        $base = base_path('projects/' . config('ispa-cms.folder_name') . '/dev/template');

        $files = collect(File::allFiles($base))
            ->filter(fn($f) => $f->getExtension() === 'php')
            ->map(function ($file) use ($base) {
                $relative = str_replace(['\\', $base . DIRECTORY_SEPARATOR, '.blade.php'], ['/', '', ''], $file->getPathname());
                return [
                    'name' => $relative,
                    'url' => url("/preview/{$relative}"),
                ];
            });

        return view('preview.browser', ['files' => $files]);
    });

}

Route::get('/i{p}anel{any}', AdminPanelController::class)
    ->where('p', '[pP]+')->where('any', '.*?');
Route::get('/i{p}anel', AdminPanelController::class)
    ->where('p', '[pP]+');

Route::group(['namespace' => '\PJ\\' . config('ispa-cms.folder_name', 'Default') . '\Controllers'], static function () {
    Route::controller('HomeController')->group(function () {
        Route::get('/', 'index')->name('home');
    });
});

if (config('ispa-cms.folder_name', 'Default') !== 'Default') {
    /** Ajax-internal request */
    Route::namespace('Website')
        ->prefix('ajax')->as('ajax.')
        ->group(function () {
            Route::controller('AjaxController')->group(function () {
                Route::get('language', 'languages');
                Route::post('maps-search', 'mapSearch');
                Route::post('comment-request', 'storeComment')->name('comment-request')->middleware('recaptcha');
                Route::post('contact-request', 'storeContact')->name('contact-request')->middleware('recaptcha');
                Route::post('register', 'register')->name('register-account')->middleware('recaptcha');
                Route::post('sign-in', 'signIn')->name('sign-in')->middleware(['recaptcha', 'throttle:web-login']);
                Route::post('get-provinces', 'getProvinces')->name('get-provinces');
            });
            Route::controller('AjaxBannerController')->as('banner.')->group(function () {
                Route::put('view', 'view')->name('view');
                Route::put('click', 'click')->name('click');
                Route::put('close', 'close')->name('close');
            });
        });

    // A project that serves its own category pages turns this catch-all off from its provider (`ispa-cms.routes.category_navigation`).
    if (config('ispa-cms.routes.category_navigation', true) === true) {
        Route::get('{cate}/{slug}', 'HomeController@cateNavigate')->name('cate-navigate')->where('cate', '[a-zA-Z0-9-]+')->where('slug', '[a-zA-Z0-9-]+');
    }

    Route::fallback([HomeController::class, 'navigate'])->name('navigate');
} else {
    Route::controller('Website\MediaController')->prefix('media')->group(function () {
        Route::get('audio/{slug}', 'audio')->name('audio' . lang('path'))->where('slug', '[a-zA-Z0-9-]+');
        Route::get('video/{slug}', 'audio')->name('video' . lang('path'))->where('slug', '[a-zA-Z0-9-]+');
        Route::get('document/{slug}', 'audio')->name('document' . lang('path'))->where('slug', '[a-zA-Z0-9-]+');
    });
    // No catch-all: an unknown address on a Default site is a 404. It used to render the Default home for every path,
    // which became a 500 once the home lists the site's posts (2026-11-19).
}
