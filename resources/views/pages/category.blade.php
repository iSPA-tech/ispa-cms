@extends(config('ispa-cms.folder') . 'layout.index')

@section('content')
    <main class="container relative" id="content" tabindex="-1">
        <x-breadcrumbs :breadcrumbs="$breadcrumbs"></x-breadcrumbs>

        <div class="relative py-6 md:py-12">
            <div class="relative max-w-screen-2xl mx-auto">
                <div class="text-center">
                    <h1 class="text-3xl tracking-tight font-extrabold text-gray-900 sm:text-4xl">{{ isset($view->seo, $view->seo->name) ? $view->seo->name : $view->name }}</h1>
                </div>
                @if (!is_null($posts))
                    @if (count($posts) > 0)
                        <div class="mt-12 max-w-lg mx-auto grid gap-5 lg:grid-cols-4 lg:max-w-none">
                            @foreach($posts as $p)
                                @if (isset($p->slug, $view, $view->slug))
                                    <div class="flex flex-col rounded-lg shadow-lg overflow-hidden relative">
                                        <div class="shrink-0">
                                            @if (isset($p->image[0]))
                                                <a href="{{ route('article.'.lang('path'), ['slug' => $p->slug, 'cate' => $view->slug]) }}"
                                                   title="{{ $p->name }}">
                                                    <img loading="lazy" class="h-48 w-full object-cover"
                                                         src="{{ $p->image[0]->cover_sm }}"
                                                         data-src="{{ $p->image[0]->cover_md }}"
                                                         alt="{{ $p->name }}">
                                                </a>
                                            @else
                                                <div class="h-48 w-full bg-gray-100"></div>
                                            @endif
                                        </div>
                                        <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                                            <div class="flex-1">
                                                <a href="{{ route('article.'.lang('path'), ['slug' => $p->slug, 'cate' => $view->slug]) }}"
                                                   title="{{ $p->name }}" class="block mt-2">
                                                    <p class="text-xl font-semibold text-gray-900 line-clamp-2">{{ $p->name }}</p>
                                                    <p class="mt-3 text-base text-gray-500 line-clamp-3">{{ str()->limit($p->description, 150) }}</p>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="rounded-md bg-red-50 p-4 mt-8">
                            <div class="flex">
                                <div class="shrink-0">
                                    <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg"
                                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd"
                                              d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                              clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <div class="text-sm text-red-700">
                                        <p>@lang('mgs.post.related-empty')</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    @foreach($view->children as $c)
                        @if (count($c->posts) > 0)
                            <div class="relative mt-4 mb-4 md:mb-6">
                                <div
                                    class="flex items-end border-b pb-1.5 mb-1.5 bg-secondary-700 py-2 px-4 text-white mb-4 rounded-xs flex items-center">
                                    <div class="flex-1">
                                        <h2><span class="flex-1 text-lg font-semibold text-white">{{ $c->name }}</span>
                                        </h2>
                                        @if ($c->hasChildren())
                                            <div class="space-x-2 leading-4">
                                                @foreach($c->children as $cate)
                                                    <a href="{{ route('category.' . lang('path'), ['slug' => $cate->slug]) }}"
                                                       title="{{ $cate->name }}"
                                                       class="text-xs font-normal hover:text-main-600">{{ $cate->name }}</a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <a href="{{ route('category.' . lang('path'), ['slug' => $c->slug]) }}"
                                       title="{{ $c->name }}"
                                       aria-label="@lang('web.read-more'): {{ $c->name }}"
                                       class="text-gray-100 hover:text-white text-sm">@lang('web.read-more') <i
                                            class="far fa-angle-double-right"></i><span class="sr-only">: {{ $c->name }}</span></a>
                                </div>
                                <div
                                    class="mt-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 md:gap-6">
                                    @foreach($c->posts as $item)
                                        @if (!empty($item) && isset($item->slug) && strlen($item->slug) > 0)
                                            <div class="group">
                                                <div class="relative shrink-0">
                                                    <a href="{{ route('article.'.lang('path'), ['slug' => $item->slug, 'cate' => $c->slug]) }}"
                                                       title="{{ $item->name }}"
                                                       aria-label="@lang('web.read-more'): {{ $item->name }}">
                                                        @if (isset($item->image))
                                                            <img loading="lazy" src="{{ $item->image->cover }}"
                                                                 class="w-full border rounded-sm" alt="">
                                                        @endif
                                                        <span
                                                            class="absolute z-10 bottom-0 right-0 bg-main-700/75 text-white px-2 py-0.5 text-sm">@lang('web.read-more')</span>
                                                        <span class="sr-only">: {{ $item->name }}</span>
                                                    </a>
                                                </div>
                                                <div class="pt-1.5">
                                                    <a href="{{ route('article.'.lang('path'), ['slug' => $item->slug, 'cate' => $c->slug]) }}"
                                                       title="{{ $item->name }}"
                                                       class="text-main-500 font-medium hover:text-main-600 group-hover:underline line-clamp-3">{{ $item->name }}</a>
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                @endif
            </div>
        </div>

        @if (!is_null($links))
            <div class="py-4 md:py-6">
                {!! $links !!}
            </div>
        @endif
    </main>
@endsection
