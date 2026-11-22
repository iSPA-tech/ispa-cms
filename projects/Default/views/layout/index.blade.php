{{-- Default theme layout, shared by the home and every module page (Content posts, categories, news): header with the
     main_menu group, the page content, footer with the footer_menu group. Pages fill @section('content'). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', iSeo('brand'))</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @vite([config('ispa-cms.template_tailwind_css')])
        @component('components.seo')@endcomponent
        @stack('head')
    </head>
    <body class="flex min-h-dvh flex-col bg-white text-slate-900 antialiased">
        @include(config('ispa-cms.folder') . 'components.header')
        <main class="flex-1">
            @yield('content')
        </main>
        @include(config('ispa-cms.folder') . 'components.footer')
        @stack('end-script')
    </body>
</html>
