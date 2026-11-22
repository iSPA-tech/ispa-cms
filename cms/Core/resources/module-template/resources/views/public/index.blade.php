@extends($layout)

@section('title', __('skeleton::text.skeleton.public.title'))

@section('content')
    <section class="mx-auto max-w-5xl px-4 py-10">
        <h1 class="text-3xl font-bold text-slate-900">{{ __('skeleton::text.skeleton.public.title') }}</h1>

        @if ($skeletonThings->isEmpty())
            <p class="mt-6 text-slate-600">{{ __('skeleton::text.skeleton.public.empty') }}</p>
        @else
            <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($skeletonThings as $item)
                    <li class="rounded-xl border border-slate-200 bg-white p-5">
                        <h2 class="text-lg font-semibold">
                            <a class="text-slate-900 hover:underline" href="{{ \Cms\Skeleton\Support\SkeletonRoute::show($item) }}">{{ $item->name }}</a>
                        </h2>
                        @if ($item->description)
                            <p class="mt-2 line-clamp-3 text-sm text-slate-600">{{ $item->description }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-8">{{ $skeletonThings->links() }}</div>
        @endif
    </section>
@endsection
