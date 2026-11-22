@php
    $folder = trim((string) config('ispa-cms.folder', ''));
    $layoutCandidates = array_values(array_filter([
        $folder !== '' ? $folder . 'layout.index' : null,
        $folder !== '' ? $folder . 'layouts.app' : null,
        'layout.index',
        'layouts.app',
    ]));

    $errorLayout = collect($layoutCandidates)
        ->first(static fn (string $layout): bool => view()->exists($layout)) ?? 'errors::layout';
    $homeUrl = Route::has('home') ? route('home') : url('/');
@endphp

@extends($errorLayout)

@section('content')
    <div class="bg-white min-h-[100vh]">
        <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-xl mx-auto py-16 sm:py-24">
                <div class="text-center">
                    <p class="text-sm font-semibold text-indigo-600 uppercase tracking-wide">@yield('code', 404)
                        error</p>
                    <h1 class="mt-2 text-4xl font-extrabold text-gray-900 tracking-tight sm:text-5xl">@yield('title', 'This page does not exist.')</h1>
                    <p class="mt-2 text-lg text-gray-500">@yield('message', 'The page you are looking for could not be found.')</p>
                </div>
                @if (trim($__env->yieldContent('code', '404')) === '404')
                    <div class="mt-12">
                        <h2 class="text-sm font-semibold text-gray-500 tracking-wide uppercase">@lang('web.section.popular_pages')</h2>
                        <ul role="list" class="mt-4 border-t border-b border-gray-200 divide-y divide-gray-200">
                            @if (Route::has('search'))
                                <li class="relative py-6 flex items-start space-x-4">
                                    <div class="shrink-0">
                <span class="flex items-center justify-center h-12 w-12 rounded-lg bg-indigo-50">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-700" fill="none"
                       viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-base font-medium text-gray-900">
                  <span class="rounded-xs focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                    <a href="{{ route('search') }}" class="focus:outline-hidden" title="@lang('web.search.title')">
                      <span class="absolute inset-0" aria-hidden="true"></span>
                      @lang('web.search.title')
                    </a>
                  </span>
                                        </h3>
                                        <p class="text-base text-gray-500">
                                            @lang('web.search.posts', ['brand' => iSeo('brand')])
                                        </p>
                                    </div>
                                    <div class="shrink-0 self-center">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd"
                                                  d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                  clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </li>
                            @endif

                            @if (Route::has('calendar'))
                                <li class="relative py-6 flex items-start space-x-4">
                                    <div class="shrink-0">
                <span class="flex items-center justify-center h-12 w-12 rounded-lg bg-indigo-50">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-700" fill="none"
                       viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-base font-medium text-gray-900">
                  <span class="rounded-xs focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                    <a href="{{ route('calendar') }}" class="focus:outline-hidden" title="@lang('web.section.events')">
                      <span class="absolute inset-0" aria-hidden="true"></span>
                      @lang('web.section.events')
                    </a>
                  </span>
                                        </h3>
                                        <p class="text-base text-gray-500">
                                            @lang('web.section.events_desc')
                                        </p>
                                    </div>
                                    <div class="shrink-0 self-center">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                             viewBox="0 0 20 20"
                                             fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd"
                                                  d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                  clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </li>
                            @endif

                            @if (Route::has('news'))
                                <li class="relative py-6 flex items-start space-x-4">
                                    <div class="shrink-0">
                <span class="flex items-center justify-center h-12 w-12 rounded-lg bg-indigo-50">
                  <!-- Heroicon name: outline/rss -->
                  <svg class="h-6 w-6 text-indigo-700" xmlns="http://www.w3.org/2000/svg" fill="none"
                       viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M6 5c7.18 0 13 5.82 13 13M6 11a7 7 0 017 7m-6 0a1 1 0 11-2 0 1 1 0 012 0z" />
                  </svg>
                </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-base font-medium text-gray-900">
                  <span class="rounded-xs focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                    <a href="{{ route('news') }}" class="focus:outline-hidden" title="@lang('web.t.news')">
                      <span class="absolute inset-0" aria-hidden="true"></span>
                      @lang('web.t.news')
                    </a>
                  </span>
                                        </h3>
                                        <p class="text-base text-gray-500">
                                            @lang('web.section.news_desc')
                                        </p>
                                    </div>
                                    <div class="shrink-0 self-center">
                                        <!-- Heroicon name: solid/chevron-right -->
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                             viewBox="0 0 20 20"
                                             fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd"
                                                  d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                  clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </li>
                            @endif
                        </ul>
                        <div class="mt-8">
                            <a href="{{ $homeUrl }}"
                               class="text-base font-medium text-indigo-600 hover:text-indigo-500">
                                @lang('web.section.or_back_to_home') <span aria-hidden="true"> &rarr;</span></a>
                        </div>
                    </div>
                @endif
            </div>
        </main>
    </div>
@endsection
