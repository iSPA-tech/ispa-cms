<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Support\Modules\ModuleStatus;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\System\ApiRootController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use Cms\RealEstate\Http\Controllers\RealEstateMapController;
use App\Http\Controllers\V2\Admin\LibraryScanController;
use App\Http\Controllers\V2\Admin\RuntimeCacheMaintenanceController;

$moduleIsEnabled = static function (string $module): bool {
    return ModuleStatus::isEnabled($module);
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/** Get locale json file */
Route::get('mail/live/{uid}', 'MailController@show')->where('uid', '[a-zA-Z0-9-]+')->name('mail-live');
Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);

if ($moduleIsEnabled('RealEstate')) {
    Route::get('real-estate/projects/map', [RealEstateMapController::class, 'index']);
}

Route::group(['prefix' => 'v3', 'namespace' => 'V3', 'as' => 'v3.'], static function () {
    Route::group(['middleware' => ['api.verify']], static function () {
        //  Authenticator
        Route::group([
            'prefix' => 'auth',
            'namespace' => 'Auth',
            'as' => 'auth.',
        ], static function () {
            Route::post('sign-in', 'Authenticator@signIn')->name('sign-in');
        });

        Route::group([
            'namespace' => 'Public',
            'middleware' => ['throttle.route:10,1', 'recaptcha'],
            'as' => 'contact.',
            'controller' => 'ContactController',
        ], static function () {
            Route::post('contact', 'store')->name('store');
        });

        Route::group([
            'prefix' => 'me',
            'namespace' => 'Auth',
            'as' => 'me.',
            'middleware' => ['auth:sanctum', 'api.audience:service', 'throttle.route:20,1'],
        ], static function () {
            Route::post('refresh', 'Authenticator@refresh')->name('refresh');
        });

        Route::group([
            'namespace' => 'Public',
        ], static function () {
            Route::group([
                'prefix' => 'static',
                'as' => 'static.',
                'controller' => 'StaticController',
            ], static function () {
                Route::get('constants', 'constants')->name('constants');
                Route::get('routes', 'routes')->name('routes');
                Route::get('settings', 'settings')->name('settings');
                Route::get('main-menu', 'mainMenu')->name('main-menu');
                Route::get('footer-menu', 'footerMenu')->name('footer-menu');
            });

            //  Public view tracking ingest for the headless frontend. POST only,
            //  so the loopback rate-limit bypass (GET-only) never applies here.
            Route::post('track', 'TrackController@store')
                ->middleware('throttle:tracking-ingest')
                ->name('track.store');

        });
    });
    //  Admin API
    Route::group(['prefix' => 'admin', 'namespace' => 'Admin'], static function () {
        //  Authenticated routers
        // Staff accounts with an admin or a v3 service token (, token inventory 5.4)
        Route::group(['middleware' => ['auth:sanctum', 'api.admin:admin,service']], static function () {
            Route::prefix('category')->controller('CategoryController')->group(function () {
                Route::get('/', 'index');
                Route::get('{uid}/edit', 'edit')->where('uid', '[a-zA-Z0-9-]+');
                Route::put('{uid}', 'update')->where('uid', '[a-zA-Z0-9-]+');
                Route::post('', 'store');
            });
            Route::prefix('language')->controller('LanguageController')->group(function () {
                Route::get('/', 'index');
            });
            Route::prefix('upload')->controller('UploadController')->group(function () {
                Route::get('/', 'index');
                Route::post('image', 'uploadImage');
            });
        });
    });
});

