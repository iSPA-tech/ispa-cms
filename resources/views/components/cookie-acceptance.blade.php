@if (! $isAccept && iSeo('cookies'))
    <div
        class="cookie-acceptance fixed z-[9999] inset-x-0 bottom-0 flex flex-col justify-between gap-x-8 gap-y-4 bg-white px-6 py-2 lg:py-3 ring-1 ring-gray-900/10 md:flex-row md:items-center lg:px-8">
        <p class="max-w-6xl text-xs/5 text-gray-900">
            {!! iSeo('cookieText') !!} <a
                href="{{ route('home') . '/' . iSeo('cookies') }}" target="_blank"
                class="cookie-acceptance-policy font-semibold text-main-500">@lang('nav.b.cookie-policy')</a>.
        </p>
        <div class="flex flex-none items-center gap-x-5">
            <button type="button" onclick="acceptCookieConsent();"
                    class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900">
                @lang('nav.b.accept-all')
            </button>

            <button type="button" onclick="rejectCookieConsent();"
                    class="text-sm font-semibold leading-6 text-gray-900">
                @lang('nav.b.reject-all')
            </button>
        </div>
    </div>
@endif

@push('script')
    <script>
        function acceptCookieConsent() {
            const d = new Date()
            d.setTime(d.getTime() + (30 * 24 * 60 * 60 * 1000))
            let expires = 'expires=' + d.toUTCString()
            document.cookie = 'cookie-acceptance=true;' + expires + ';path=/'

            document.querySelector('.cookie-acceptance').remove()
        }

        function rejectCookieConsent() {
            const d = new Date()
            d.setTime(d.getTime() + (30 * 24 * 60 * 60 * 1000))
            let expires = 'expires=' + d.toUTCString()
            document.cookie = 'cookie-acceptance=reject;' + expires + ';path=/'

            document.querySelector('.cookie-acceptance').remove()
        }
    </script>
@endpush
