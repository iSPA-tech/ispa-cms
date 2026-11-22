<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace PJ\Default\Provider;

use Illuminate\Support\ServiceProvider;

/**
 * Default theme provider (loaded by AppServiceProvider while Default is the active project).
 *
 * Loads the theme's own copy under the `default` namespace, e.g. `__('default::theme.latest.title')`.
 */
final class CustomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'default');
    }
}
