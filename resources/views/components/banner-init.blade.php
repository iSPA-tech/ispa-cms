@php
    $bannerViewUrl = \Illuminate\Support\Facades\Route::has('ajax.banner.view') ? route('ajax.banner.view') : null;
    $bannerCloseUrl = \Illuminate\Support\Facades\Route::has('ajax.banner.close') ? route('ajax.banner.close') : null;
    $bannerClickUrl = \Illuminate\Support\Facades\Route::has('ajax.banner.click') ? route('ajax.banner.click') : null;
@endphp

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('banner', {
            defaultCredential: {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '{{  csrf_token() }}',
                },
                credentials: 'same-origin',
            },
            updateView: (id) => {
                @if($bannerViewUrl)
                    fetch('{{ $bannerViewUrl }}', {
                        ...Alpine.store('banner').defaultCredential,
                        body: JSON.stringify({ id: id }),
                    })
                @endif
            },
            updateClose: (id) => {
                @if($bannerCloseUrl)
                    fetch('{{ $bannerCloseUrl }}', {
                        ...Alpine.store('banner').defaultCredential,
                        body: JSON.stringify({ id: id }),
                    })
                @endif
            },
            updateClick: (id, url) => {
                @if($bannerClickUrl)
                    fetch('{{ $bannerClickUrl }}', {
                        ...Alpine.store('banner').defaultCredential,
                        body: JSON.stringify({ id: id }),
                    })
                @endif
                const baseUrl = '{{ url('/') }}'
                //  Check if url is external or internal
                if (!url.includes('http')) {
                    //  Make sure url is not start with '/' and combine with baseUrl
                    url = url.startsWith('/') ? baseUrl + url : baseUrl + '/' + url
                }
                //  Open new tab with the url
                window.open(url, '_blank')
            },
        })
    })
</script>
