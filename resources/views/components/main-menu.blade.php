@if (isset($menu, $menu->items) && $menu->items)
  <nav class="hidden md:flex space-x-10 pr-6">
    @foreach($menu->items as $item)
      @if ($item->parent_id === null)
        <a href="{{ menu_navigate($item) }}" class="text-base font-medium text-gray-500 hover:text-gray-900" title="{{ $item->title ?? $item->name }}" target="{{ $item->custom['action'] ?? '_self' }}">{{ $item->name }}</a>
      @endif
    @endforeach
  </nav>
@endif
