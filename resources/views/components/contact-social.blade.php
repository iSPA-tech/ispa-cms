@if (isset($type) && !is_null(iSeo($type)) && strlen(iSeo($type)) > 0)
    <a href="{{ iSeo($type) }}" title="@lang('web.social-channel') {{ $type }}" target="_blank" rel="noopener"
       class="flex items-center">
        @if (isset($icon))
            <span{{ (isset($showText) && $showText === true) ? ' class="pr-2"' : '' }}>
                <i class="{{ $icon }}"></i>
            </span>
        @endif
        @if (isset($showText) && $showText === true)
            <span>{{ iSeo($type) }}</span>
        @endif
    </a>
@endif
