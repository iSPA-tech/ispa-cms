{{-- Do NOT resolve captcha provider or siteKey outside <x-captcha /> --}}
@if ($invalid)
    <div class="rounded-md bg-yellow-50 border border-yellow-300 p-4">
        <div class="flex">
            <div class="shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd"
                          d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                          clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">@lang('mgs.doesnot-work.title', ['name' => $formName ?? __('web.form.register')])</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>@lang('mgs.doesnot-work.desc', ['name' => $formName ?? __('web.form.register')])</p>
                </div>
            </div>
        </div>
    </div>
@endif

<input type="hidden" name="captcha-response" id="recaptcha-token-{{ $formId }}" x-ref="captchaToken">
@if ($renderWidget && in_array($provider, ['cloudflare', 'hcaptcha'], true) && $active)
    <div id="captcha-widget-{{ $formId }}" class="hidden"></div>
@endif

@once
    <script>
        window.__captcha = {
            provider: @json($provider === 'hcaptcha' ? 'cloudflare' : $provider),
            verificationProvider: @json($provider),
            siteKey: @json($siteKey),
        }
    </script>
    @if ($provider === 'cloudflare' && $active)
        <script>
            window.onCaptchaLoad = function () {
                document.dispatchEvent(new Event('captcha-ready'))
            }
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onCaptchaLoad" async defer></script>
    @elseif ($provider === 'google' && $active)
        <script>
            window.onCaptchaLoad = function () {
                document.dispatchEvent(new Event('captcha-ready'))
            }
        </script>
        <script src="https://www.google.com/recaptcha/api.js?render={{ urlencode($siteKey) }}&onload=onCaptchaLoad" async defer></script>
    @elseif ($provider === 'hcaptcha' && $active)
        <script>
            window.onCaptchaLoad = function () {
                const api = window.hcaptcha
                window.turnstile = {
                    render(container, options = {}) {
                        const element = typeof container === 'string'
                            ? document.querySelector(container)
                            : container
                        element?.classList.remove('hidden')

                        return api.render(element ?? container, {
                            sitekey: options.sitekey,
                            size: options.size === 'compact' ? 'compact' : 'normal',
                            theme: options.theme,
                            callback: options.callback,
                            'error-callback': options['error-callback'],
                            'expired-callback': options['expired-callback'],
                            'chalexpired-callback': options['timeout-callback'],
                        })
                    },
                    execute(widgetId) {
                        return api.getResponse(widgetId)
                    },
                    reset(widgetId) {
                        return api.reset(widgetId)
                    },
                    getResponse(widgetId) {
                        return api.getResponse(widgetId)
                    },
                }
                document.dispatchEvent(new Event('captcha-ready'))
            }
        </script>
        <script src="https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off&onload=onCaptchaLoad" async defer></script>
    @endif
@endonce
