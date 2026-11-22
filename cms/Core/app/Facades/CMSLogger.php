<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the CMS structured logging service.
 *
 * Provides a static interface to the underlying logger service for
 * recording structured error, info, and warning entries across all
 * CMS modules.
 *
 * @method static void error(array $array)
 * @method static void info(array $array)
 * @method static void warning(array $array)
 * @method static void send(array $data = [], string $type = 'info')
 *
 * @see \Cms\Core\Providers\CMSLoggerServiceProvider
 * @package Cms\Core\Facades
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CMSLogger extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected static function getFacadeAccessor(): string
    {
        return 'CMSLogger';
    }
}
