@if (isset($item))
    <div class="flyout group {{$class ?? ''}}">
        <a href="{{ menu_navigate($item, $prefix ?? null) }}"
           class="flyout-btn" title="{{ $item->name }}"
           target="{{ $item->custom['action'] ?? '_self' }}">{{ $item->name }}</a>

        @if (isset($item->custom['isExpandChild']) && $item->custom['isExpandChild'] && $item->target->has_children)
            <div id="flyout-menu-{{ $key }}" class="flyout-group hidden group-hover:block">
                <div class="px-4 py-1">
                    @component('components.tools.category-multiple-level', [
	                    'item' => $item->target,
	                    'prefix' => $item->custom['prefix'] ?? ''
                    ])@endcomponent
                </div>
            </div>
        @endif
    </div>
@endif
