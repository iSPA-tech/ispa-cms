<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class CollectionMacro extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        Collection::macro('keyByRecursive', function ($func) {
            return $this->keyBy(function ($value, $key) use ($func) {
                return $func($value, $key);
            })->map(function ($item) use ($func) {
                if ($item instanceof Collection) {
                    return $item->keyByRecursive($func);
                }
                if (is_array($item) || is_object($item)) {
                    return collect($item)->keyByRecursive($func);
                }

                return $item;
            });
        });

        Collection::macro('toCamel', function () {
            return $this->keyByRecursive(function ($value, $key) {
                return Str::camel($key);
            });
        });
    }
}
