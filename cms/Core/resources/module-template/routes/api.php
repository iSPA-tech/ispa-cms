<?php

use Cms\Skeleton\Http\Controllers\V2\Admin\SkeletonThingController;
use Illuminate\Support\Facades\Route;

// Admin API, UUID-first: /api/v2/admin/skeleton/things
Route::prefix('v2/admin')->as('v2.admin.')
    ->middleware(['auth:sanctum', 'api.admin', 'throttle:500,1'])
    ->group(static function (): void {
        Route::prefix('skeleton')->as('skeleton.')->group(static function (): void {
            Route::apiResource('things', SkeletonThingController::class)
                ->parameters(['things' => 'uuid'])
                ->where(['uuid' => '[0-9a-fA-F-]{36}']);
        });
    });
