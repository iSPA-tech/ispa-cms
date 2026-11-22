{{-- footer_menu group of the menus table ($menu: the Menu with its top-level items, children in ->items). --}}
@if ($menu && $menu->items->isNotEmpty())
    <nav aria-label="{{ __('default::theme.footer.label') }}">
        <ul class="grid grid-cols-2 gap-x-10 gap-y-3 text-sm text-slate-600 sm:grid-cols-3" role="list">
            @foreach ($menu->items as $item)
                <li>
                    <a href="{{ menu_navigate($item) }}" target="{{ $item->custom['action'] ?? '_self' }}" class="hover:text-slate-900">{{ $item->name }}</a>
                    @if ($item->have_children)
                        <ul class="mt-2 space-y-2" role="list">
                            @foreach ($item->items as $child)
                                <li><a href="{{ menu_navigate($child) }}" target="{{ $child->custom['action'] ?? '_self' }}" class="text-slate-500 hover:text-slate-900">{{ $child->name }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif
