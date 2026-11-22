{{-- Default theme header: logo (setting, else the bundled logo) and site name, then the main_menu group of the menus
     table through the MainMenu component (components/main-menu.blade.php). With no menu only the logo and name show. --}}
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-4 py-4 sm:px-6">
        <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-3">
            <img src="{{ iSeo('logo_dark.url') ?: asset('images/ispa-logo.png') }}" alt="{{ iSeo('brand') }}" class="h-9 w-auto shrink-0">
            <span class="truncate text-base font-semibold text-slate-900">{{ iSeo('brand') }}</span>
        </a>
        <x-main-menu template />
    </div>
</header>
