@extends($layout)

@section('title', $skeletonThing->name)

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-10">
        <a class="text-sm text-slate-600 hover:underline" href="{{ \Cms\Skeleton\Support\SkeletonRoute::index() }}">{{ __('skeleton::text.skeleton.public.back') }}</a>
        <h1 class="mt-4 text-3xl font-bold text-slate-900">{{ $skeletonThing->name }}</h1>
        @if ($skeletonThing->description)
            <div class="mt-6 whitespace-pre-line text-slate-700">{{ $skeletonThing->description }}</div>
        @endif
    </article>
@endsection
