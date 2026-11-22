<div x-cloak{!! !$noJs ? ' x-show="'.$xShow.'"' : '' !!}
     @if (isset($xId))
         id="{{ $xId }}"
     @endif
     class="border-l-4 p-4 {{
      $type === 'warning' ? 'border-yellow-400 bg-yellow-50' :
      ($type === 'success' ? 'border-green-400 bg-green-50' :
      ($type === 'danger' ? 'border-red-400 bg-red-50' :
      ($type === 'info' ? 'border-cyan-400 bg-cyan-50' :
      ($type === 'default' ? 'border-gray-400 bg-gray-50' :
      ($type === 'primary' ? 'border-blue-400 bg-blue-50' : ''
      )))))
      }}"{!! !$noJs ? ' x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95"' : '' !!}>
    <div class="flex">
        @if ($icon)
            <div class="shrink-0 {{
      $type === 'warning' ? 'text-yellow-400' :
      ($type === 'success' ? 'text-green-400' :
      ($type === 'danger' ? 'text-red-400' :
      ($type === 'info' ? 'text-cyan-400' :
      ($type === 'default' ? 'text-gray-400' :
      ($type === 'primary' ? 'text-blue-400' : ''
      )))))
    }}">
                <i class="{{ $icon }}"></i>
            </div>
        @endif
        <div class="ml-3">
            @if ($title)
                <h3 class="text-sm font-medium {{
        $type === 'warning' ? 'text-yellow-800' :
        ($type === 'success' ? 'text-green-800' :
        ($type === 'danger' ? 'text-red-800' :
        ($type === 'info' ? 'text-cyan-800' :
        ($type === 'default' ? 'text-gray-800' :
        ($type === 'primary' ? 'text-blue-800' : ''
        )))))
      }}">{{ $title }}</h3>
            @endif
            <div class="{{ $title ? 'mt-2' : '' }} text-sm {{
        $type === 'warning' ? 'text-yellow-700' :
        ($type === 'success' ? 'text-green-700' :
        ($type === 'danger' ? 'text-red-700' :
        ($type === 'info' ? 'text-cyan-700' :
        ($type === 'default' ? 'text-gray-700' :
        ($type === 'primary' ? 'text-blue-700' : ''
        )))))
      }}">
                <p class="message"{!! !$noJs ? ' x-html="message"' : '' !!}>{!! $message ?? '' !!}</p>
            </div>
        </div>
    </div>
</div>
