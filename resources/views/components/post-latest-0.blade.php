@if (isset($list))
    <div class="relative px-4 md:px-0">
        <div class="grid grid-cols-1 md:grid-cols-2 md:gap-8">
            @foreach($list as $key => $item)
                @if ($key === 0)
                    <div>
                        @endif

                        @if ($key === 2 && $list->count() > 2)
                    </div>
                    <div class="md:border-l md:pl-6">
                        @endif

                        <div class="sm:flex relative group mb-4">
                            <div class="mb-4 shrink-0 sm:mb-0 sm:mr-4">
                                <a href="{{ route('article.'.lang()->path, ['slug' => $item->slug, 'cate' => $item->category->slug]) }}">
                                    @if (isset($item->image))
                                        <img loading="lazy" src="{{ $item->image->cover_sm }}"
                                             class="{{ $key < 2 ? 'w-full md:w-80' : 'w-20' }} border rounded-sm"
                                             alt="">
                                    @endif
                                </a>
                            </div>
                            <div>
                                <a href="{{ route('article.'.lang()->path, ['slug' => $item->slug, 'cate' => $item->category->slug]) }}">
                                    <h4 class="mb-2 text-lg text-main-500 hover:text-main-600 font-bold group-hover:underline line-clamp-2">{{ $item->name }}</h4>
                                </a>
                                @if ($key < 2)
                                    <a href="{{ route('category.' . lang()->path, ['slug' => $item->category->slug]) }}">
                                        <h4 class="text-base text-secondary-500 hover:text-secondary-600">{{ $item->category->name }}</h4>
                                    </a>
                                    <p class="mt-1.5 line-clamp-5">{{ $item->description }}</p>
                                @endif
                            </div>
                        </div>

                        @if ($key === ($list->count() - 1))
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
