<?php

use Illuminate\Support\Facades\Route;
use Cms\Content\Http\Controllers\V2\Admin\PostController;
use Cms\Content\Http\Controllers\V2\Admin\ContentController;
use Cms\Content\Http\Controllers\V2\Admin\DashboardController;
use Cms\Content\Http\Controllers\V2\Admin\TagController;


Route::group(['prefix' => 'v2', 'as' => 'v2.'], static function () {
    // Admin APIs routes
    Route::prefix('admin')->group(static function () {
        Route::middleware(['auth:sanctum', 'api.admin', 'throttle:500,1'])->group(function () {
            //  Content
            Route::prefix('content')->controller(ContentController::class)->group(function () {
                Route::put('{uuid}/quick', 'quickUpdate')->name('content.quick');
                Route::put('{uuid}/confirmation', 'confirmation');
                Route::put('{uuid}/translate', 'translateManual');
                Route::put('{uuid}/convert', 'convertAudio');
                Route::post('translate/{id}/{language}', 'translate')->whereNumber('id')->where('language', '[a-z]+');
                Route::post('audio/{id}', 'audio')->whereNumber('id');
            });
            Route::resource('content', ContentController::class)->except(['create'])->parameters(['content' => 'uuid']);
            //  Admin Place
            Route::controller(DashboardController::class)->prefix('dashboard')->group(function () {
                Route::get('post', 'postDashboard');
            });
            //  Admin post
            Route::controller(PostController::class)
                ->prefix('post')->group(function () {
                    Route::get('search', 'search');
                    Route::get('menu', 'getMenu');
                    Route::prefix('crawler')->group(static function () {
                        Route::post('/', 'crawlData');
                        Route::post('translate', 'translate');
                        Route::post('save', 'saveCrawlData');
                    });
                    Route::put('{uuid}/quick', 'quickUpdate')->where('uuid', '[a-zA-Z0-9-]+');
                    Route::put('{id}/update', 'switch')->where('id', '\d+');
                    Route::put('{id}/restore', 'restore');
                });
            Route::resource('post', PostController::class)->except(['create']);
            //  Admin tag (moved from cms/Core/routes/api.php on 2026-11-21; same URIs, names and middleware)
            Route::get('tag/search', [TagController::class, 'search']);
            // UUID-first; no `create` form route.
            Route::resource('tag', TagController::class)->except(['create'])->parameters(['tag' => 'uuid'])
                ->where(['uuid' => '[0-9a-fA-F-]{36}']);
        });
    });
});
