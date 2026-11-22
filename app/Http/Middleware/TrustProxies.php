<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Loopback only. The frontend runs on this same host and relays the view
     * tracking beacon on a reader's behalf, so it has to be able to hand over
     * that reader's address in X-Forwarded-For. Nothing arriving from outside
     * the machine can reach this: public traffic goes to php-fpm through
     * fastcgi_pass, so its REMOTE_ADDR is never 127.0.0.1, and a forwarded
     * header from any other peer stays ignored.
     *
     * Note this does NOT restore the client address for ordinary public
     * traffic. Cloudflare can sit in front of an API-only host and the vhost has no
     * real_ip directives, so REMOTE_ADDR there is a Cloudflare edge address.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        '127.0.0.1',
        '::1',
    ];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
