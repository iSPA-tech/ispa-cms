<?php

use Illuminate\Support\Facades\Route;
use Cms\Content\Http\Controllers\Website\BlogController;
use Cms\Content\Http\Controllers\Website\BookmarkAjaxController;
use Cms\Content\Http\Controllers\Website\ContentAjaxController;

Route::middleware(['auth', 'verified'])->group(function () {

});

/*
|--------------------------------------------------------------------------
| Public blog pages (moved from routes/web.php,)
|--------------------------------------------------------------------------
| URLs, route names and where-constraints are unchanged. Route files of
| modules load before routes/web.php, so these precede the project
| catch-all (`{cate}/{slug}`) and the fallback exactly as before.
*/
Route::controller(BlogController::class)
    ->prefix('blog')->group(function () {
        Route::get('{cate}/{slug}', 'article')->name('article.en')
            ->where('slug', '[a-zA-Z0-9-]+')->where('cate', '[a-zA-Z0-9-]+');
        Route::get('{slug}', 'category')->name('category.en')
            ->where('blog', '[{blog|bai-viet}]+')->where('slug', '[a-zA-Z0-9-]+');
    })
    ->prefix('bai-viet')->group(function () {
        Route::get('{cate}/{slug}', 'article')->name('article.vi')
            ->where('slug', '[a-zA-Z0-9-]+')->where('cate', '[a-zA-Z0-9-]+');
        Route::get('{slug}', 'category')->name('category.vi')
            ->where('blog', '[{blog|bai-viet}]+')->where('slug', '[a-zA-Z0-9-]+');
    });

// Every site, Default included: the Default theme has the layout these pages extend (2026-11-19). A project without
// one gets a 404 from BlogController::pageView, never a 500.
Route::get('/news', [BlogController::class, 'news'])->name('news');
Route::get('/about/{slug}', [BlogController::class, 'reader'])->name('reader');

if (config('ispa-cms.folder_name', 'Default') !== 'Default') {
    Route::post('ajax/save-bookmark', [BookmarkAjaxController::class, 'saveBookmark'])->name('ajax.save-bookmark');
    // Moved from routes/web.php (app AjaxController) on 2026-11-21; URI and name unchanged.
    Route::get('ajax/content-view', [ContentAjaxController::class, 'contentView'])->name('ajax.content-view');
}
