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
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'facebook' => [
        'client_id' => config('settings.facebook_app_id', env('FACEBOOK_CLIENT_ID')),
        'client_secret' => 'xxx',
        'redirect' => 'http://127.0.0.1:8000/callback/facebook',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'channel_id' => [
            'general' => env('TELEGRAM_CHANNEL_ID_GENERAL'),
            'error_handler' => env('TELEGRAM_CHANNEL_ID_ERROR_HANDLER'),
            'debug' => env('TELEGRAM_CHANNEL_ID_DEBUG'),
            'error' => env('TELEGRAM_CHANNEL_ID_ERROR'),
            'info' => env('TELEGRAM_CHANNEL_ID_INFO'),
        ],
    ],

    'discord' => [
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'default_channel_id' => env('DISCORD_CHANNEL_ID_GENERAL'), // e.g., '123456789012345678'
        'icon_url' => env('DISCORD_ICON_URL', ''),
    ],

    // Optional SOCKS5 proxy for image downloads from hosts that block the server (e.g. socks5h://127.0.0.1:1080).
    // Empty = no proxy.
    'image_proxy' => [
        'socks5' => env('IMAGE_PROXY_SOCKS5', ''),
        // "host/path" prefixes of images that are also tried through the WordPress Photon CDN (i0-i2.wp.com) when the
        // direct download fails. Empty here; a module adds the sites it needs at boot.
        'photon_mirror_prefixes' => [],
    ],

    'fetch_proxy' => [
        'socks5' => env('FETCH_PROXY_SOCKS5', env('IMAGE_PROXY_SOCKS5', '')),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'public' => env('STRIPE_PUBLIC'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'scrapeless' => [
        'api_key' => env('SCRAPELESS_API_KEY', ''),
        'base' => env('SCRAPELESS_BASE', 'https://api.scrapeless.com'),
    ],

    'turnstile' => [
        'site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY', ''),
        // Comma-separated hostnames a verified Turnstile token may come from; empty skips the hostname check.
        'hostnames' => array_values(array_filter(array_map(static fn ($h) => strtolower(trim($h)), explode(',', (string) env('TURNSTILE_HOSTNAMES', ''))))),
    ],

    'recaptcha' => [
        'site_key' => env('GOOGLE_RECAPTCHA_SITE_KEY', ''),
    ],

    'tinify' => [
        'key' => env('TINIFY_API'),
    ],

    'openai' => [
        //  Must be read through config, never getenv(): config:cache skips the
        //  dotenv bootstrapper, so getenv('OPENAI_API_KEY') is false in production.
        'key' => env('OPENAI_API_KEY'),
        'embedded_model' => env('OPENAI_EMBEDDED_MODEL'),
    ],

    'typesafe' => [
        'api_key' => env('TYPESAFE_API_KEY'),
        'crawler_mode' => env('JEV_CRAWLER_MODE', 'off'),
        'crawler_model' => env('JEV_CRAWLER_MODEL', 'jev-1.13.0'),
    ],

    'google_vision' => [
        'api_key' => env('GOOGLE_VISION_API_KEY', ''),
    ],

    'smart_library' => [
        'connection' => env('SMART_LIBRARY_DB_CONNECTION', 'pgsql'),
    ],
];
