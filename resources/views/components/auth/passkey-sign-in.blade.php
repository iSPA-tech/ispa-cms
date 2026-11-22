@php
    $isVietnamese = str_starts_with((string) app()->getLocale(), 'vi');
    $fallback = $isVietnamese
        ? [
            'sign_in' => 'Đăng nhập bằng passkey',
            'description' => 'Dùng Face ID, Touch ID hoặc khóa bảo mật.',
            'signing_in' => 'Đang xác thực passkey...',
            'cancelled' => 'Đã hủy xác thực passkey.',
            'failed' => 'Không thể đăng nhập bằng passkey. Vui lòng thử lại.',
        ]
        : [
            'sign_in' => 'Sign in with a passkey',
            'description' => 'Use Face ID, Touch ID, or a security key.',
            'signing_in' => 'Signing in with passkey...',
            'cancelled' => 'Passkey verification was cancelled.',
            'failed' => 'Unable to sign in with a passkey. Please try again.',
        ];
    $translate = static function (string $key, string $fallback): string {
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    };
@endphp

<div class="mt-6 border-t border-gray-200 pt-6" data-passkey-login hidden>
    <button
        type="button"
        class="group relative flex w-full items-center justify-center rounded-md border border-main-600 bg-white px-4 py-2 text-sm font-medium text-main-700 shadow-xs hover:bg-main-50 focus:outline-hidden focus:ring-2 focus:ring-main-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        data-passkey-login-button
        aria-describedby="passkey-login-description"
    >
        <span class="absolute inset-y-0 left-0 flex items-center pl-3" aria-hidden="true">
            {{-- Font Awesome Free Solid: fa-fingerprint (f577). --}}
            <svg class="h-5 w-5 text-main-600 group-hover:text-main-700" viewBox="0 0 512 512" fill="currentColor" data-passkey-fingerprint-icon>
                <path d="M256.12 245.96c-13.25 0-24 10.74-24 24 1.14 72.25-8.14 141.9-27.7 211.55-2.73 9.72 2.15 30.49 23.12 30.49 10.48 0 20.11-6.92 23.09-17.52 13.53-47.91 31.04-125.41 29.48-224.52.01-13.25-10.73-24-23.99-24zm-.86-81.73C194 164.16 151.25 211.3 152.1 265.32c.75 47.94-3.75 95.91-13.37 142.55-2.69 12.98 5.67 25.69 18.64 28.36 13.05 2.67 25.67-5.66 28.36-18.64 10.34-50.09 15.17-101.58 14.37-153.02-.41-25.95 19.92-52.49 54.45-52.34 31.31.47 57.15 25.34 57.62 55.47.77 48.05-2.81 96.33-10.61 143.55-2.17 13.06 6.69 25.42 19.76 27.58 19.97 3.33 26.81-15.1 27.58-19.77 8.28-50.03 12.06-101.21 11.27-152.11-.88-55.8-47.94-101.88-104.91-102.72zm-110.69-19.78c-10.3-8.34-25.37-6.8-33.76 3.48-25.62 31.5-39.39 71.28-38.75 112 .59 37.58-2.47 75.27-9.11 112.05-2.34 13.05 6.31 25.53 19.36 27.89 20.11 3.5 27.07-14.81 27.89-19.36 7.19-39.84 10.5-80.66 9.86-121.33-.47-29.88 9.2-57.88 28-80.97 8.35-10.28 6.79-25.39-3.49-33.76zm109.47-62.33c-15.41-.41-30.87 1.44-45.78 4.97-12.89 3.06-20.87 15.98-17.83 28.89 3.06 12.89 16 20.83 28.89 17.83 11.05-2.61 22.47-3.77 34-3.69 75.43 1.13 137.73 61.5 138.88 134.58.59 37.88-1.28 76.11-5.58 113.63-1.5 13.17 7.95 25.08 21.11 26.58 16.72 1.95 25.51-11.88 26.58-21.11a929.06 929.06 0 0 0 5.89-119.85c-1.56-98.75-85.07-180.33-186.16-181.83zm252.07 121.45c-2.86-12.92-15.51-21.2-28.61-18.27-12.94 2.86-21.12 15.66-18.26 28.61 4.71 21.41 4.91 37.41 4.7 61.6-.11 13.27 10.55 24.09 23.8 24.2h.2c13.17 0 23.89-10.61 24-23.8.18-22.18.4-44.11-5.83-72.34zm-40.12-90.72C417.29 43.46 337.6 1.29 252.81.02 183.02-.82 118.47 24.91 70.46 72.94 24.09 119.37-.9 181.04.14 246.65l-.12 21.47c-.39 13.25 10.03 24.31 23.28 24.69.23.02.48.02.72.02 12.92 0 23.59-10.3 23.97-23.3l.16-23.64c-.83-52.5 19.16-101.86 56.28-139 38.76-38.8 91.34-59.67 147.68-58.86 69.45 1.03 134.73 35.56 174.62 92.39 7.61 10.86 22.56 13.45 33.42 5.86 10.84-7.62 13.46-22.59 5.84-33.43z" />
            </svg>
        </span>
        <span data-passkey-login-label>{{ $translate('text.passkey.sign-in', $fallback['sign_in']) }}</span>
    </button>
    <p id="passkey-login-description" class="mt-2 text-center text-sm text-gray-500">
        {{ $translate('text.passkey.sign-in-desc', $fallback['description']) }}
    </p>
    <p class="mt-2 text-center text-sm" data-passkey-login-message role="status" aria-live="polite"></p>
