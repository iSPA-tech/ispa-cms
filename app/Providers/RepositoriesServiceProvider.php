<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Config-driven repository bindings (eager by default).
 * Reads interfaces→concretes from config('repositories.bindings').
 */
class RepositoriesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $bindings = config('repositories.bindings', []);
        foreach ($bindings as $interface => $concrete) {
            $this->app->singleton($interface, $concrete);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return array_keys(config('repositories.bindings', []));
    }
}
