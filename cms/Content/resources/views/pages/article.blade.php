@extends(config('ispa-cms.folder') . 'layout.index')

@section('content')
    <main class="container mx-auto max-w-3xl px-4 py-8" data-blog-default="article">
        <h1 class="text-3xl font-bold tracking-tight text-gray-900">{{ $view->name ?? '' }}</h1>
        @if (!empty($view->description))
            <p class="mt-3 text-lg text-gray-600">{{ $view->description }}</p>
        @endif
        {{-- The admin editor saves content as blocks (text, image, video, widget); older posts hold an HTML string. --}}
        <div class="prose mt-6 max-w-none">
            @if (is_array($view->content ?? null))
                @include('components.contents-view', ['content' => $view->content])
            @else
                {!! $view->content ?? '' !!}
            @endif
        </div>
    </main>
@endsection
