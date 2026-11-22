<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*', 'img/*', 'thumb/*', 'storage/*', 'sanctum/csrf-cookie', 'qr*',
        'js/utils/*', 'js/utils/ckfinder/*', 'js/*', 'telescope/*', 'i18n/**',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*', 'localhost', '127.0.0.1'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit', 'X-RateLimit-Reset', 'X-RateLimit-Remaining',
        // Marks answers to the read-only demo account so a cross-origin admin can show its banner (Demo project only sends it).
        'X-Demo-Read-Only',
        // Marks answers to the shared demo account of an editable demo site, with its daily reset time (Demo module only).
        'X-Demo-Site',
    ],

    'max_age' => 0,

    'supports_credentials' => true,
];
