<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

/**
 * The one site-id rule shared by the site and the hub (one-tap review 2026-11-22, I3): the hub stores a site under its
 * bare host, so `www.example.com` and `example.com` are the same site.
 */
final class SiteDomain
{
    /**
     * Lowercase host without a trailing dot and without one leading `www.` (kept when nothing but a top-level label
     * follows, so `www.com` stays `www.com`). Only a leading `www.` is dropped: `blog.www.example.com` is its own site.
     */
    public static function bare(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        return str_starts_with($host, 'www.') && substr_count($host, '.') > 1 ? substr($host, 4) : $host;
    }
}
