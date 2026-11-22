<?php

declare(strict_types=1);

namespace Cms\Skeleton\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Skeleton';

    public function map(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
        // @mode full
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
        // @endmode
    }
}
