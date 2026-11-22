<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Web;

use Cms\Core\Services\Platform\SiteConnectService;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * `GET /.well-known/ispa-hub/{nonce}` (, spec 12.2 step 4): the hub's domain check. It answers the HMAC of the
 * nonce with the secret being connected, but only while a connect call from the connect screen is outstanding and only
 * for a nonce of the shape the hub makes. Every other request gets the same empty 404.
 */
final class HubWellKnownController extends Controller
{
    /**
     * Answer one domain check.
     */
    public function show(string $nonce, SiteConnectService $connect): Response
    {
        $proof = $connect->callbackProof($nonce);
        if ($proof === null) {
            return response('', 404)->header('Cache-Control', 'no-store');
        }

        return response($proof, 200)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
    }
}
