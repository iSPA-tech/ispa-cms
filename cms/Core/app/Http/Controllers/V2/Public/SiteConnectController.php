<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Public;

use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `POST /api/v2/public/site-connect` (, spec 12.2): the pre-login connect screen sends the site id, site secret
 * and setup code. SiteConnectService checks the setup code first, calls the hub and stores the credentials only on a
 * signed hub answer. The route is throttled to 5 attempts a minute.
 *
 * Answers `{data: {status: connected|pending|not_connected, message_key}}`; never the secret, the setup code or the
 * hub address.
 */
final class SiteConnectController extends Controller
{
    /**
     * HTTP status per outcome; a failed connect is never a 2xx.
     */
    private const HTTP_STATUS = [
        SiteConnectService::OUTCOME_CONNECTED => 200,
        SiteConnectService::OUTCOME_PENDING => 200,
        SiteConnectService::OUTCOME_INVALID_INPUT => 422,
        SiteConnectService::OUTCOME_BAD_SETUP_CODE => 422,
        SiteConnectService::OUTCOME_REFUSED => 422,
        SiteConnectService::OUTCOME_MANAGED_BY_ENV => 409,
        SiteConnectService::OUTCOME_HUB_UNREACHABLE => 503,
    ];

    /**
     * Connect this site to the hub.
     */
    public function store(Request $request, SiteConnectService $connect): JsonResponse
    {
        $result = $connect->connect(
            (string) $request->input('site_id', ''),
            (string) $request->input('site_secret', ''),
            (string) $request->input('setup_code', ''),
        );
        $outcome = $result['outcome'];

        return response()->json([
            'data' => [
                'status' => in_array($outcome, [SiteConnectService::OUTCOME_CONNECTED, SiteConnectService::OUTCOME_PENDING], true) ? $outcome : 'not_connected',
                'message_key' => $result['message_key'],
            ],
        ], self::HTTP_STATUS[$outcome] ?? 422);
    }
}
