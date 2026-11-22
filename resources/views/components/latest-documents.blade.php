@if (isset($posts, $category))
    <div class="relative container py-8">
        <div class="title-box relative">
            <h2 class="flex-1 text-lg font-semibold uppercase">{{ $category->name }}</h2>
            <span class="text-sm">@lang('web.read-more')</span>
            <a href="{{ route('category.' . lang('path'), ['slug' => $category->slug]) }}" title="{{ $category->name }}"
               class="absolute inset-0 z-20">
                <span class="sr-only">{{ $category->name }}</span>
            </a>
        </div>

        <div class="relative">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 md:gap-6">
                @foreach($posts as $key => $item)
                    @if (!empty($item) && strlen($item->slug) > 0)
                        <div class="group">
                            <div class="relative shrink-0">
                                <a href="{{ route('article.'.lang()->path, ['slug' => $item->slug, 'cate' => $item->category->slug]) }}"
                                   title="{{ $item->name }}">
                                    @if (isset($item->image))
                                        <x-lazy-image :image="$item->image" class="w-full border rounded-sm"
                                                      alt="{{ $item->name }}"></x-lazy-image>
                                    @endif
                                    <span
                                        class="absolute z-10 bottom-0 right-0 bg-main-700/75 text-white px-2 py-0.5 text-sm">@lang('web.read-more')</span>
                                    <span class="sr-only">{{ $item->name }}</span>
                                </a>
                            </div>
                            <div class="pt-1.5">
                                <a href="{{ route('article.'.lang()->path, ['slug' => $item->slug, 'cate' => $item->category->slug]) }}"
                                   title="{{ $item->name }}"
                                   class="text-gray-700 font-medium hover:text-main-700 group-hover:underline line-clamp-3">{{ $item->name }}</a>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif
