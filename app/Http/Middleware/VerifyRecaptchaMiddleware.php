<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use Closure;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;

/**
 * Verifies the CAPTCHA token of a request before the route runs (route middleware alias `recaptcha`).
 *
 * An optional middleware parameter names the action the client widget was rendered with, for example
 * `recaptcha:login`; Cloudflare Turnstile then rejects a token issued for another action. Without a
 * parameter no action is checked.
 */
class VerifyRecaptchaMiddleware
{
    /**
     * Initialize the middleware with the CAPTCHA verification service.
     *
     * @param CaptchaServiceInterface $captcha Active CAPTCHA provider gateway.
     */
    public function __construct(
        private readonly CaptchaServiceInterface $captcha,
    ) {}

    /**
     * Verify the submitted CAPTCHA token, then continue or reject the request.
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure                  $next
     * @param string|null              $action  Optional expected widget action (`recaptcha:<action>`).
     * @return mixed
     */
    public function handle($request, Closure $next, ?string $action = null)
    {
        $token = $request->input('captcha-response')
            ?? $request->input('cf-turnstile-response')
            ?? $request->input('h-captcha-response')
            ?? $request->input('g-recaptcha-response');

        $result = $action !== null && $action !== ''
            ? $this->captcha->verify($token, $request->ip(), ['action' => $action])
            : $this->captcha->verify($token, $request->ip());

        if (!$result->ok) {
            $message = 'Captcha verification failed. Please try again.';

            if ($request->expectsJson()) {
                return ires()
                    ->error()
                    ->message($message)
                    ->data([
                        'provider' => $result->provider,
                        'error' => $result->error,
                    ]);
            }

            return redirect()->back()
                ->withErrors([$message])
                ->withInput();
        }

        return $next($request);
    }
}
