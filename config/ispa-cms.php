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
  | iSPA CMS runtime settings
  |--------------------------------------------------------------------------
  |
  | Site-level switches read through config() at runtime (env() returns null once the config is cached).
  |
  */
    'ggApi' => env('GOOGLE_API'),
    'homepage' => env('HOMEPAGE_TYPE', 'default'),
    'homepage_static_id' => env('HOMEPAGE_STATIC_ID'),

    // Runtime flags that used to be read via env() at call sites. With a
    // cached config env() returns null everywhere, so every runtime read
    // must go through config.
    'cache_enable' => (bool) env('CACHE_ENABLE', false),
    'redis_enable' => (bool) env('REDIS_ENABLE', false),
    'skip_languages_query' => (bool) env('SKIP_LANGUAGES_QUERY', false),
    'public_path' => env('PUBLIC_PATH', 'public'),
    'text_editor' => env('TEXT_EDITOR', 'ckeditor'),
    'api_auth_key' => env('ISPA_API_AUTH_KEY', ''),
    'admin_auth_key' => env('ISPA_ADMIN_AUTH_KEY', ''),
    'setting_runtime_enabled' => (bool) env('CMS_SETTING_RUNTIME_ENABLED', true),
    'blog_category_slug' => env('BLOG_CATEGORY_SLUG'),
    // This site's own domain, as registered with the platform hub (empty until the site is set up).
    'cms_domain' => env('CMS_DOMAIN', ''),
    // Outside production only: the IP AppHelpers::getLocation() looks up instead of the request's (local requests come
    // from 127.0.0.1). Empty = the request IP.
    'location_fallback_ip' => env('LOCATION_FALLBACK_IP', ''),
    'cms_domain_secret' => env('CMS_DOMAIN_SECRET', ''),
    // Base URL of the platform hub that verifies signed site requests (https only).
    'platform_hub_url' => env('PLATFORM_HUB_URL', 'https://ispa.tech'),
    // Usage docs the admin links to (`<docs_url>/<vi|en>/docs`); a site that hosts its own docs sets ISPA_DOCS_URL.
    'docs_url' => env('ISPA_DOCS_URL', 'https://docs.ispa.tech'),
    // Site report level sent to the hub: off, basic or full. Empty = the Webmaster's setting, else the default
    // (full with CMS_DOMAIN_SECRET, basic for a site connected from the connect screen).
    'platform_report_level' => env('CMS_PLATFORM_REPORT_LEVEL', ''),

    /** Maintenance mode */
    'maintenance' => env('MAINTENANCE_ENABLE', false),

    'primary_language' => env('DEFAULT_LOCALE', 'vi'),

    'available_languages' => [
        'en', 'en-US',
        'vi', 'vi-VN',
    ],

    'app_version' => file_exists(base_path('VERSION'))
        ? trim((string) file_get_contents(base_path('VERSION')))
        : '0.0.0',
    // Latest released mobile app versions, surfaced through the public
    // i18n/app manifest so installed apps can show an update banner.
    'mobile_app' => [
        'android' => [
            'latest' => env('MOBILE_APP_ANDROID_LATEST'),
            'minimum' => env('MOBILE_APP_ANDROID_MINIMUM'),
            'store_url' => env('MOBILE_APP_ANDROID_STORE_URL'),
        ],
        'ios' => [
            'latest' => env('MOBILE_APP_IOS_LATEST'),
            'minimum' => env('MOBILE_APP_IOS_MINIMUM'),
            'store_url' => env('MOBILE_APP_IOS_STORE_URL'),
        ],
    ],
    // Settings a project changes from its own provider (the open code names no project).
    'routes' => [
        // The `{category}/{slug}` catch-all of the public site.
        'category_navigation' => true,
    ],
    'upload' => [
        // Lock the uploads a content block references when it is saved, and let a failed attach throw.
        'lock_references' => false,
    ],
    'moderation' => [
        // Words added to the open keyword list of the automatic moderation (lower case).
        'extra_keywords' => [],
    ],
    'folder_name' => env('VITE_TEMPLATE_FOLDER', 'Default'),
    'folder' => str(env('VITE_TEMPLATE_FOLDER', 'Default'))->lower() . '::',
    'folder_path' => env('VITE_TEMPLATE_VIEWS_PATH', ''),
    'template_tailwind_css' => env('VITE_TEMPLATE_TAILWIND_CSS'),

    //  timeZone default of website
    //  Get timeZone from user config, if not, return the default timeZone
    'timezone' => env('TIMEZONE', config('app.timezone')),

    // Extra CSP frame-ancestors sources for signed Widget preview responses,
    // appended after the frozen 'self'. Production (same-origin /iPanel
    // admin) needs nothing here; a split-origin dev admin lists its origin,
    // e.g. "http://admin.local:8081". Space-separated, never a wildcard.
    'widget_preview_frame_ancestors' => env('WIDGET_PREVIEW_FRAME_ANCESTORS', ''),

    // Local widget authoring: lets hub (catalog) widgets be edited in place.
    // Always off in production regardless of this flag.
    'widget' => [
        'catalog_authoring' => env('WIDGET_CATALOG_AUTHORING', false),
    ],
];
