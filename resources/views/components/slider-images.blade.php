@if (isset($images) && $images->count() > 0)
    @php($sliderId = $type ?? 'default-slider')
    <style>
        .slider-images-component .lSSlideWrapper {
            position: relative;
        }

        .slider-images-component .lSAction > span {
            position: absolute;
            top: 50%;
            z-index: 30;
            display: flex;
            width: 2.75rem;
            height: 2.75rem;
            align-items: center;
            justify-content: center;
            margin-top: -1.375rem;
            border-radius: 9999px;
            background: rgba(17, 24, 39, .78);
            color: #fff;
            cursor: pointer;
            font-size: 2.25rem;
            line-height: 1;
            opacity: .9;
            transition: opacity .2s ease, background-color .2s ease;
        }

        .slider-images-component .lSAction > span:hover,
        .slider-images-component .lSAction > span:focus {
            background: rgba(17, 24, 39, .95);
            opacity: 1;
            outline: 2px solid #fff;
            outline-offset: 2px;
        }

        .slider-images-component .lSAction > .lSPrev {
            left: .75rem;
        }

        .slider-images-component .lSAction > .lSNext {
            right: .75rem;
        }
    </style>

    <div class="slider-images-component relative">
        <ul id="{{ $sliderId }}">
            @foreach($images as $item)
                <li class="relative">
                    @if (isset($item->custom['title']))
                        <div
                            class="caption absolute z-10 top-full md:top-1/2 left-[16px] md:left-1/2 md:-ml-[25%] -mt-[76px] text-white">
                            <h1 class="text-lg md:text-3xl font-extralight">{{ $item->custom['title'] }}</h1>
                            <h2 class="font-bold text-3xl md:text-6xl">{{ $item->name }}</h2>
                        </div>
                    @endif

                    @if ($item->url)
                        <x-lazy-image :image="$item" class="w-full object-contain" alt="{{ $item->name }}" />
                    @else
                        <div class="bg-orange-600 text-white flex items-center h-full w-full"></div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

@push('script')
    <script>
        $(document).ready(function() {
            var $slider = $('#{{ $sliderId ?? ($type ?? 'default-slider') }}')

            if (!$slider.length) {
                return
            }

            $slider.lightSlider({
                adaptiveHeight: true,
                item: 1,
                slideMargin: 0,
                loop: true,
                auto: @json((bool)($auto_play ?? false)),
                controls: true,
                prevHtml: '<span class="sr-only">Anh truoc</span><span aria-hidden="true">&#8249;</span>',
                nextHtml: '<span class="sr-only">Anh sau</span><span aria-hidden="true">&#8250;</span>',
                onSliderLoad: function() {
                    var $actions = $slider.closest('.lSSlideOuter').find('.lSAction > span')

                    $actions.attr('role', 'button').attr('tabindex', '0')
                    $actions.filter('.lSPrev').attr('aria-label', 'Anh truoc')
                    $actions.filter('.lSNext').attr('aria-label', 'Anh sau')
                    $actions.on('keydown', function(event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            $(this).trigger('click')
                        }
                    })
                },
            })
        })
    </script>
@endpush
