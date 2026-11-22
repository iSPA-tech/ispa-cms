@extends(config('ispa-cms.folder') . 'layout.index')

@section('content')
    <main class="container mx-auto px-4 py-8" data-blog-default="news">
        <h1 class="mb-6 text-3xl font-bold tracking-tight text-gray-900">@lang('nav.a.posts')</h1>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts ?? [] as $p)
                <article class="rounded-lg border border-gray-200 bg-white p-5">
                    <a href="{{ route('article.' . lang()->path, ['slug' => $p->slug ?? ($p->slugs->first()->slug ?? ''), 'cate' => $p->category->slug ?? '']) }}"
                       title="{{ $p->name }}" class="block">
                        <h2 class="line-clamp-2 text-lg font-semibold text-gray-900">{{ $p->name }}</h2>
                        <p class="mt-2 line-clamp-3 text-sm text-gray-600">{{ str()->limit((string) $p->description, 150) }}</p>
                    </a>
                </article>
            @endforeach
        </div>

        @if (!empty($links))
            <div class="mt-8">{!! $links !!}</div>
        @endif
    </main>
@endsection
