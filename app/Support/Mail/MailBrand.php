<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Support\Mail;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Brand of the site's transactional mail: the name and logo printed in the shared layout and the `[Brand] - ` subject prefix.
 *
 * Both come from the site's own setup (Settings → basic brand, Settings → images logo), so every site sends mail under its
 * own brand without code changes.
 */
final class MailBrand
{
    /** Date-time shown in subjects that must stay distinct in an inbox thread (password reset). */
    public const STAMP_FORMAT = 'd/m/Y H:i';

    /**
     * The site's brand name: the brand setting, else the project brand, else the app name.
     */
    public static function name(): string
    {
        foreach ([self::setting('brand'), config('ispa-cms.project_brand'), config('app.name')] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'iSPA';
    }

    /**
     * Absolute URL of the site's logo, or null when the site has none (the layout then prints the brand name only).
     */
    public static function logoUrl(): ?string
    {
        $logo = self::setting('logo.url');
        $logo = is_string($logo) ? trim($logo) : '';
        if ($logo === '') {
            return null;
        }
        if (str_starts_with($logo, '//')) {
            return 'https:' . $logo;
        }
        if (preg_match('#^https?://#i', $logo) === 1) {
            return $logo;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($logo, '/');
    }

    /**
     * The subject with the `[Brand] - ` prefix; a subject that already carries it is returned unchanged.
     */
    public static function subject(string $subject, ?string $brand = null): string
    {
        $brand = trim($brand ?? self::name());
        $subject = trim($subject);
        $prefix = '[' . $brand . ']';
        if ($brand === '' || str_starts_with($subject, $prefix)) {
            return $subject;
        }

        return $subject === '' ? $prefix : $prefix . ' - ' . $subject;
    }

    /**
     * The subject followed by the current date-time in the site's time zone, e.g. `Đặt lại mật khẩu (23/11/2026 14:05)`,
     * so each mail of a repeated flow is its own conversation in the inbox.
     */
    public static function stamped(string $subject, ?Carbon $at = null): string
    {
        $at = ($at ?? Carbon::now())->copy()->setTimezone(self::timezone());

        return trim($subject) . ' (' . $at->format(self::STAMP_FORMAT) . ')';
    }

    /**
     * The site's time zone for dates printed in mail.
     */
    public static function timezone(): string
    {
        $timezone = config('ispa-cms.timezone') ?: config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Ho_Chi_Minh';
    }

    /**
     * A site setting read through iSeo(); mail must still go out when settings cannot be read (queue boot, tests).
     */
    private static function setting(string $key): mixed
    {
        try {
            return function_exists('iSeo') ? iSeo($key) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
