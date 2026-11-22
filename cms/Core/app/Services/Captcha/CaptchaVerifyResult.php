<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Captcha;

/**
 * Immutable value object representing a CAPTCHA verification result.
 *
 * Contains the verification outcome (ok), the provider used, an optional
 * error code, and the raw API response (with secrets stripped).
 *
 * @package Cms\Core\Services\Captcha
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final readonly class CaptchaVerifyResult
{
    /**
     * Initialize the verification result with outcome, provider, error, and raw response.
     *
     * @param bool        $ok       Whether the CAPTCHA verification passed.
     * @param string      $provider The captcha provider used (e.g., 'cloudflare', 'google').
     * @param string|null $error    Error code or message if verification failed.
     * @param array       $raw      Raw API response (with secrets stripped).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public bool    $ok,
        public string  $provider,
        public ?string $error = null,
        public array   $raw = [],
    ) {}
}
