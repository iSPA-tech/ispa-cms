{{-- Default theme home: the site's own
     content. Hero with the site name and description, the newest post large and the next ones in a grid ($posts from
     HomeController, Content getLatestPosts), or an empty state with a link to the admin on a fresh install. --}}
@extends(config('ispa-cms.folder') . 'layout.index')

@section('content')
    @php
        $newsUrl = Route::has('news') ? route('news') : null;
        $lead = $posts->first();
        $rest = $posts->slice(1, 6);
        $excerpt = static fn ($post): string => (string) str(strip_tags((string) $post->description))->squish()->limit(180);
    @endphp

    <section class="bg-gradient-to-b from-cyan-50 to-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
            <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance break-words text-slate-900 sm:text-5xl">{{ iSeo('brand') }}</h1>
            @if (filled(iSeo('description')))
                <p class="mt-5 max-w-2xl text-lg/8 text-pretty text-slate-600">{{ iSeo('description') }}</p>
            @endif
            @if ($lead && $newsUrl)
                <a href="{{ $newsUrl }}" class="mt-8 inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-cyan-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-600">
                    {{ __('default::theme.hero.cta') }}
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="size-4"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd"/></svg>
                </a>
            @endif
        </div>
    </section>

    @if ($lead)
        <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6" aria-labelledby="latest-title">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 id="latest-title" class="text-2xl font-bold tracking-tight text-slate-900">{{ __('default::theme.latest.title') }}</h2>
                @if ($newsUrl)
                    <a href="{{ $newsUrl }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800">{{ __('default::theme.latest.all') }} <span aria-hidden="true">→</span></a>
                @endif
            </div>

            <article class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2 lg:items-center">
                @if ($lead->image?->url)
                    <img src="{{ $lead->image->url }}" alt="{{ $lead->name }}" class="aspect-[16/9] w-full rounded-2xl object-cover">
                @else
                    <div class="aspect-[16/9] rounded-2xl bg-gradient-to-br from-cyan-100 to-slate-200" aria-hidden="true"></div>
                @endif
                <div class="min-w-0">
                    <p class="text-sm text-slate-500">
                        @if ($lead->category?->name)<span class="font-semibold text-cyan-700">{{ $lead->category->name }}</span> · @endif{{ $lead->published_date }}
                    </p>
                    <h3 class="mt-2 text-2xl font-bold tracking-tight text-balance break-words text-slate-900"><a href="{{ post_route($lead) }}" class="hover:text-cyan-700">{{ $lead->name }}</a></h3>
                    @if (filled($excerpt($lead)))
                        <p class="mt-3 text-base/7 text-slate-600">{{ $excerpt($lead) }}</p>
                    @endif
                </div>
            </article>

            @if ($rest->isNotEmpty())
                <div class="mt-12 grid grid-cols-1 gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($rest as $post)
                        <article class="min-w-0">
                            @if ($post->image?->url)
                                <img src="{{ $post->image->url }}" alt="{{ $post->name }}" loading="lazy" class="aspect-[16/9] w-full rounded-xl object-cover">
                            @else
                                <div class="aspect-[16/9] rounded-xl bg-gradient-to-br from-slate-100 to-slate-200" aria-hidden="true"></div>
                            @endif
                            <p class="mt-4 text-sm text-slate-500">
                                @if ($post->category?->name)<span class="font-semibold text-cyan-700">{{ $post->category->name }}</span> · @endif{{ $post->published_date }}
                            </p>
                            <h3 class="mt-1 text-lg font-semibold break-words text-slate-900"><a href="{{ post_route($post) }}" class="hover:text-cyan-700">{{ $post->name }}</a></h3>
                            @if (filled($excerpt($post)))
                                <p class="mt-2 line-clamp-2 text-sm/6 text-slate-600">{{ $excerpt($post) }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="rounded-2xl border-2 border-dashed border-slate-200 px-6 py-12 text-center">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('default::theme.empty.title') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm/6 text-slate-600">{{ __('default::theme.empty.text') }}</p>
                <a href="{{ url('/iPanel/') }}" class="mt-6 inline-flex rounded-lg px-4 py-2 text-sm font-semibold text-cyan-700 ring-1 ring-cyan-200 hover:bg-cyan-50">{{ __('default::theme.empty.admin') }}</a>
            </div>
        </section>
    @endif
@endsection
