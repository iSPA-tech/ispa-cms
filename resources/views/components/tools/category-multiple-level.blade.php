@php
    $maxDepth = isset($maxDepth)
        ? max(0, (int) $maxDepth)
        : max(0, (int) ($item->menu_expand_child_level ?? 2));
    $currentDepth = isset($currentDepth) ? max(1, (int) $currentDepth) : 1;
    $visited = $visited ?? [(int) ($item->id ?? 0)];
@endphp

@if ($item->has_children && ($maxDepth === 0 || $currentDepth <= $maxDepth))
    @foreach($item->public_children as $lv1Key => $cat1)
        @continue(in_array((int) $cat1->id, $visited, true))
        @php
            if (isset($prefix) && !empty($prefix)) {
                $url = url(str($prefix)->rtrim('/') . '/' . str($cat1->slug)->ltrim('/'));
            } else {
                $url = $cat1->route ?? route('category.' . lang('path', 'vi'), $cat1->slug);
            }
        @endphp
        <a
            href="{{ $url }}"
            class="text-base block font-medium text-gray-500 hover:text-gray-800 dark:text-white leading-8"
            title="{{ $cat1->name }}"
        >{{ $cat1->name }}</a>
        @if (($maxDepth === 0 || $currentDepth < $maxDepth) && $cat1->has_children)
            <div class="lv2-sub-flyout">
                @include('components.tools.category-multiple-level', [
                    'item' => $cat1,
                    'prefix' => $prefix ?? '',
                    'maxDepth' => $maxDepth,
                    'currentDepth' => $currentDepth + 1,
                    'visited' => [...$visited, (int) $cat1->id],
                ])
            </div>
        @endif
    @endforeach
@endif
