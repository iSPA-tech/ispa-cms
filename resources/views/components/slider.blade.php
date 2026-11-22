@if (isset($banners) && $banners->count() > 0)
    @php
        $list = '';
    @endphp
    @if ($fullscreen === false)
        <div class="max-w-7xl mx-auto">
            @endif
            <ul id="{{ $type ?? 'default-slider' }}">
                @foreach($banners as $item)
                    <li class="relative">
                        @if (isset($item->custom['title']))
                            <div
                                class="caption absolute z-10 top-full md:top-1/2 left-[16px] md:left-1/2 md:-ml-[25%] -mt-[76px] text-white">
                                <h1 class="text-lg md:text-3xl font-extralight">{{ $item->custom['title'] }}</h1>
                                <h2 class="font-bold text-3xl md:text-6xl">{{ $item->name }}</h2>
                            </div>
                        @endif

                        @if ($item->image)
                            {{-- The first slide is above the fold and is the LCP
                                 on every template that opens with this slider,
                                 so it loads eagerly; the rest stay lazy. --}}
                            <x-lazy-image :image="$item->image" type="scale"
                                          class="w-full object-contain"
                                          min-size="md"
                                          :eager="$loop->first"
                                          sizes="{{ $fullscreen === false ? '(min-width: 1280px) 1280px, 100vw' : '100vw' }}"
                                          alt="{{ $item->name }}" />
                        @else
                            <div class="bg-orange-600 text-white flex items-center h-full w-full"></div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($fullscreen === false)
        </div>
    @endif
@endif

@push('script')
    <script>
        $(document).ready(function() {
            $('#{{ $type ?? 'default-slider' }}').lightSlider({
                adaptiveHeight: true,
                item: 1,
                slideMargin: 0,
                loop: true,
            })
        })
    </script>
@endpush
