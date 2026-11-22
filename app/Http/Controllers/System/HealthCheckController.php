<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Liveness probe used when the GameAdmin health endpoint is not installed.
 *
 * Extends the framework controller rather than App\Http\Controllers\Controller:
 * that base constructor resolves route('home') and a translation line, work a
 * liveness probe should never depend on.
 *
 * Exists as a class because a closure route cannot be serialized, and one
 * uncacheable route is enough to make `route:cache` fail for the whole app.
 *
 * @package App\Http\Controllers\System
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
