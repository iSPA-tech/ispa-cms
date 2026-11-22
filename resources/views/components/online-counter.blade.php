<div {{ $attributes->class([
    'text-gray-200 inline-flex',
    'text-sm' => !isset($class),
])}}>
    @if (isset($online) && $online > 0)
        <span class="flex items-center space-x-2 pr-4" title="Current Online">
      <span><i class="fa fa-user"></i></span>
      <span>{{ number_format($total_online) }}</span>
    </span>
    @endif
    @if (isset($visit) && $visit > 0)
        <span class="flex items-center space-x-2 pr-4" title="Total visitors">
      <span><i class="fa fa-users"></i></span>
      <span>{{ number_format($total_user) }}</span>
    </span>
    @endif
    @if (isset($views) && $views > 0)
        <span class="flex items-center space-x-2 pr-4" title="Total Views">
      <span><i class="fa fa-eye"></i></span>
      <span>{{ number_format($total_view) }}</span>
    </span>
    @endif
</div>
