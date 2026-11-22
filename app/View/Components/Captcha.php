<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Throwable;

/**
 * Centralized captcha resolver for Blade forms.
 *
 * This component is the only place where frontend captcha runtime config should be resolved.
 * Views must not resolve captcha provider/siteKey directly via service calls, config(), or iSetting().
 * Keeping this logic centralized protects contract consistency for window.__captcha and widget DOM ids.
 */
class Captcha extends Component
{
    public string $provider;
    public string $siteKey;
    public bool $active;
    public bool $invalid;

    /**
     * Create a new component instance.
     *
     * @param CaptchaServiceInterface $captchaService Captcha provider gateway.
     * @param string $formId Form identifier used as widget/token id suffix.
     * @param string|null $formName Optional form action name for frontend usage.
     * @param bool $renderWidget Whether to render the cloudflare widget container.
     */
    public function __construct(
        protected CaptchaServiceInterface $captchaService,
        public string $formId,
        public ?string $formName = null,
        public bool $renderWidget = true,
    ) {
        $this->provider = $this->captchaService->provider();
        $this->siteKey = $this->resolveSiteKey($this->provider);
        $this->active = $this->provider !== 'off' && $this->siteKey !== '';
        $this->invalid = $this->provider !== 'off' && $this->siteKey === '';
    }

    /**
     * Resolve the site key from unified settings and optional env fallback.
     *
     * Env fallback is used only when iSetting() returns null. Empty-string settings are respected as configured.
     *
     * @param string $provider
     *
     * @return string
     */
    protected function resolveSiteKey(string $provider): string
    {
        [$settingKey, $configKey] = match ($provider) {
            'cloudflare' => ['api.CLOUDFLARE_TURNSTILE_SITE_KEY', 'services.turnstile.site_key'],
            'google' => ['api.GOOGLE_RECAPTCHA_SITE_KEY', 'services.recaptcha.site_key'],
            'hcaptcha' => ['api.HCAPTCHA_SITE_KEY', 'services.hcaptcha.site_key'],
            default => [null, null],
        };

        if ($settingKey === null || $configKey === null) {
            return '';
        }

        $siteKey = trim((string)$this->setting($settingKey));
        if ($this->isValidSiteKey($siteKey)) {
            return $siteKey;
        }

        $fallback = trim((string)config($configKey, ''));

        return $this->isValidSiteKey($fallback) ? $fallback : '';
    }

    private function isValidSiteKey(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $value) === 1;
    }

    private function setting(string $key): mixed
    {
        try {
            return iSetting($key, null);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Get the view/contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|Closure|string
    {
        return view('components.captcha');
    }
}
