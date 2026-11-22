<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Public;

use Cms\Core\Services\Platform\SiteStatusService;
use Illuminate\Routing\Controller;

/**
 * `GET /api/v2/public/site-status` (, spec 12.4): the admin asks its own backend whether the site is connected
 * to the platform hub, instead of calling the hub from the browser.
 *
 * No sign-in (the admin asks before the sign-in screen); throttled per IP on the route. The answer is
 * `{status: connected|pending|not_connected, message_key, expires_at}` and never carries the domain token, the site
 * secret or the hub address.
 */
final class SiteStatusController extends Controller
{
    /**
     * Answer the site's hub connection status.
     */
    public function show(SiteStatusService $status): mixed
    {
        return ires()->data($status->current());
    }
}
