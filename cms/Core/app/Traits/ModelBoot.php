<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits;

/**
 * Trait ModelBoot
 *
 * Provides a boot method for Eloquent models to automatically attach observers if defined.
 * Intended for use in models that require custom observer logic via a static $observer property.
 *
 * Usage:
 *   - Add this trait to your Eloquent model.
 *   - Define a static $observer property on your model if you want to attach an observer.
 *
 * @package Cms\Core\Traits
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait ModelBoot
{
    /**
     * Boot the model and attach observer if defined.
     *
     * Defers observer registration until boot completes to avoid constructing a model during boot.
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function boot(): void
    {
        parent::boot();

        // Attach observer if the static property is set
        if (isset(self::$observer)) {
            static::whenBooted(static function (): void {
                static::observe(self::$observer);
            });
        }
    }
}
