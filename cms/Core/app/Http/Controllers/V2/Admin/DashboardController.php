<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use JsonException;
use App\Http\Controllers\Controller;
use Cms\Core\Services\SystemInfoService;

/**
 * Admin dashboard endpoints for system-level information.
 *
 * Provides server and environment details for the admin dashboard,
 * including PHP version, extensions, database info, and disk usage.
 *
 * @see SystemInfoService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DashboardController extends Controller
{
    /**
     * Server information
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2025-09-20, 18:07 ICT
     * @author malayvuong
     */
    public function server(): mixed
    {
        $service = new SystemInfoService();

        return ires()->data($service->getFullServerInfo());
    }
}