</div>

<script>
    (() => {
        const root = document.currentScript.previousElementSibling
        if (!root || !window.PublicKeyCredential || !navigator.credentials?.get) return

        const button = root.querySelector('[data-passkey-login-button]')
        const label = root.querySelector('[data-passkey-login-label]')
        const message = root.querySelector('[data-passkey-login-message]')
        const defaultLabel = label.textContent
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}'
        const copy = {
            signingIn: @json($translate('text.passkey.signing-in', $fallback['signing_in'])),
            cancelled: @json($translate('text.passkey.cancelled', $fallback['cancelled'])),
            failed: @json($fallback['failed']),
        }

        const base64UrlToBuffer = (value) => {
            const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
            const padding = '='.repeat((4 - base64.length % 4) % 4)
            const binary = window.atob(base64 + padding)

            return Uint8Array.from(binary, (character) => character.charCodeAt(0)).buffer
        }

        const bufferToBase64Url = (buffer) => {
            const binary = String.fromCharCode(...new Uint8Array(buffer))

            return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
        }

        const publicKeyOptions = (options) => ({
            ...options,
            challenge: base64UrlToBuffer(options.challenge),
            allowCredentials: (options.allowCredentials || []).map((credential) => ({
                ...credential,
                id: base64UrlToBuffer(credential.id),
            })),
        })

        const credentialPayload = (credential) => ({
            id: credential.id,
            rawId: bufferToBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                signature: bufferToBase64Url(credential.response.signature),
                userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null,
            },
            clientExtensionResults: credential.getClientExtensionResults(),
            authenticatorAttachment: credential.authenticatorAttachment || null,
        })

        const errorMessage = (body) => {
            const firstError = Object.values(body?.errors || {}).flat()[0]

            return firstError || body?.message || copy.failed
        }

        const setMessage = (text, type = 'neutral') => {
            message.textContent = text
            message.className = {
                error: 'mt-2 text-center text-sm text-red-600',
                neutral: 'mt-2 text-center text-sm text-gray-600',
            }[type] || 'mt-2 text-center text-sm text-gray-600'
        }

        const safeRedirect = () => {
            const redirect = new URLSearchParams(window.location.search).get('redirect')

            if (!redirect) return null

            try {
                const url = new URL(redirect, window.location.origin)

                return url.origin === window.location.origin
                    ? `${url.pathname}${url.search}${url.hash}`
                    : null
            } catch (_) {
                return null
            }
        }

        root.hidden = false
        button.addEventListener('click', async () => {
            button.disabled = true
            label.textContent = copy.signingIn
            setMessage('')

            try {
                const optionsResponse = await fetch(@json(route('core.passkey.options')), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: '{}',
                })
                const optionsBody = await optionsResponse.json().catch(() => ({}))
                if (!optionsResponse.ok) throw new Error(errorMessage(optionsBody))

                const assertion = await navigator.credentials.get({
                    publicKey: publicKeyOptions(optionsBody.data.options),
                })
                if (!assertion) throw new DOMException('Passkey verification was cancelled.', 'AbortError')

                const remember = document.querySelector('input[name="remember"]')?.checked || false
                const loginResponse = await fetch(@json(route('core.passkey.login')), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        challenge_id: optionsBody.data.challenge_id,
                        credential: credentialPayload(assertion),
                        remember,
                    }),
                })
                const loginBody = await loginResponse.json().catch(() => ({}))
                if (!loginResponse.ok) throw new Error(errorMessage(loginBody))

                window.location.assign(safeRedirect() || loginBody?.data?.redirect_url || '/')
            } catch (error) {
                const cancelled = error?.name === 'NotAllowedError' || error?.name === 'AbortError'
                setMessage(cancelled ? copy.cancelled : (error?.message || copy.failed), cancelled ? 'neutral' : 'error')
            } finally {
                button.disabled = false
                label.textContent = defaultLabel
            }
        })
    })()
</script>
