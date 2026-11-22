<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Behaviour of deployments that serve APIs only. Project-neutral: any
 * headless host (api.example.com…) can opt in with one env
 * var, and an empty value keeps the feature inert.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Redirect direct page views to the frontend
    |--------------------------------------------------------------------------
    | When set (e.g. https://example.com), every browser page view on this host
    | 301s to the same path there; APIs, /iPanel, health probes, storage and
    | admin preview assets keep working. Empty = middleware does nothing.
    */
    'redirect_page_views_to' => env('PUBLIC_WEB_REDIRECT_TARGET', ''),
];
