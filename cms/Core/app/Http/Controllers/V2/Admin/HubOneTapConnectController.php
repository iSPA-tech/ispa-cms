<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Services\Platform\HubOneTapConnect;
use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * One-tap hub connect (plan 2026-11-22-hub-one-tap-connect), `hub.connect`, throttled to 10 a minute each:
 *
 * - `POST /api/v2/admin/hub-connect/start` with `{replace?}` → `{data: {redirect_url}}` (the hub portal
 *   `/portal/connect-site` address). A site with credentials answers HUB_ALREADY_CONNECTED until `replace` is true;
 *   the answer is kept for finish.
 * - `POST /api/v2/admin/hub-connect/finish` with `{code, state, replace?}` or `{error, state}` → on success
 *   `{data: {status: connected|pending, source: stored|env, warning_key, message_key}}` like `site-connect`.
 *
 * Refusals use the error envelope with `error_code`: HUB_CONNECT_FORBIDDEN (403), HUB_CONNECT_SITE_UNKNOWN (422),
 * HUB_CONNECT_STATE_INVALID (400), HUB_CONNECT_DENIED (400), HUB_CONNECT_DOMAIN_TAKEN (409),
 * HUB_CONNECT_SITE_DISABLED (409), HUB_CONNECT_EXCHANGE_FAILED (502), HUB_ALREADY_CONNECTED (409), CREDENTIALS_REFUSED (422),
 * HUB_UNREACHABLE (503), HUB_CONNECT_MANAGED_BY_ENV (409, credentials set in `.env` by the server operator).
 */
final class HubOneTapConnectController extends Controller
{
    /**
     * Error code, HTTP status and plain sentence per refused outcome.
     */
    private const REFUSALS = [
        HubOneTapConnect::OUTCOME_SITE_UNKNOWN => ['HUB_CONNECT_SITE_UNKNOWN', 422, 'Chưa biết địa chỉ của website này. Hãy kết nối bằng mã thủ công.'],
        HubOneTapConnect::OUTCOME_STATE_INVALID => ['HUB_CONNECT_STATE_INVALID', 400, 'Yêu cầu kết nối đã hết hạn hoặc không hợp lệ. Hãy bấm "Kết nối iSPA Hub" lại.'],
        HubOneTapConnect::OUTCOME_DENIED => ['HUB_CONNECT_DENIED', 400, 'Bạn đã huỷ kết nối trên ispa.tech.'],
        HubOneTapConnect::OUTCOME_DOMAIN_TAKEN => ['HUB_CONNECT_DOMAIN_TAKEN', 409, 'Website này đang thuộc một tài khoản iSPA khác.'],
        HubOneTapConnect::OUTCOME_SITE_DISABLED => ['HUB_CONNECT_SITE_DISABLED', 409, 'Website này đã bị tắt kết nối trong tài khoản iSPA. Hãy bật lại trên ispa.tech rồi thử lại.'],
        HubOneTapConnect::OUTCOME_EXCHANGE_FAILED => ['HUB_CONNECT_EXCHANGE_FAILED', 502, 'iSPA Hub chưa xác nhận được kết nối. Hãy thử lại hoặc kết nối bằng mã thủ công.'],
        SiteConnectService::OUTCOME_ALREADY_CONNECTED => ['HUB_ALREADY_CONNECTED', 409, 'Website đã kết nối với iSPA Hub.'],
        SiteConnectService::OUTCOME_REFUSED => ['CREDENTIALS_REFUSED', 422, 'iSPA Hub không nhận kết nối này. Hãy thử lại hoặc kết nối bằng mã thủ công.'],
        SiteConnectService::OUTCOME_MANAGED_BY_ENV => ['HUB_CONNECT_MANAGED_BY_ENV', 409, 'Kết nối iSPA Hub của website này do người quản trị máy chủ đặt trong cấu hình (.env). Hãy nhờ họ thay đổi ở đó.'],
        SiteConnectService::OUTCOME_HUB_UNREACHABLE => ['HUB_UNREACHABLE', 503, 'Chưa nối được tới iSPA Hub. Hãy thử lại sau ít phút hoặc kết nối bằng mã thủ công.'],
    ];

    /**
     * Start an attempt and answer where to send the browser.
     */
    public function start(Request $request, HubOneTapConnect $oneTap): mixed
    {
        $user = user();
        if (! $user?->can('hub.connect')) {
            return $this->forbidden();
        }

        $result = $oneTap->start($user, $request->boolean('replace'));
        if ($result['outcome'] !== HubOneTapConnect::OUTCOME_STARTED) {
            return $this->refuse($result['outcome'], null);
        }

        return ires()->data(['redirect_url' => $result['redirect_url']]);
    }

    /**
     * Finish an attempt with what the hub portal sent back.
     */
    public function finish(Request $request, HubOneTapConnect $oneTap): mixed
    {
        $user = user();
        if (! $user?->can('hub.connect')) {
            return $this->forbidden();
        }

        $result = $oneTap->finish(
            $user,
            $request->input('code'),
            $request->input('state'),
            $request->boolean('replace'),
            $request->input('error'),
        );
        $outcome = $result['outcome'];
        if ($outcome !== SiteConnectService::OUTCOME_CONNECTED && $outcome !== SiteConnectService::OUTCOME_PENDING) {
            return $this->refuse($outcome, $result['message_key'] ?? null);
        }

        return ires()->data([
            'status' => $outcome,
            'source' => $result['source'] ?? 'stored',
            'warning_key' => $result['warning_key'] ?? null,
            'message_key' => $result['message_key'] ?? null,
        ]);
    }

    private function forbidden(): mixed
    {
        return ires()->error()->code(403)->errorCode('HUB_CONNECT_FORBIDDEN')->message(__('mgs.no.permission'));
    }

    /**
     * The error envelope for one refused outcome.
     */
    private function refuse(string $outcome, ?string $messageKey): mixed
    {
        [$errorCode, $status, $message] = self::REFUSALS[$outcome] ?? self::REFUSALS[HubOneTapConnect::OUTCOME_EXCHANGE_FAILED];

        return ires()->error()->code($status)->errorCode($errorCode)->message($message)->data(['message_key' => $messageKey]);
    }
}
