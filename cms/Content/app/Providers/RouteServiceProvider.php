<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Route service provider for the Content module.
 *
 * Registers API and web route files for the module.
 *
 * @package Cms\Content\Providers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Content';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     * @return void
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     * @return void
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapPublicApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }

    /**
     * Define the Content read API routes that kept their pre-module route names.
     *
     * Same `api` middleware and prefix as routes/api.php, but no `api.` name prefix, so route
     * names such as `v3.category.index` are unchanged after the move out of routes/api.php.
     */
    protected function mapPublicApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->group(module_path($this->name, '/routes/public-api.php'));
    }
}
