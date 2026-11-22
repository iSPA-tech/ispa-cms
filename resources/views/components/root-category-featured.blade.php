@if (isset($list) && $list->count() > 0)
    @foreach($list as $key => $category)
        <div class="relative">
            <h1 class="uppercase text-center text-lg md:text-3xl font-semibold">{{ $category->name }}</h1>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 md:gap-8 pt-4 md:pt-8">
                @foreach($category->children as $child)
                    <div class="relative border rounded-sm overflow-hidden">
                        <a href="{{ $child->route }}">
                            <span class="absolute inset-0"></span>
                        </a>
                        <img loading="lazy" src="{{ $child->image->thumbnail_lg }}" alt="">
                        <p class="text-center line-clamp-1 py-2 px-3 font-semibold">{{ $child->name }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endif
