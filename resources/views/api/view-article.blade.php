@extends('api.web-view')

@section('content')
    <div class="relative py-16 bg-white overflow-hidden">
        <div class="relative px-4 sm:px-6 lg:px-8">
            <div class="text-lg max-w-prose mx-auto">
                <h1>
                    <span
                        class="mt-2 block text-3xl text-center leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl">{{ $post->name }}</span>
                </h1>
                <p class="mt-8 text-xl text-gray-500 leading-8">{{ $post->description }}</p>
            </div>
            <div class="mt-6 prose prose-indigo text-gray-500 mx-auto">
                @component('components.contents-view', ['content' => $post->content])@endcomponent
            </div>
        </div>
    </div>
@endsection
