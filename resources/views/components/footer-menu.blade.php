@if (isset($menu, $menu->items))
    @foreach($menu->items as $key => $item)
        <div class="flex-1 bg-gray-800 text-gray-200 px-0 md:px-4 py-4 min-h-full">
            <h3 class="text-base font-semibold tracking-wider uppercase">{{ $item->name }}</h3>
            <ul role="list" class="mt-4 space-y-2">
                @foreach($item->items as $subItem)
                    <li class="flex items-center">
                        <a href="{{ $subItem->url }}" class="text-gray-300 hover:text-gray-200"{{ str($subItem->url)->contains('//') ? ' target="_blank"' : '' }}>{{ $subItem->name }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
@endif
