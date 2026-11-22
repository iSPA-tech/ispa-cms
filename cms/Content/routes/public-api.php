<?php

use Illuminate\Support\Facades\Route;
use Cms\Content\Http\Controllers\V2\Client\BookmarkController as ClientBookmarkController;
use Cms\Content\Http\Controllers\V2\Client\PostController as ClientPostController;
use Cms\Content\Http\Controllers\V2\Public\ArticleController as PublicArticleController;
use Cms\Content\Http\Controllers\V2\Public\PostController as PublicPostController;
use Cms\Content\Http\Controllers\V2\Public\ReaderController;
use Cms\Content\Http\Controllers\V3\Admin\PostController as V3AdminPostController;
use Cms\Content\Http\Controllers\V3\Public\CategoryController;
use Cms\Content\Http\Controllers\V3\Public\PostController as V3PublicPostController;
use Cms\Content\Http\Controllers\V3\Public\TagController;

/*
|--------------------------------------------------------------------------
| Content read APIs (moved from routes/api.php,)
|--------------------------------------------------------------------------
| Mapped by the Content RouteServiceProvider under the `api` middleware and
| `api` prefix WITHOUT the `api.` name prefix of routes/api.php, so URLs,
| route names (`v3.category.index`, ...) and middleware stay identical.
*/

Route::group(['prefix' => 'v3', 'as' => 'v3.'], static function () {
    Route::group(['middleware' => ['api.verify']], static function () {
        Route::group(['prefix' => 'category', 'as' => 'category.', 'controller' => CategoryController::class], static function () {
            Route::get('/', 'index')->name('index');
            Route::get('all', 'getAll')->name('all');
            Route::get('featured', 'featuredCategories')->name('featured-categories');
            Route::get('{uid}', 'show')->name('show');
            Route::get('{uid}/post', 'post')
                ->name('post')->where('uid', '[a-zA-Z0-9]+');
            Route::get('{slug}/tree-with-post', 'treeWithPost')
                ->name('tree-with-post')->where('slug', '[a-zA-Z0-9-]+');
            Route::get('{slug}/all-posts-of-tree', 'allPostsOfTree')
                ->name('all-posts-of-tree')->where('slug', '[a-zA-Z0-9-]+');
        });

        Route::group(['prefix' => 'post', 'as' => 'post.', 'controller' => V3PublicPostController::class], static function () {
            Route::get('all', 'getAll')->name('all');
            Route::get('latest', 'latestPosts')->name('latest-posts');
            Route::get('featured', 'featuredPosts')->name('featured-posts');
            Route::get('recommended', 'recommendedPosts')->name('recommended-posts');
            Route::get('{uuid}', 'show')->name('show');
            Route::get('slug/{slug}', 'detailBySlug')->name('detail-by-slug');
            Route::get('about/{slug}', 'about')
                ->name('about')
                ->where('slug', '[a-zA-Z0-9-]+');
        });

        Route::group(['prefix' => 'tag', 'as' => 'tag.', 'controller' => TagController::class], static function () {
            Route::get('{slug}', 'show')->name('show')->where('slug', '[a-zA-Z0-9-]+');
            Route::get('/', 'index')->name('index');
        });
    });

    //  Admin API
    Route::group(['prefix' => 'admin'], static function () {
        Route::group(['middleware' => ['auth:sanctum', 'api.admin:admin,service']], static function () {
            Route::prefix('post')->controller(V3AdminPostController::class)->group(function () {
                Route::get('/', 'index');
            });
        });
    });
});

Route::group(['prefix' => 'v2'], static function () {
    /** Public API */
    Route::group(['prefix' => 'public', 'middleware' => ['api.verify']], static function () {
        //  Post/Articles
        Route::controller(PublicPostController::class)->prefix('article')->group(static function () {
            Route::get('/', 'index');
            Route::get('{uid}', 'show')->where('uid', '[a-zA-Z0-9-]+');
            Route::get('{categorySlug}/{postSlug}', 'show')
                ->where('categorySlug', '[a-zA-Z0-9-]+')
                ->where('postSlug', '[a-zA-Z0-9-]+');
        });

        Route::group(['prefix' => 'view'], static function () {
            Route::get('about-us', [ReaderController::class, 'about_us']);
            Route::get('privacy-policy', [ReaderController::class, 'privacy_policy']);
            Route::get('terms-of-service', [ReaderController::class, 'terms_of_service']);
            /** Web view article */
            Route::get('article/{uid}', [PublicArticleController::class, 'article_view'])->name('api-web-view-article')->where('uid', '[a-zA-Z0-9-]+');
            Route::get('article/{uid}/json', [PublicArticleController::class, 'article_json'])->name('api-web-json-article')->where('uid', '[a-zA-Z0-9-]+');
        });
    });

    //  Client API
    Route::group(['prefix' => 'client', 'middleware' => ['api.verify', 'api.log']], static function () {
        Route::group(['middleware' => ['auth:sanctum', 'api.audience:client', 'throttle:20,1,v2-client:']], static function () {
            //  Get data by uuid of user
            Route::prefix('{uuid}')->group(function () {
                Route::controller(ClientPostController::class)->prefix('post')->group(function () {
                    Route::get('/', 'index');
                    Route::get('{uid}', 'show')->where('uid', '[a-zA-Z0-9-]+');
                });
            })->whereUuid('uuid');

            //  Reader bookmarks ("Lưu") — alias + uuid vocabulary, morph rows
            //  in the shared bookmarks table.
            Route::controller(ClientBookmarkController::class)->prefix('bookmark')->group(static function () {
                Route::get('/', 'index');
                Route::get('status', 'status');
                Route::post('toggle', 'toggle');
            });
        });
    });
});
