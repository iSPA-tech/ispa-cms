@if (isset($list))
    <ul id="post-{{ $type ?? 'default-slider' }}">
        @foreach($list as $item)
            @if (isset($item->image))
                <li class="relative">
                    <a href="{{ route('article.'.lang()->path, ['slug' => $item->slug, 'cate' => $item->categories[0]->slug]) }}">
                        <span class="absolute inset-0"></span>
                    </a>
                    <div class="aspect-w-5 aspect-h-2">
                        @if (! empty($item->image))
                            <img loading="lazy" src="{{ url($item->image->cover_xl) }}" alt="{{ $item->name }}"
                                 class="mx-auto object-cover">
                        @else
                            <div class="bg-secondary-500 text-white flex items-center h-full w-full"></div>
                        @endif
                    </div>
                    @if (isset($item->name))
                        <div class="absolute bottom-0 w-full px-4 py-2 text-white bg-gray-800/25">
                            <h1 class="text-base md:text-xl">{{ $item->name }}</h1>
                            <h2 class="text-sm">{{ $item->description }}</h2>
                        </div>
                    @endif
                </li>
            @endif
        @endforeach
    </ul>
@endif

@push('script')
    <script>
        $(document).ready(function() {
            $('{{  '#post-'. ($type ?? 'default-slider') }}').lightSlider({
                adaptiveHeight: true,
                item: 1,
                slideMargin: 0,
                loop: true,
            })
        })
    </script>
@endpush
