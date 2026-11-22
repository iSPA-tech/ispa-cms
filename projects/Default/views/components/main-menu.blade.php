{{-- main_menu group of the menus table ($menu: top-level items from MenuItemRepository, children in ->items).
     Wide screens: inline list, an item with children opens a <details> dropdown. Narrow screens: a Menu button opens a
     native popover with the same items, children indented. No JavaScript. Nothing renders when the group is empty. --}}
@php($items = collect($menu ?? [])->filter())
@if ($items->isNotEmpty())
    <nav aria-label="{{ __('default::theme.menu.label') }}">
        <ul class="hidden items-center gap-x-7 text-sm font-semibold text-slate-700 lg:flex" role="list">
            @foreach ($items as $item)
                <li class="relative">
                    @if ($item->have_children)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center gap-1 hover:text-cyan-700 [&::-webkit-details-marker]:hidden">
                                {{ $item->name }}
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="size-4 text-slate-400 group-open:rotate-180"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
                            </summary>
                            <ul class="absolute left-0 z-50 mt-3 w-56 rounded-xl bg-white p-2 font-normal shadow-lg ring-1 ring-slate-900/5" role="list">
                                @foreach ($item->items as $child)
                                    <li><a href="{{ menu_navigate($child) }}" target="{{ $child->custom['action'] ?? '_self' }}" title="{{ $child->title ?? $child->name }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">{{ $child->name }}</a></li>
                                @endforeach
                            </ul>
                        </details>
                    @else
                        <a href="{{ menu_navigate($item) }}" target="{{ $item->custom['action'] ?? '_self' }}" title="{{ $item->title ?? $item->name }}" class="hover:text-cyan-700">{{ $item->name }}</a>
                    @endif
                </li>
            @endforeach
        </ul>

        <button type="button" popovertarget="default-menu" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 lg:hidden">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            {{ __('default::theme.menu.open') }}
        </button>
        <div id="default-menu" popover class="m-0 h-dvh max-h-none w-full max-w-none overflow-y-auto bg-white p-6 lg:hidden">
            <div class="flex justify-end">
                <button type="button" popovertarget="default-menu" popovertargetaction="hide" class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{{ __('default::theme.menu.close') }}</button>
            </div>
            <ul class="mt-6 space-y-1 text-base font-semibold text-slate-800" role="list">
                @foreach ($items as $item)
                    <li>
                        <a href="{{ menu_navigate($item) }}" target="{{ $item->custom['action'] ?? '_self' }}" class="block rounded-lg px-3 py-2 hover:bg-slate-50">{{ $item->name }}</a>
                        @if ($item->have_children)
                            <ul class="ml-4 border-l border-slate-200 pl-3 font-normal" role="list">
                                @foreach ($item->items as $child)
                                    <li><a href="{{ menu_navigate($child) }}" target="{{ $child->custom['action'] ?? '_self' }}" class="block rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-50">{{ $child->name }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </nav>
@endif
