<?php

use Cms\Skeleton\Http\Controllers\Public\SkeletonPublicController;
use Cms\Skeleton\Http\Middleware\EnsureSkeletonEnabled;
use Illuminate\Support\Facades\Route;

// Public pages: /skeleton lists published things, /skeleton/{uuid} shows one. A project changes the look by adding
// projects/<Folder>/views/modules/skeleton/public/*.blade.php.
Route::prefix('skeleton')
    ->as('skeleton.public.')
    ->middleware(EnsureSkeletonEnabled::class)
    ->group(static function (): void {
        Route::get('/', [SkeletonPublicController::class, 'index'])->name('index');
        Route::get('{uuid}', [SkeletonPublicController::class, 'show'])->where('uuid', '[0-9a-fA-F-]{36}')->name('show');
    });
