<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Services\CMSSettingRuntime;

if (!function_exists('has_cookie_consent')) {
    /**
     * Determine if the visitor has accepted cookie-dependent features.
     */
    function has_cookie_consent(): bool
    {
        if (app()->runningInConsole()) {
            return true;
        }

        return in_array(request()?->cookie('cookie-acceptance'), ['true', 'accepted'], true);
    }
}

if (!function_exists('iSeo')) {
    /**
     * Get seo data for website
     *
     * @param string $key
     * @param string $default
     *
     * @return mixed
     * @throws Throwable
     * @since 6.62.6 - 2023-08-22, 20:26 ICT
     * @author malayvuong
     */
    function iSeo(string $key, string $default = ''): mixed
    {
        //  Split key with dot and get first key
        $split = explode('.', $key, 2);
        $key = $split[0];
        $subKey = $split[1] ?? null;

        $keySupported = [
            'title' => 'setting.basic.title',
            'description' => 'setting.basic.description',
            'website_slogan' => 'setting.basic.website_slogan',
            'hashtag' => 'setting.basic.hashtag',
            'brand' => 'setting.basic.brand',
            'copy_right' => 'setting.basic.brand',
            'address' => 'setting.contact.address',
            'admin_email' => 'setting.contact.admin_email',
            'phone' => 'setting.contact.phone',
            'mobile' => 'setting.contact.mobile',
            'time_serves' => 'setting.contact.time_serves',
            'email_contact' => 'setting.contact.email_contact',
            'contribute_contact' => 'setting.contact.email_contribute',
            'email_organization' => 'setting.contact.email_organization',
            'feedback_phone' => 'setting.contact.feedback_phone',
            'notify_feedback_email' => 'setting.contact.notify_feedback_email',
            'representative_office' => 'setting.contact.representative_office',
            'facebook' => 'setting.social.url.facebook',
            'fb_app_id' => 'setting.tracking.chat.app-id-facebook',
            'youtube' => 'setting.social.url.youtube',
            'twitter' => 'setting.social.url.twitter',
            'instagram' => 'setting.social.url.instagram',
            'linkedin' => 'setting.social.url.linkedin',
            'skype' => 'setting.social.url.skype',
            'tiktok' => 'setting.social.url.tiktok',
            'zalo' => 'setting.social.url.zalo',
            'discord' => 'setting.social.url.discord',
            'version' => 'setting.advanced.VERSION',
            'timezone' => 'setting.advanced.TIMEZONE',
            'editor' => 'setting.advanced.TEXT_EDITOR',
            'maintenance' => 'setting.advanced.MAINTENANCE_ENABLE',
            'widget' => 'setting.chat.chat.widget',
            'html' => 'setting.html.html-1',
            'html-footer' => 'setting.html.html-footer',
            //  Ads
            'ads_auto' => 'setting.ads.ads.global_ads',
            'ads_banner_top' => 'setting.ads.ads.banner_top',
            'ads_content_view' => 'setting.ads.ads.content_view',
            'ads_frequency' => 'setting.ads.ads.frequency',
            //  Url
            'terms' => 'setting.social.terms-url',
            'privacy' => 'setting.social.privacy-url',
            'cookies' => 'setting.social.cookies-url',
            'cookieText' => 'setting.social.cookies-text',
            //  images
            'image' => 'setting.image.logo',
            'logo' => 'setting.image.logo',
            'logo_footer' => 'setting.image.logo_footer',
            'favicon' => 'setting.image.favicon',
            'logo_dark' => 'setting.image.logo_dark',
        ];
        //  Key has language inside
        $hasLanguage = [
            'title', 'description', 'website_slogan',
            'address', 'time_serves', 'representative_office',
            'cookieText',
        ];
        //  Key is Images
        $isImage = [
            'logo', 'logo_footer', 'favicon', 'logo_dark', 'image',
        ];
        //  Custom key and custom value
        $custom = [
            'url' => url(request()->getRequestUri()),
            'base_url' => request()->root(),
        ];

        if (isset($custom[$key])) {
            return $custom[$key];
        }

        $consentSensitive = [
            'widget',
            'html-footer',
            'ads_auto',
            'ads_banner_top',
            'ads_content_view',
        ];
        if (in_array($key, $consentSensitive, true) && !has_cookie_consent()) {
            return $default;
        }

        $value = config($keySupported[$key] ?? $key, $default);
        // Priority: CMSSettingRuntime (env > private > public), then legacy config() fallback.
        $runtime = app(CMSSettingRuntime::class);
        $value = $runtime->get($keySupported[$key] ?? $key, $value);
        if ($value === null) {
            $value = config($keySupported[$key] ?? $key, $default);
        }

        if (in_array($key, $hasLanguage, true)) {
            $value = $value[lang('path_alias', 'vi-VN')] ?? $value['en-US'] ?? $default;
        } else if (in_array($key, $isImage, true)) {
            $value = $value[0] ?? $default;
            if (isset($subKey)) {
                $value = $value[$subKey] ?? $value ?? $default;
            }
        }
        if (is_array($value)) {
            //  take first value and convert to string
            $value = collect($value)->first() ?? $default;
        }

        return $value;
    }
}


if (!function_exists('get_srcset')) {
    /**
     * Generate srcset for image
     *
     * @param array $image
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-26, 21:22 ICT
     */
    function get_srcset(array $image = [], string $type = 'thumbnail'): string
    {
        if (empty($image)) {
            return '';
        }
        $presets = match ($type) {
            'scale' => ['md', 'lg', 'xl'],
            'cover' => ['cover_md', 'cover_lg', 'cover_xl'],
            default => ['thumbnail_md', 'thumbnail_lg', 'thumbnail_xl'],
        };
        $image['type'] ??= 'image';

        return \Cms\Core\View\Components\LazyImage::buildSrcset(
            (new \Cms\Core\Models\Upload($image))->forceFill(\Illuminate\Support\Arr::only($image, ['id', 'r2_synced_at'])),
            $presets,
        );
    }
}
