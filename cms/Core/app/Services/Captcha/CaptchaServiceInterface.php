<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Captcha;

/**
 * Contract for CAPTCHA verification services.
 *
 * Implementations determine the active CAPTCHA provider and verify
 * client-submitted tokens against the provider's API.
 *
 * @see CaptchaGateway
 * @see CaptchaVerifyResult
 * @package Cms\Core\Services\Captcha
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface CaptchaServiceInterface
{
    /**
     * Return the active CAPTCHA provider name ('off', 'google', 'cloudflare').
     *
     * @return string
     */
    public function provider(): string;

    /**
     * Verify a CAPTCHA token against the active provider.
     *
     * @param string|null          $token   The client-submitted CAPTCHA token.
     * @param string|null          $ip      Optional client IP address.
     * @param array<string, mixed> $context Optional context: `minScore` for Google v3, `action` for the action a Cloudflare Turnstile widget was rendered with.
     * @return CaptchaVerifyResult
     */
    public function verify(?string $token, ?string $ip = null, array $context = []): CaptchaVerifyResult;
}
