@extends(config('ispa-cms.folder') . 'layout.index')

@section('content')
    <div x-data="news" class="bg-gray-50 py-8 px-4">
        <h1 class="text-3xl text-main-500 pb-4">
            <span class="font-bold">@lang('web.t.news')</span>
        </h1>

        @if (isset($posts))
            <div class="relative pt-3 flex flex-col gap-4 md:gap-6">
                @foreach($posts as $key => $item)
                    <div class="group{{ $key > 0 ? ' flex items-center' : '' }} relative">
                        <div class="relative shrink-0">
                            @if (isset($item->image, $item->image->cover))
                                <img loading="lazy" src="{{ $item->image->cover }}"
                                     class="{{ $key > 0 ? 'w-20 mr-3' : 'w-full' }} border rounded-lg"
                                     alt="{{ $item->name }}">
                            @endif
                        </div>
                        <h3 class="pt-1.5 text-main-500 font-medium hover:text-main-600 group-hover:underline line-clamp-3">
                            {{ $item->name }}
                        </h3>
                        <a href="{{ route('article.'.lang('path'), ['slug' => $item->slug, 'cate' => $item->category->slug]) }}"
                           title="{{ $item->title }}"
                        >
                            <span class="absolute inset-0"></span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif

        <div>
            {{ $posts->links() }}
        </div>
    </div>
@endsection

@push('script')
    <script>
        function news() {
            return {
                //
            };
        }
    </script>
@endpush
