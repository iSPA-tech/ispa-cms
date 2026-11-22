<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Providers;

use App\Services\CacheService;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the CMSCache singleton for centralized cache operations.
 *
 * Binds: 'CMSCache' singleton resolved to App\Services\CacheService.
 * Boots: No boot-time actions.
 *
 * @see \App\Services\CacheService
 * @package Cms\Core\Providers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CMSCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton('CMSCache', function () {
            return new CacheService();
        });
    }

    /**
     * Bootstrap services.
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
