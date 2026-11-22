<meta name="viewport" content="width=device-width, initial-scale=1" />
{{-- Escape raw values without double-encoding Blade's already-escaped inline sections. --}}
<title>{!! e($__env->yieldContent('seo-title', iSeo('title')), false) !!}</title>
<meta name="description" content="{!! e($__env->yieldContent('seo-description', iSeo('description')), false) !!}" />
<meta property="keywords" content="{{ $__env->yieldContent('seo-hashtag', iSeo('hashtag')) }}" />
@include('core::components.social-meta', [
    'metadata' => [
        'title' => $__env->yieldContent('seo-title', iSeo('title')),
        'description' => $__env->yieldContent('seo-description', iSeo('description')),
        'type' => $__env->yieldContent('seo-type', 'website'),
        'url' => $__env->yieldContent('seo-url', iSeo('url')),
        'image' => $__env->yieldContent('seo-image', iSeo('image.url')),
    ],
    'siteName' => iSeo('brand'),
    'facebookImage' => $__env->yieldContent('seo-image-facebook', $__env->yieldContent('seo-image', iSeo('image.url'))),
    'twitterImage' => $__env->yieldContent('seo-image-twitter', $__env->yieldContent('seo-image', iSeo('image.url'))),
])
@include('core::components.site-identity', [
    'siteName' => iSeo('brand'), 'siteUrl' => iSeo('base_url'),
    'identityAttributes' => [
        'logo' => iSeo('logo.url'), 'address' => iSeo('address'), 'telephone' => iSeo('phone'),
        'sameAs' => [iSeo('facebook'), iSeo('twitter'), iSeo('instagram'), iSeo('youtube'), iSeo('linkedin'), iSeo('tiktok')],
    ],
])
@if (!empty(iSeo('fb_app_id')))
    <meta property="fb:app_id" content="{{ iSeo('fb_app_id') }}" />
@endif
@if (!empty($view->video_url))
    <meta property="og:video" content="{{ $view->video_url }}" />
@endif
@if (isset($view->config, $view->config['bookAuthor']) && !empty($view->config['bookAuthor']) && count($view->attachs) > 0)
    <meta name="author" content="{{ $view->config['bookAuthor'] }}" />
@endif
<meta name="copyright"
      content="Copyright {{ iSeo('copy_right') ?? 'iSPA CMS' }} {{ date('Y') }}. All Rights Reserved." />
<link rel="canonical" href="{!! e($__env->yieldContent('seo-url', iSeo('url')), false) !!}" />

@if (config('app.env') === 'production')
    <link rel="manifest" href="{{ asset('build/manifest.json') }}">
@endif
<meta name="theme_color" media="(prefers-color-scheme: light)" content="white">
<meta name="theme_color" media="(prefers-color-scheme: dark)" content="black">
@if (!empty(iSeo('favicon.url')))
    <link rel="shortcut icon" href="{{ iSeo('favicon.url') }}" type="image/x-icon">
@endif

@stack('seo')

@push('end-script')
    <!-- Website build/CMS copyright by iSPA.io -->
@endpush