Route::group(['prefix' => 'v2', 'namespace' => 'V2'], static function () {
    /** Public API */
    Route::group(['prefix' => 'public', 'namespace' => 'Public', 'middleware' => ['api.verify']], static function () {
        Route::controller('BackendGatewayController')->prefix('backend')->group(static function () {
            Route::get('/', 'index');
        });
        Route::controller('BackendGatewayController')->prefix('categories')->group(static function () {
            Route::get('/', 'categories');
        });
        Route::controller('MetaController')->prefix('meta')->group(static function () {
            Route::get('user', 'user');
        });
        //  Contact with throttle middleware to prevent spam attack from bots
        Route::controller('ContactController')->prefix('contact')
            ->middleware(['throttle.route:10,1'])->group(static function () {
                Route::post('/', 'store');
            });

        /** Customer Mobile */
        Route::controller('MobileCustomer')->prefix('customer')->group(function () {
            Route::get('categories/{id}', 'show');
            Route::get('categories', 'categories');
        });
    });

    //  Client API
    Route::group(['prefix' => 'client', 'namespace' => 'Client', 'middleware' => ['api.verify', 'api.log']], static function () {
        /** No sanctum middleware */
        Route::group(['prefix' => 'auth', 'namespace' => 'Auth', 'middleware' => ['throttle.route:10,1']], static function () {
            Route::post('sign-in', 'Authenticator@signIn');
        });
        /** Sanctum middleware */
        //  Group budgets below carry their own key prefix: unnamed throttle:N,M
        //  limits share one per-user/IP counter (see ThrottlePerRoute).
        Route::group(['middleware' => ['auth:sanctum', 'api.audience:client', 'throttle:20,1,v2-client:']], static function () {
            Route::controller('AccountController')->prefix('account')->group(static function () {
                Route::get('me', 'myAccount');
                Route::post('sign-out', 'signOut');
                Route::post('update', 'update');
            });
            Route::controller('AccountController')->prefix('account')->group(static function () {
                Route::post('sign-out', 'signOut');
                Route::post('update', 'update');
            });
            Route::controller('TranslateController')->prefix('translate')->group(static function () {
                Route::post('text', 'text');
            });
        });
    });

    //  Admin API
    Route::group(['prefix' => 'admin', 'namespace' => 'Admin', 'middleware' => [
        'api.admin.middleware', 'throttle:500,1',
    ]], static function () {
        /** No sanctum middleware */
        Route::group(['prefix' => 'auth', 'namespace' => 'Auth', 'controller' => 'Authenticator'], static function () {
        });

        //  Logged in router
        //  Own key prefixes here and on runtime-cache: stacked under the outer
        //  admin throttle, unnamed limits counted every request two or three times.
        Route::group(['middleware' => ['auth:sanctum', 'api.admin', 'throttle:500,1,v2-admin-signed-in:']], static function () {
            Route::controller(RuntimeCacheMaintenanceController::class)
                ->prefix('runtime-cache')
                ->middleware('throttle:3,1,v2-runtime-cache:')
                ->group(static function () {
                    Route::post('clear', 'clear')->name('admin.runtime-cache.clear');
                    Route::post('optimize', 'optimize')->name('admin.runtime-cache.optimize');
                });

            Route::controller(LibraryScanController::class)->prefix('library-scan')->group(static function () {
                Route::post('scan', 'scan');
                Route::post('books', 'storeBook');
            });

            //  Admin API Logs
            Route::resource('api-logs', 'ApiLogController')->only(['index', 'show']);
            //  Admin Banner
            Route::resource('banner', 'BannerController')->except(['create']);
            //  Admin calendar
            Route::resource('calendar', 'CalendarController')->except(['create']);
            //  Admin Comment
            Route::get('comment-settings', 'CommentController@settings');
            Route::put('comment-settings', 'CommentController@saveSettings');
            Route::put('comment/{id}/status', 'CommentController@setStatus')->where('id', '\d+');
            Route::put('comment/{id}/confirm', 'CommentController@confirm')->where('id', '\d+');
            Route::put('comment/{id}/restore', 'CommentController@restore')->where('id', '\d+');
            Route::resource('comment', 'CommentController')->except(['create']);
            //  Admin contact
            Route::prefix('contact')->controller('ContactController')->group(function () {
                Route::get('search', 'search');
                Route::get('counter', 'counter');
                Route::get('dashboard', 'dashboard');
                Route::post('reply', 'replyMail');
                Route::post('import', 'import');
                Route::get('export', 'export');
            });
            Route::resource('contact', 'ContactController')->except(['create']);
            //Admin Gallery
            Route::put('gallery/{id}/quick', 'GalleryController@quickUpdate');
            Route::resource('gallery', 'GalleryController');
            //  Admin province
            Route::prefix('province')->controller('ProvinceController')->group(function () {
                Route::get('search', 'search');
                Route::get('getFolderTree/{id}', 'getFolderTree');
                Route::get('tree/{id}', 'getList');
                Route::put('{id}/switch', 'switch')->where('id', '\d+');
            });
            Route::resource('province', 'ProvinceController')->except(['show', 'create']);
            Route::resource('scheduler', 'SchedulerController')->except(['show', 'create']);

            //  Admin slug
            Route::prefix('slug')->controller('SlugController')->group(function () {
                Route::post('check-slug', 'check');
                Route::post('check', 'check');
            });
            Route::resource('slug', 'SlugController')->except(['show', 'create']);
            //  Admin dashboard summary (aggregate-only)
            Route::get('dashboard/summary', 'DashboardSummaryController@summary');
            Route::get('my-tasks', 'MyTasksController@index');
            //  Admin Tracking
            Route::prefix('track')->controller('TrackController')->group(function () {
                Route::post('chart', 'chart');
                Route::post('dashboard', 'dashboard');
                Route::post('get-track', 'getTrack');
                Route::get('top-targets', 'topTargets');
            });
            Route::resource('track', 'TrackController')->except(['create', 'show', 'update', 'massDestroy']);

            Route::get('workflows/function-list', '\\Cms\\Core\\Http\\Controllers\\V2\\Admin\\WorkflowProjectController@functionList');
            Route::resource('workflows', '\\Cms\\Core\\Http\\Controllers\\V2\\Admin\\WorkflowProjectController')->except(['show', 'create']);

            Route::prefix('workflow')->group(function () {
                Route::post(
                    'expression/validate',
                    '\\Cms\\Core\\Http\\Controllers\\Workflow\\WorkflowExpressionController@validateExpression'
                );
                Route::post(
                    'template/preview',
                    '\\Cms\\Core\\Http\\Controllers\\Workflow\\WorkflowExpressionController@previewTemplate'
                );

                Route::get('schema', '\\Cms\\Core\\Http\\Controllers\\Workflow\\ModelSchemaController@schema');
                Route::get('schema/relation', '\\Cms\\Core\\Http\\Controllers\\Workflow\\ModelSchemaController@relation');
                Route::get('models', '\\Cms\\Core\\Http\\Controllers\\Workflow\\ModelSchemaController@models');
                Route::get('node-catalog', '\\Cms\\Core\\Http\\Controllers\\Workflow\\NodeCatalogController@index');
            });

            Route::prefix('workflow/instances')->group(function () {
                // Numeric run ids (workflow tables have no uuid column; see guidelines/backend-data-contracts.md).
                Route::get('{id}', '\\Cms\\Core\\Http\\Controllers\\Workflow\\WorkflowInstanceController@show')->whereNumber('id');
                Route::get('{id}/steps', '\\Cms\\Core\\Http\\Controllers\\Workflow\\WorkflowInstanceController@steps')->whereNumber('id');
            });
        });
    });
});

Route::get('/', ApiRootController::class);
