<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `POST /api/v2/admin/site-connect` (spec 2026-11-22 hub connect from the admin): a signed-in user with `hub.connect`
 * sends `site_id`, `secret` and optional `replace`; no setup code. Throttled to 5 attempts a minute.
 *
 * Answers `{data: {status: connected|pending, source: stored|env, warning_key, message_key}}`, or the error envelope
 * with `error_code` HUB_CONNECT_FORBIDDEN (403), HUB_CONNECT_INVALID (422), HUB_ALREADY_CONNECTED (409),
 * CREDENTIALS_REFUSED (422) or HUB_UNREACHABLE (503). Never the secret or the hub address.
 */
final class SiteConnectController extends Controller
{
    /**
     * Error code and HTTP status per refused outcome.
     */
    private const REFUSALS = [
        SiteConnectService::OUTCOME_INVALID_INPUT => ['HUB_CONNECT_INVALID', 422, 'Hãy điền đúng địa chỉ website và mã bí mật.'],
        SiteConnectService::OUTCOME_ALREADY_CONNECTED => ['HUB_ALREADY_CONNECTED', 409, 'Website đã kết nối với iSPA Hub.'],
        SiteConnectService::OUTCOME_REFUSED => ['CREDENTIALS_REFUSED', 422, 'iSPA Hub không nhận địa chỉ website hoặc mã bí mật này.'],
        SiteConnectService::OUTCOME_HUB_UNREACHABLE => ['HUB_UNREACHABLE', 503, 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút.'],
    ];

    /**
     * Connect this site to the hub.
     */
    public function store(Request $request, SiteConnectService $connect): mixed
    {
        $user = user();
        if (! $user?->can('hub.connect')) {
            return ires()->error()->code(403)->errorCode('HUB_CONNECT_FORBIDDEN')->message(__('mgs.no.permission'));
        }

        $siteId = $request->input('site_id');
        $secret = $request->input('secret');
        if (! is_string($siteId) || ! is_string($secret)) {
            return $this->refuse(SiteConnectService::OUTCOME_INVALID_INPUT, 'text.site_connect.invalid_input');
        }

        $result = $connect->connectAsAdmin($siteId, $secret, (int) $user->id, $request->boolean('replace'));
        $outcome = $result['outcome'];
        if ($outcome !== SiteConnectService::OUTCOME_CONNECTED && $outcome !== SiteConnectService::OUTCOME_PENDING) {
            return $this->refuse($outcome, $result['message_key']);
        }

        return ires()->data([
            'status' => $outcome,
            'source' => $result['source'] ?? 'stored',
            'warning_key' => $result['warning_key'] ?? null,
            'message_key' => $result['message_key'],
        ]);
    }

    /**
     * The error envelope for one refused outcome.
     */
    private function refuse(string $outcome, string $messageKey): mixed
    {
        [$errorCode, $status, $message] = self::REFUSALS[$outcome] ?? ['HUB_UNREACHABLE', 503, self::REFUSALS[SiteConnectService::OUTCOME_HUB_UNREACHABLE][2]];

        return ires()->error()->code($status)->errorCode($errorCode)->message($message)->data(['message_key' => $messageKey]);
    }
}
